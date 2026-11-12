import { randomBytes, randomUUID } from 'node:crypto';
import { constants } from 'node:fs';
import { cp, lstat, mkdir, readFile, readdir, readlink, realpath, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { command, safeEnvironment } from './process.js';
import { isWithin } from './validation.js';

const require = createRequire(import.meta.url);
const codexEntrypoint = path.join(path.dirname(require.resolve('@openai/codex/package.json')), 'bin/codex.js');

export interface PreviewDatabase {
  driver: 'sqlite' | 'pgsql';
  host: string;
  port: number;
  username: string;
}

export interface LaravelPreparation {
  snapshotPath: string;
  repositoryPath: string;
  directory: string;
  runtimePath: string;
  database?: PreviewDatabase;
  signal?: AbortSignal;
}

async function exists(file: string): Promise<boolean> {
  try { await lstat(file); return true; } catch { return false; }
}

export async function matchingDependencyLocks(source: string, snapshot: string, lock: string): Promise<boolean> {
  try {
    const [before, after] = await Promise.all([readFile(path.join(source, lock)), readFile(path.join(snapshot, lock))]);
    return before.equals(after);
  } catch { return false; }
}

async function verifyLinks(directory: string, root: string): Promise<void> {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) {
      const target = path.resolve(directory, await readlink(file));
      if (!isWithin(target, root)) throw new Error('A dependency symlink points outside the preview snapshot.');
      try {
        if (!isWithin(await realpath(file), root)) throw new Error('A dependency symlink resolves outside the preview snapshot.');
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
      }
    } else if (entry.isDirectory()) {
      await verifyLinks(file, root);
    }
  }
}

async function copyDependencies(source: string, snapshot: string, folder: string, lock: string): Promise<boolean> {
  const original = path.join(source, folder);
  if (!await matchingDependencyLocks(source, snapshot, lock) || !await exists(original)) return false;
  if ((await lstat(original)).isSymbolicLink()) return false;
  const destination = path.join(snapshot, folder);
  await cp(original, destination, {
    recursive: true, verbatimSymlinks: true, mode: constants.COPYFILE_FICLONE,
    filter: (candidate) => !/^\.env(?:\.|$)/.test(path.basename(candidate)),
  });
  await verifyLinks(destination, snapshot);
  return true;
}

