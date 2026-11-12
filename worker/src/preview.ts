import { randomBytes, randomUUID } from 'node:crypto';
import { spawn } from 'node:child_process';
import { access, lstat, mkdir, readFile, readdir, realpath, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { safeEnvironment } from './process.js';
import { isWithin, redact } from './validation.js';

export interface PreviewConfig {
  enabled: boolean;
  driver: 'command' | 'laravel';
  directory: string;
  command: string[];
  paths: string[];
  ttlSeconds: number;
}

export interface PreviewRequest {
  taskId: number;
  revision: string;
  worktreePath: string;
  repositoryPath: string;
  artifactPath: string;
  previewRoot: string;
  phase: 'before' | 'after';
  config: PreviewConfig;
  database?: { driver: 'sqlite' | 'pgsql'; host: string; port: number; username: string };
}

export interface PreviewHandle {
  id: string;
  url: string;
  revision: string;
  expiresAt: string;
}

export interface Screenshot {
  file: string;
  phase: 'before' | 'after';
  viewport: 'desktop' | 'mobile';
  path: string;
  width: number;
  height: number;
  revision: string;
  sha256: string;
}

export interface PreviewSelector {
  taskId: number;
  previewRoot: string;
  id?: string;
  revision?: string;
}

export interface PreviewManifest {
  version: 1;
  taskId: number;
  handle: PreviewHandle;
  token: string;
  controlUrl: string;
  state: 'starting' | 'ready' | 'stopping' | 'stopped' | 'failed';
  cleanupComplete?: boolean;
  error?: string;
}

export const previewIdPattern = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;

export function validatePreviewConfig(value: PreviewConfig): PreviewConfig {
  if (!value || typeof value !== 'object' || typeof value.enabled !== 'boolean' || !['command', 'laravel'].includes(value.driver)) {
    throw new Error('Preview configuration requires enabled and a supported driver.');
  }
  if (typeof value.directory !== 'string' || path.isAbsolute(value.directory) || value.directory.includes('\\')
    || value.directory.includes('\0') || value.directory.split('/').some((part) => part === '..')) {
    throw new Error('Preview directory must stay inside the revision snapshot.');
  }
  if (!Array.isArray(value.command) || !value.command.length || value.command.length > 50
    || value.command.some((part) => typeof part !== 'string' || part.includes('\0'))
    || !value.command[0]?.trim() || value.command.join('').split('{port}').length !== 2) {
    throw new Error('Preview command must be an argument array containing exactly one {port} placeholder.');
  }
  if (value.command.some((part) => /(?:^|=)(?:0\.0\.0\.0|\[?::\]?)$/.test(part))) {
    throw new Error('Preview commands must bind to 127.0.0.1, not a public interface.');
  }
  if (!Array.isArray(value.paths) || value.paths.length < 1 || value.paths.length > 5) {
    throw new Error('Choose between one and five local preview paths.');
  }
  for (const route of value.paths) {
    validatePreviewPath(route);
  }
  if (!Number.isInteger(value.ttlSeconds) || value.ttlSeconds < 1 || value.ttlSeconds > 3600) {
    throw new Error('Preview lifetime must be between 1 and 3600 seconds.');
  }
  return value;
}

export function validatePreviewPath(route: unknown): asserts route is string {
  if (typeof route !== 'string' || !route.startsWith('/') || route.startsWith('//') || route.includes('\\')
    || /[\u0000-\u0020\u007f#]/.test(route) || route.length > 2000) {
    throw new Error('Screenshot paths must be local paths beginning with one slash.');
  }
  let decoded: string;
  try {
    decoded = decodeURIComponent(route.split('?')[0]!);
  } catch {
    throw new Error('Screenshot paths must use valid URL encoding.');
  }
  if (decoded.startsWith('//') || decoded.includes('\\') || decoded.split('/').includes('..')) {
    throw new Error('Screenshot paths cannot escape the preview origin.');
  }
  if (new URL(route, 'http://localhost:1234').origin !== 'http://localhost:1234') {
    throw new Error('Screenshot paths cannot escape the preview origin.');
  }
}

export function validatePreviewRequest(request: PreviewRequest): PreviewRequest {
  validateSelector(request);
  if (!/^[a-f0-9]{40,64}$/.test(request.revision || '') || !['before', 'after'].includes(request.phase)) {
    throw new Error('Preview requests require an exact revision and before/after phase.');
  }
  for (const field of ['worktreePath', 'repositoryPath', 'artifactPath'] as const) {
    if (typeof request[field] !== 'string' || !path.isAbsolute(request[field]) || request[field].includes('\0')) {
      throw new Error(`${field} must be an absolute local path.`);
    }
  }
  validatePreviewConfig(request.config);
  if (!request.config.enabled) {
    throw new Error('Previews are disabled for this run.');
  }
  return request;
}

function validateSelector(selector: PreviewSelector): void {
  if (!selector || !Number.isSafeInteger(selector.taskId) || selector.taskId < 1
    || typeof selector.previewRoot !== 'string' || !path.isAbsolute(selector.previewRoot) || selector.previewRoot.includes('\0')
    || (selector.id !== undefined && !previewIdPattern.test(selector.id))
    || (selector.revision !== undefined && !/^[a-f0-9]{40,64}$/.test(selector.revision))) {
    throw new Error('Invalid preview task, session, or storage location.');
  }
}

function isControlUrl(value: string): boolean {
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'http:' && parsed.hostname === '127.0.0.1' && parsed.port !== ''
      && parsed.pathname === '/' && !parsed.username && !parsed.password && !parsed.search && !parsed.hash;
  } catch {
    return false;
  }
}

export async function readPreviewManifests(selector: PreviewSelector): Promise<PreviewManifest[]> {
  validateSelector(selector);
  const taskPath = path.join(selector.previewRoot, `task-${selector.taskId}`);
  try {
    if ((await lstat(taskPath)).isSymbolicLink()) {
      throw new Error('Preview storage cannot be a symbolic link.');
    }
    const entries = await readdir(taskPath, { withFileTypes: true });
    const manifests: PreviewManifest[] = [];
    for (const entry of entries) {
      if (!entry.isDirectory() || !previewIdPattern.test(entry.name) || (selector.id && entry.name !== selector.id)) {
        continue;
      }
      const manifestPath = path.join(taskPath, entry.name, 'manifest.json');
      try {
        if (!(await lstat(manifestPath)).isFile()) {
          continue;
        }
        const manifest = JSON.parse(await readFile(manifestPath, 'utf8')) as PreviewManifest;
        if (manifest.version !== 1 || manifest.taskId !== selector.taskId || manifest.handle.id !== entry.name
          || !/^[a-f0-9]{64}$/.test(manifest.token) || !isControlUrl(manifest.controlUrl)
          || (selector.revision && manifest.handle.revision !== selector.revision)) {
          continue;
        }
        manifests.push(manifest);
      } catch {
        // Incomplete manifests are never a reason to send a control request.
      }
    }
    return manifests.sort((a, b) => b.handle.expiresAt.localeCompare(a.handle.expiresAt));
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return [];
    }
    throw error;
  }
}

