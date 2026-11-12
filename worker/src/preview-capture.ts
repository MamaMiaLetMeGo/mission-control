import { createHash } from 'node:crypto';
import { mkdir, realpath, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';
import type { Browser } from 'playwright';
import { statusPreview, stopPreview, validatePreviewRequest, type PreviewHandle, type PreviewRequest, type Screenshot } from './preview.js';
import { safeEnvironment } from './process.js';

const browserExecutable = chromium.executablePath();
const viewports = [
  { name: 'desktop' as const, width: 1440, height: 1000, mobile: false },
  { name: 'mobile' as const, width: 390, height: 844, mobile: true },
];

export async function capturePreview(handle: PreviewHandle, rawRequest: PreviewRequest, signal?: AbortSignal): Promise<Screenshot[]> {
  const request = validatePreviewRequest(rawRequest);
  signal?.throwIfAborted();
  const owned = await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot, id: handle.id, revision: request.revision });
  if (!owned || owned.url !== handle.url || owned.revision !== handle.revision) {
    throw new Error('This preview is no longer running at the requested revision.');
  }
  const origin = new URL(handle.url).origin;
  await mkdir(request.artifactPath, { recursive: true, mode: 0o700 });
  const artifactPath = await realpath(request.artifactPath);
  let browser: Browser | undefined;
  const closeBrowser = () => { void browser?.close().catch(() => {}); };
  signal?.addEventListener('abort', closeBrowser, { once: true });
  try {
    const browserHome = path.join(request.previewRoot, `task-${request.taskId}`, handle.id, 'runtime', 'browser-home');
    await mkdir(browserHome, { recursive: true, mode: 0o700 });
    browser = await chromium.launch({
      headless: true, executablePath: browserExecutable, timeout: 20000,
      env: safeEnvironment(browserHome), chromiumSandbox: true,
    });
    signal?.throwIfAborted();
    const screenshots: Screenshot[] = [];
    for (const viewport of viewports) {
      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height }, deviceScaleFactor: 1,
        isMobile: viewport.mobile, hasTouch: viewport.mobile, serviceWorkers: 'block', acceptDownloads: false,
        locale: 'en-US', timezoneId: 'UTC', colorScheme: 'light', reducedMotion: 'reduce',
      });
      await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.origin === origin || ['data:', 'blob:'].includes(url.protocol)) {
          await route.continue();
        } else {
          await route.abort('blockedbyclient');
        }
      });
      for (const [index, routePath] of request.config.paths.entries()) {
        signal?.throwIfAborted();
        const page = await context.newPage();
        page.setDefaultTimeout(20000);
        const failures: string[] = [];
        page.on('pageerror', (error) => failures.push(error.message));
        page.on('response', (response) => {
          if (new URL(response.url()).origin === origin && response.status() >= 400) failures.push(`HTTP ${response.status()}: ${new URL(response.url()).pathname}`);
        });
        page.on('download', () => failures.push('The preview attempted a file download.'));
        const target = new URL(routePath, origin).href;
        const response = await page.goto(target, { waitUntil: 'networkidle', timeout: 20000 });
        if (!response || response.status() >= 300 || response.request().redirectedFrom() || page.url() !== target) {
          throw new Error(`Screenshot path ${routePath} redirected or did not return a successful page.`);
        }
        await page.waitForFunction(() => document.fonts.status === 'loaded' && Array.from(document.images).every((image) => image.complete), null, { timeout: 20000 });
        await page.evaluate(async () => {
          await document.fonts.ready;
          for (const image of Array.from(document.images)) {
            if (image.currentSrc && image.naturalWidth === 0) throw new Error('An image failed to load.');
          }
        });
        signal?.throwIfAborted();
        if (failures.length) {
          throw new Error(`Preview browser errors at ${routePath}: ${failures.slice(0, 5).join('; ')}`);
        }
        const dimensions = await page.evaluate(() => ({
          width: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
          height: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
        }));
        if (dimensions.height > 20000 || dimensions.width > 4000) {
          throw new Error(`Screenshot page ${routePath} exceeds the 20000px height or 4000px width limit.`);
        }
        const bytes = await page.screenshot({ fullPage: true, animations: 'disabled', type: 'png', timeout: 20000 });
        if (bytes.length > 20 * 1024 * 1024 || bytes.readUInt32BE(20) > 20000 || bytes.readUInt32BE(16) > 4000) {
          throw new Error(`Screenshot page ${routePath} exceeds the 20 MB or image dimension limit.`);
        }
        const file = `${request.phase}-${viewport.name}-${index}.png`;
        await writeFile(path.join(artifactPath, file), bytes, { flag: 'wx', mode: 0o600 });
        screenshots.push({ file, phase: request.phase, viewport: viewport.name, path: routePath,
          width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20), revision: request.revision,
          sha256: createHash('sha256').update(bytes).digest('hex'),
        });
        await page.close();
      }
      await context.close();
    }
    return screenshots;
  } catch (error) {
    await browser?.close();
    browser = undefined;
    await stopPreview({ taskId: request.taskId, previewRoot: request.previewRoot, id: handle.id });
    throw error;
  } finally {
    signal?.removeEventListener('abort', closeBrowser);
    await browser?.close();
  }
}
