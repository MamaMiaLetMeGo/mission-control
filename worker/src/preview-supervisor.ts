import { timingSafeEqual } from 'node:crypto';
import { spawn, type ChildProcess } from 'node:child_process';
import { watch } from 'node:fs';
import { lstat, mkdir, readFile, readdir, readlink, realpath, rename, rm, writeFile } from 'node:fs/promises';
import http from 'node:http';
import net from 'node:net';
import { createRequire } from 'node:module';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { prepareLaravelPreview } from './laravel-preview.js';
import { command, git, safeEnvironment } from './process.js';
import { validatePreviewRequest, previewIdPattern, type PreviewManifest, type PreviewRequest } from './preview.js';
import { isWithin, redact } from './validation.js';

const require = createRequire(import.meta.url);
const codexEntrypoint = path.join(path.dirname(require.resolve('@openai/codex/package.json')), 'bin/codex.js');

function tokenMatches(candidate: string, token: string): boolean {
  return candidate.length === token.length && timingSafeEqual(Buffer.from(candidate), Buffer.from(token));
}

async function availablePort(): Promise<number> {
  const server = net.createServer();
  await new Promise<void>((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  const port = (server.address() as net.AddressInfo).port;
  await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
  return port;
}

async function validateArchive(repository: string, revision: string, environment: Record<string, string>, signal: AbortSignal): Promise<void> {
  const entries = await git(['ls-tree', '-r', '-z', revision], repository, environment, signal, 8 * 1024 * 1024, false);
  for (const entry of entries.split('\0').filter(Boolean)) {
    const separator = entry.indexOf('\t');
    const metadata = entry.slice(0, separator);
    const filename = entry.slice(separator + 1);
    if (separator < 0 || filename.startsWith('/') || filename.includes('\\') || filename.split('/').some((part) => ['..', '.', '.git'].includes(part))) {
      throw new Error('The revision contains an unsafe archive path.');
    }
    if (metadata.startsWith('160000 ')) {
      throw new Error('Preview snapshots do not support Git submodules yet.');
    }
    if (metadata.startsWith('120000 ')) {
      const target = await git(['show', `${revision}:${filename}`], repository, environment, signal, 8192, false);
      if (path.isAbsolute(target) || target.includes('\0') || !isWithin(path.resolve('/snapshot', path.dirname(filename), target), '/snapshot')) {
        throw new Error('The revision contains a symlink that escapes its snapshot.');
      }
    }
  }
}

async function inspectSnapshot(directory: string, root: string): Promise<void> {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const filename = path.join(directory, entry.name);
    if (/^\.env(?:\.|$)/.test(entry.name)) {
      await rm(filename, { recursive: true, force: true });
    } else if (entry.isSymbolicLink()) {
      const target = path.resolve(directory, await readlink(filename));
      if (!isWithin(target, root)) {
        throw new Error('A snapshot symlink escapes its revision.');
      }
      try {
        if (!isWithin(await realpath(filename), root)) {
          throw new Error('A snapshot symlink resolves outside its revision.');
        }
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
          throw error;
        }
      }
    } else if (entry.isDirectory()) {
      await inspectSnapshot(filename, root);
    } else if (!entry.isFile()) {
      throw new Error('The snapshot contains an unsupported file type.');
    }
  }
}