export async function controlPreview(manifest: PreviewManifest, action: 'status' | 'stop'): Promise<{ state: PreviewManifest['state']; preview: PreviewHandle; error?: string } | null> {
  try {
    const response = await fetch(new URL(action, manifest.controlUrl), {
      method: action === 'stop' ? 'POST' : 'GET',
      headers: { Authorization: `Bearer ${manifest.token}` },
      redirect: 'error',
      signal: AbortSignal.timeout(1500),
    });
    if (!response.ok) {
      return null;
    }
    const body = await response.json() as { taskId: number; state: PreviewManifest['state']; preview: PreviewHandle; error?: string };
    if (body.taskId !== manifest.taskId || body.preview?.id !== manifest.handle.id || body.preview.revision !== manifest.handle.revision) {
      return null;
    }
    return body;
  } catch {
    return null;
  }
}

export async function startPreview(rawRequest: PreviewRequest, signal?: AbortSignal): Promise<PreviewHandle> {
  signal?.throwIfAborted();
  const request = validatePreviewRequest(rawRequest);
  await access(request.worktreePath);
  await mkdir(request.previewRoot, { recursive: true, mode: 0o700 });
  const previewRoot = await realpath(request.previewRoot);
  const repositoryPath = await realpath(request.repositoryPath);
  const worktreePath = await realpath(request.worktreePath);
  if (isWithin(previewRoot, repositoryPath) || isWithin(previewRoot, worktreePath)) {
    throw new Error('Preview snapshots must be stored outside the source and task worktrees.');
  }
  const id = randomUUID();
  const taskPath = path.join(previewRoot, `task-${request.taskId}`);
  await mkdir(taskPath, { recursive: true, mode: 0o700 });
  if ((await lstat(taskPath)).isSymbolicLink()) {
    throw new Error('Preview storage cannot be a symbolic link.');
  }
  const sessionPath = path.join(taskPath, id);
  await mkdir(sessionPath, { mode: 0o700 });
  const runtimePath = path.join(sessionPath, 'runtime');
  await mkdir(runtimePath, { mode: 0o700 });
  const requestPath = path.join(sessionPath, 'request.json');
  const controlToken = randomBytes(32).toString('hex');
  await writeFile(requestPath, JSON.stringify({ ...request, previewRoot, repositoryPath, worktreePath, controlToken }), { mode: 0o600, flag: 'wx' });
  const supervisor = spawn(process.execPath, [fileURLToPath(new URL('./preview-supervisor.js', import.meta.url)), requestPath], {
    cwd: sessionPath,
    env: safeEnvironment(runtimePath),
    detached: true,
    stdio: 'ignore',
  });
  let spawnError: Error | undefined;
  let supervisorExited = false;
  supervisor.once('error', (error) => { spawnError = error; });
  supervisor.once('exit', () => { supervisorExited = true; });
  supervisor.unref();
  const deadline = Date.now() + 175000;
  try {
    while (Date.now() < deadline) {
      signal?.throwIfAborted();
      if (spawnError) {
        throw spawnError;
      }
      const [manifest] = await readPreviewManifests({ taskId: request.taskId, previewRoot, id });
      if (manifest?.state === 'failed' || manifest?.state === 'stopped') {
        throw new Error(manifest.error || 'The preview stopped before it became ready.');
      }
      if (supervisorExited) {
        const detail = await readFile(path.join(sessionPath, 'startup-error.txt'), 'utf8').catch(() => 'The preview supervisor exited during startup.');
        throw new Error(detail);
      }
      if (manifest) {
        const live = await controlPreview(manifest, 'status');
        if (live?.state === 'ready') {
          return live.preview;
        }
        if (live?.error) {
          throw new Error(live.error);
        }
      }
      await delay(150);
    }
    throw new Error('The preview did not become ready within 175 seconds.');
  } catch (error) {
    await writeFile(path.join(sessionPath, 'stop.request'), controlToken, { mode: 0o600 });
    await stopPreview({ taskId: request.taskId, previewRoot, id });
    throw new Error(redact(error instanceof Error ? error.message : String(error)));
  }
}