export async function prepareLaravelPreview(input: LaravelPreparation): Promise<{ environment: Record<string, string>; cleanup: () => Promise<void> }> {
  const snapshot = await realpath(input.snapshotPath);
  const application = await realpath(path.resolve(snapshot, input.directory));
  if (!isWithin(application, snapshot)) throw new Error('The Laravel app directory must stay inside its preview snapshot.');
  if (!await exists(path.join(application, 'artisan'))) throw new Error('The selected preview directory does not contain a Laravel artisan file.');
  const original = path.resolve(input.repositoryPath, input.directory);
  const runtime = await realpath(input.runtimePath);
  const home = path.join(runtime, 'home');
  const phpIni = path.join(runtime, 'php-ini');
  await mkdir(path.join(home, '.codex'), { recursive: true, mode: 0o700 });
  await mkdir(phpIni, { mode: 0o700 });
  await writeFile(path.join(phpIni, 'zz-mission-preview.ini'), 'memory_limit=512M\n', { mode: 0o600 });
  const environment = {
    ...safeEnvironment(home),
    APP_ENV: 'local', APP_DEBUG: 'false', APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_URL: 'http://localhost', ASSET_URL: '', DB_URL: '', DB_PASSWORD: '',
    SESSION_DRIVER: 'file', SESSION_COOKIE: `mc_preview_${randomUUID().replaceAll('-', '')}`,
    SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log', BROADCAST_CONNECTION: 'log', LOG_CHANNEL: 'stderr',
    FILESYSTEM_DISK: 'local', SCOUT_DRIVER: 'null', TELESCOPE_ENABLED: 'false',
    COMPOSER_HOME: path.join(home, '.composer'), NPM_CONFIG_USERCONFIG: path.join(home, '.npmrc'),
    CODEX_HOME: path.join(home, '.codex'),
    // An empty scan component retains PHP's compiled extension configuration before our private override.
    PHP_INI_SCAN_DIR: `${path.delimiter}${phpIni}`,
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(runtime, 'preview.sqlite'), DB_HOST: '', DB_PORT: '', DB_USERNAME: '',
  };
  const run = async (argv: string[], directory = application, signal = input.signal): Promise<void> => {
    const result = await command([
      process.execPath, codexEntrypoint, 'sandbox', '-c', 'sandbox_mode="workspace-write"',
      '-c', 'sandbox_workspace_write.network_access=true',
      '-c', `sandbox_workspace_write.writable_roots=${JSON.stringify([runtime])}`,
      '--', ...argv,
    ], directory, environment, signal);
    if (result.exitCode !== 0) throw new Error(`Preview preparation failed (${argv.slice(0, 2).join(' ')}): ${result.output.slice(-6000)}`);
  };
  let createdDatabase: string | undefined;
  const cleanup = async (): Promise<void> => {
    if (!createdDatabase) return;
    const database = input.database!;
    const result = await command(['dropdb', '--if-exists', '--force', '-h', database.host, '-p', String(database.port), '-U', database.username, createdDatabase], runtime, safeEnvironment(home), AbortSignal.timeout(15000));
    if (result.exitCode !== 0) throw new Error(`Could not remove disposable preview database ${createdDatabase}: ${result.output.slice(-1000)}`);
    await rm(path.join(runtime, 'database.json'), { force: true });
    createdDatabase = undefined;
  };
  try {
    // Runtime state from a checkout must never redirect this preview to its real services.
    for (const filename of await readdir(application)) {
      if (/^\.env(?:\.|$)/.test(filename) && filename !== '.env.example') await rm(path.join(application, filename), { recursive: true, force: true });
    }
    await rm(path.join(application, 'bootstrap/cache/config.php'), { force: true });
    await rm(path.join(application, 'public/hot'), { force: true });
    for (const folder of ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']) {
      await mkdir(path.join(application, folder), { recursive: true });
    }
    if (!await copyDependencies(original, application, 'vendor', 'composer.lock')) {
      await run(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--no-scripts']);
    }
    await run(['composer', 'dump-autoload', '--no-interaction', '--no-scripts']);
    await run(['php', 'artisan', 'package:discover', '--no-interaction']);

    if (input.database?.driver === 'pgsql') {
      const database = input.database;
      if (!['/tmp', 'localhost', '127.0.0.1', '::1'].includes(database.host)
        || !Number.isInteger(database.port) || database.port < 1 || database.port > 65535
        || !/^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(database.username)) {
        throw new Error('Preview PostgreSQL must use a local host and a valid local database role.');
      }
      const name = `mc_preview_${randomUUID().replaceAll('-', '')}`;
      createdDatabase = name;
      await writeFile(path.join(runtime, 'database.json'), JSON.stringify({ ...database, name }), { mode: 0o600 });
      const created = await command(['createdb', '-h', database.host, '-p', String(database.port), '-U', database.username, name], runtime, safeEnvironment(home), input.signal);
      if (created.exitCode !== 0) throw new Error(`Could not create disposable preview database ${name}: ${created.output.slice(-1000)}`);
      Object.assign(environment, { DB_CONNECTION: 'pgsql', DB_DATABASE: name, DB_HOST: database.host, DB_PORT: String(database.port), DB_USERNAME: database.username });
    } else {
      await writeFile(environment.DB_DATABASE, '');
    }
    await run(['php', 'artisan', 'migrate', '--seed', '--force', '--no-interaction']);
    if (await exists(path.join(application, 'package.json'))) {
      const manifest = JSON.parse(await readFile(path.join(application, 'package.json'), 'utf8'));
      if (manifest.scripts?.build) {
        if (!await copyDependencies(original, application, 'node_modules', 'package-lock.json')) {
          if (!await exists(path.join(application, 'package-lock.json'))) throw new Error('A committed package-lock.json is required to build a reproducible Laravel preview.');
          await run(['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund']);
        }
        await run(['npm', 'run', 'build']);
      }
    }
    return { environment, cleanup };
  } catch (error) {
    await cleanup();
    throw error;
  }
}