async function main(): Promise<void> {
  const requestFile = process.argv[2];
  if (!requestFile || !path.isAbsolute(requestFile)) {
    throw new Error('A private preview request file is required.');
  }
  const sessionPath = await realpath(path.dirname(requestFile));
  const id = path.basename(sessionPath);
  const raw = JSON.parse(await readFile(requestFile, 'utf8')) as PreviewRequest & { controlToken: string };
  const request = validatePreviewRequest(raw);
  if (!previewIdPattern.test(id) || !/^[a-f0-9]{64}$/.test(raw.controlToken)
    || sessionPath !== path.join(await realpath(request.previewRoot), `task-${request.taskId}`, id)) {
    throw new Error('The private preview session is invalid.');
  }
  const runtimePath = path.join(sessionPath, 'runtime');
  const snapshotPath = path.join(sessionPath, 'snapshot');
  const manifestPath = path.join(sessionPath, 'manifest.json');
  await mkdir(path.join(runtimePath, '.codex'), { recursive: true, mode: 0o700 });
  const environment = safeEnvironment(runtimePath);
  environment.CODEX_HOME = path.join(runtimePath, '.codex');
  const port = await availablePort();
  const manifest: PreviewManifest = {
    version: 1, taskId: request.taskId, token: raw.controlToken, controlUrl: '', state: 'starting',
    handle: { id, url: `http://localhost:${port}`, revision: request.revision, expiresAt: new Date(Date.now() + request.config.ttlSeconds * 1000).toISOString() },
  };
  const saveManifest = async () => {
    const temporary = manifestPath + '.tmp';
    await writeFile(temporary, JSON.stringify(manifest), { mode: 0o600 });
    await rename(temporary, manifestPath);
  };
  const controller = new AbortController();
  let child: ChildProcess | undefined;
  let childClosed: Promise<void> = Promise.resolve();
  let cleanup: () => Promise<void> = async () => {};
  let stopped = false;
  let resolveStopped: () => void;
  const stopRequested = new Promise<void>((resolve) => { resolveStopped = resolve; });
  let logs = '';
  let ttlTimer: ReturnType<typeof setTimeout> | undefined;
  let killTimer: ReturnType<typeof setTimeout> | undefined;
  const killOwnedChild = (signal: NodeJS.Signals) => {
    if (!child?.pid || child.exitCode !== null || child.signalCode !== null) {
      return;
    }
    try {
      if (process.platform === 'win32') {
        child.kill(signal);
      } else {
        process.kill(-child.pid, signal);
      }
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'ESRCH') {
        child.kill(signal);
      }
    }
  };
  const requestStop = (reason?: string) => {
    if (stopped) {
      return;
    }
    stopped = true;
    if (reason) {
      manifest.error = redact(reason);
      manifest.state = 'failed';
    } else {
      manifest.state = 'stopping';
    }
    controller.abort(new Error(reason || 'The preview was stopped.'));
    killOwnedChild('SIGTERM');
    killTimer = setTimeout(() => killOwnedChild('SIGKILL'), 1500);
    resolveStopped!();
  };
  const control = http.createServer((incoming, outgoing) => {
    const auth = incoming.headers.authorization || '';
    if (!tokenMatches(auth, `Bearer ${manifest.token}`)) {
      outgoing.writeHead(403).end();
      return;
    }
    if ((incoming.method !== 'GET' || incoming.url !== '/status') && (incoming.method !== 'POST' || incoming.url !== '/stop')) {
      outgoing.writeHead(404).end();
      return;
    }
    if (incoming.method === 'POST') {
      requestStop();
    }
    outgoing.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    outgoing.end(JSON.stringify({ taskId: request.taskId, state: manifest.state, preview: manifest.handle, error: manifest.error }));
  });
  await new Promise<void>((resolve, reject) => {
    control.once('error', reject);
    control.listen(0, '127.0.0.1', resolve);
  });
  manifest.controlUrl = `http://127.0.0.1:${(control.address() as net.AddressInfo).port}/`;
  await saveManifest();
  const checkEarlyStop = async () => {
    try {
      const token = await readFile(path.join(sessionPath, 'stop.request'), 'utf8');
      if (tokenMatches(token, manifest.token)) {
        requestStop();
      }
    } catch { /* No early cancellation was requested. */ }
  };
  const watcher = watch(sessionPath, (_event, filename) => {
    if (filename === 'stop.request') {
      void checkEarlyStop();
    }
  });
  process.once('SIGTERM', () => requestStop());
  process.once('SIGINT', () => requestStop());
  const startupTimer = setTimeout(() => requestStop('Preview preparation exceeded 170 seconds.'), 170000);
  try {
    await checkEarlyStop();
    controller.signal.throwIfAborted();
    const worktree = await realpath(request.worktreePath);
    const repository = await realpath(request.repositoryPath);
    if ((await git(['rev-parse', '--path-format=absolute', '--git-common-dir'], worktree, environment, controller.signal))
      !== (await git(['rev-parse', '--path-format=absolute', '--git-common-dir'], repository, environment, controller.signal))) {
      throw new Error('The preview worktree belongs to another repository.');
    }
    const revision = await git(['rev-parse', '--verify', '--end-of-options', `${request.revision}^{commit}`], worktree, environment, controller.signal);
    if (revision !== request.revision) {
      throw new Error('The preview revision could not be resolved exactly.');
    }
    await validateArchive(worktree, revision, environment, controller.signal);
    await mkdir(snapshotPath, { mode: 0o700 });
    const archive = path.join(runtimePath, 'revision.tar');
    await git(['archive', '--format=tar', `--output=${archive}`, revision], worktree, environment, controller.signal);
    const extracted = await command(['tar', '-xf', archive, '-C', snapshotPath], sessionPath, environment, controller.signal);
    await rm(archive, { force: true });
    if (extracted.exitCode !== 0) {
      throw new Error('Could not extract the immutable preview snapshot: ' + extracted.output);
    }
    await inspectSnapshot(snapshotPath, snapshotPath);
    const applicationPath = await realpath(path.resolve(snapshotPath, request.config.directory || '.'));
    if (!isWithin(applicationPath, snapshotPath) || !(await lstat(applicationPath)).isDirectory()) {
      throw new Error('The selected application directory is outside the snapshot.');
    }
    let applicationEnvironment = { ...environment };
    if (request.config.driver === 'laravel') {
      const prepared = await prepareLaravelPreview({
        snapshotPath, repositoryPath: repository, directory: request.config.directory,
        runtimePath, database: request.database, signal: controller.signal,
      });
      applicationEnvironment = prepared.environment;
      cleanup = prepared.cleanup;
    }
    controller.signal.throwIfAborted();
    Object.assign(applicationEnvironment, {
      HOST: '127.0.0.1', BIND_ADDRESS: '127.0.0.1', PORT: String(port), APP_URL: manifest.handle.url,
      CODEX_HOME: environment.CODEX_HOME,
    });
    const argv = request.config.command.map((part) => part.replaceAll('{port}', String(port)).replaceAll('{host}', '127.0.0.1'));
    child = spawn(process.execPath, [
      codexEntrypoint, 'sandbox', '-c', 'sandbox_mode="workspace-write"',
      '-c', 'sandbox_workspace_write.network_access=true',
      '-c', `sandbox_workspace_write.writable_roots=${JSON.stringify([runtimePath])}`,
      '--', ...argv,
    ], {
      cwd: applicationPath, env: applicationEnvironment, shell: false, detached: process.platform !== 'win32', stdio: ['ignore', 'pipe', 'pipe'],
    });
    childClosed = new Promise<void>((resolve) => {
      child!.once('close', (code) => {
        resolve();
        if (!stopped) {
          requestStop(`The preview server exited (code ${code ?? 'unknown'}). ${logs.slice(-4000)}`);
        }
      });
    });
    child.once('error', (error) => requestStop(error.message));
    const collect = (chunk: string) => { logs = (logs + redact(chunk)).slice(-12000); };
    child.stdout!.setEncoding('utf8').on('data', collect);
    child.stderr!.setEncoding('utf8').on('data', collect);
    const readyUrl = new URL(request.config.paths[0]!, `http://127.0.0.1:${port}`);
    while (!controller.signal.aborted) {
      try {
        const response = await fetch(readyUrl, { redirect: 'manual', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(1500)]) });
        await response.body?.cancel();
        if (response.status >= 300) {
          throw new Error(`The preview path ${request.config.paths[0]} returned HTTP ${response.status}. ${logs.slice(-3000)}`);
        }
        break;
      } catch (error) {
        if (error instanceof Error && error.message.startsWith('The preview path')) {
          throw error;
        }
        controller.signal.throwIfAborted();
        await delay(100, undefined, { signal: controller.signal });
      }
    }
    controller.signal.throwIfAborted();
    clearTimeout(startupTimer);
    manifest.state = 'ready';
    manifest.handle.expiresAt = new Date(Date.now() + request.config.ttlSeconds * 1000).toISOString();
    await saveManifest();
    ttlTimer = setTimeout(() => requestStop(), request.config.ttlSeconds * 1000);
    await stopRequested;
  } catch (error) {
    if (!stopped) {
      requestStop(error instanceof Error ? error.message : String(error));
    }
  } finally {
    clearTimeout(startupTimer);
    if (ttlTimer) clearTimeout(ttlTimer);
    killOwnedChild('SIGTERM');
    await Promise.race([childClosed, delay(2000)]);
    killOwnedChild('SIGKILL');
    if (killTimer) clearTimeout(killTimer);
    let cleanupFailed = false;
    try {
      await cleanup();
      if (request.config.driver === 'laravel' && await lstat(path.join(runtimePath, 'database.json')).then(() => true, () => false)) {
        throw new Error('Disposable preview database cleanup remains unresolved. Its private runtime has been retained.');
      }
    } catch (error) {
      cleanupFailed = true;
      manifest.error = redact(error instanceof Error ? error.message : String(error));
      manifest.state = 'failed';
    }
    await writeFile(path.join(sessionPath, 'server.log'), logs, { mode: 0o600 });
    if (!cleanupFailed) {
      try {
        await rm(snapshotPath, { recursive: true, force: true });
        await rm(runtimePath, { recursive: true, force: true });
      } catch (error) {
        cleanupFailed = true;
        manifest.error = `Preview file cleanup failed: ${redact(error instanceof Error ? error.message : String(error))}`;
        manifest.state = 'failed';
      }
    }
    watcher.close();
    control.closeAllConnections();
    await new Promise<void>((resolve) => control.close(() => resolve()));
    if (manifest.state !== 'failed') manifest.state = 'stopped';
    manifest.cleanupComplete = !cleanupFailed;
    await saveManifest();
  }
}

await main().catch(async (error) => {
  const requestFile = process.argv[2];
  if (requestFile && path.isAbsolute(requestFile)) {
    await writeFile(path.join(path.dirname(requestFile), 'startup-error.txt'), redact(error instanceof Error ? error.message : String(error)), { mode: 0o600 }).catch(() => {});
  }
  process.exitCode = 1;
});