export async function statusPreview(selector: PreviewSelector): Promise<PreviewHandle | null> {
  for (const manifest of await readPreviewManifests(selector)) {
    if (manifest.state !== 'ready' || Date.parse(manifest.handle.expiresAt) <= Date.now()) {
      continue;
    }
    const live = await controlPreview(manifest, 'status');
    if (live?.state === 'ready' && Date.parse(live.preview.expiresAt) > Date.now()) {
      return live.preview;
    }
  }
  return null;
}

export async function stopPreview(selector: PreviewSelector): Promise<void> {
  const manifests = await readPreviewManifests(selector);
  const pending = new Set<string>();
  for (const manifest of manifests) {
    if (manifest.cleanupComplete === false) {
      throw new Error(manifest.error || 'Preview cleanup did not complete; its private runtime has been retained.');
    }
    if (!['stopped', 'failed'].includes(manifest.state)) {
      pending.add(manifest.handle.id);
      await controlPreview(manifest, 'stop');
    }
  }
  const deadline = Date.now() + 20000;
  while (pending.size > 0 && Date.now() < deadline) {
    for (const manifest of await readPreviewManifests(selector)) {
      if (['stopped', 'failed'].includes(manifest.state)) {
        if (manifest.cleanupComplete === false) {
          throw new Error(manifest.error || 'Preview cleanup did not complete; its private runtime has been retained.');
        }
        pending.delete(manifest.handle.id);
      }
    }
    if (pending.size) await delay(50);
  }
  if (pending.size) {
    throw new Error('Could not confirm preview cleanup within 20 seconds. Its private runtime has been retained.');
  }
}

export { capturePreview } from './preview-capture.js';
