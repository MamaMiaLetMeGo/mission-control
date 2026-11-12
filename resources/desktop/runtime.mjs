import { execFile, spawn } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile, rename, rm, realpath, stat, chmod } from 'node:fs/promises';
import { get } from 'node:http';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { homedir, tmpdir, userInfo } from 'node:os';
import { fileURLToPath } from 'node:url';

const application = 'mission-control';
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
export const repositoryHash = path => createHash('sha256').update(path).digest('hex');

export function runtimeEnvironment(config) {
    const user = userInfo();
    return { HOME: homedir(), USER: user.username, LOGNAME: user.username, TMPDIR: tmpdir(), LANG: 'en_US.UTF-8', LC_ALL: 'en_US.UTF-8', PATH: [...new Set([dirname(config.nodePath), dirname(config.phpPath), '/opt/homebrew/bin', '/opt/homebrew/sbin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'])].join(':') };
}

export function jobArguments(config, kind) {
    return [config.phpPath, join(config.repositoryPath, 'artisan'), ...(kind === 'web'
        ? ['serve', '--host=127.0.0.1', `--port=${config.port}`, '--tries=1', '--no-reload', '--no-interaction']
        : ['queue:listen', '--queue=missions,default', '--tries=1', '--timeout=900', '--sleep=2', '--no-interaction'])];
}

const xml = value => String(value).replace(/[<>&"']/g, char => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' })[char]);
export function launchAgentPlist(config, kind) {
    const environment = Object.entries(runtimeEnvironment(config)).map(([key, value]) => `<key>${xml(key)}</key><string>${xml(value)}</string>`).join('');
    return `<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>
<key>Label</key><string>${xml(config.label)}.${kind}</string>
<key>ProgramArguments</key><array>${jobArguments(config, kind).map(value => `<string>${xml(value)}</string>`).join('')}</array>
<key>WorkingDirectory</key><string>${xml(config.repositoryPath)}</string>
<key>EnvironmentVariables</key><dict>${environment}</dict>
<key>RunAtLoad</key><false/><key>AbandonProcessGroup</key><false/><key>ExitTimeOut</key><integer>5</integer>
<key>StandardOutPath</key><string>${xml(join(config.logDirectory, `${kind}.log`))}</string>
<key>StandardErrorPath</key><string>${xml(join(config.logDirectory, `${kind}.log`))}</string>
</dict></plist>`;
}

export function parseJob(output, config, kind) {
    const field = name => output.match(new RegExp(`^\\t${name} = (.+)$`, 'm'))?.[1];
    const args = output.match(/^\targuments = \{\n([\s\S]*?)^\t\}/m)?.[1].split('\n').map(line => line.trim()).filter(Boolean);
    const owned = field('path') === join(config.stateDirectory, `${kind}.plist`)
        && field('working directory') === config.repositoryPath
        && field('program') === config.phpPath
        && JSON.stringify(args) === JSON.stringify(jobArguments(config, kind));
    return { loaded: true, owned, pid: Number(field('pid')) || null };
}

export function safeToStop(preflight) {
    return preflight?.database?.ready === true && ['active_tasks', 'active_runs', 'pending_jobs', 'reserved_jobs'].every(key => preflight[key] === 0);
}

export function deriveStatus(config, snapshot) {
    const { web, worker, probe, preflight, externalWorkers = [] } = snapshot;
    const ownJobs = [web, worker].some(job => job.owned && job.pid);
    const identityConflict = [web, worker].some(job => job.loaded && !job.owned);
    const ours = probe.kind === 'ours';
    const base = { state: 'stopped', message: 'Start Mission Control to open your local workspace.', canStart: true, canStop: ownJobs && safeToStop(preflight), canOpen: ours, url: `http://127.0.0.1:${config.port}/`, logDirectory: config.logDirectory };
    if (identityConflict || probe.kind === 'foreign') return { ...base, state: 'error', message: 'Another service is using the local address or launcher identity. Close that service before starting Mission Control.', canStart: false, canStop: false, canOpen: false };
    if (externalWorkers.length || (ours && !web.pid)) return { ...base, state: 'external', message: 'Mission Control is already running outside this launcher. You can open the existing dashboard.', canStart: false, canStop: false };
    if (ours && web.pid && worker.pid) {
        const workVerified = ['active_tasks', 'active_runs', 'pending_jobs', 'reserved_jobs'].every(key => Number.isInteger(preflight?.[key]) && preflight[key] >= 0);
        const message = preflight?.database?.ready !== true
            ? 'The launcher cannot verify the database. Check Postgres.app connection permissions and database settings. Services remain running; Stop is unavailable.'
            : !workVerified ? 'Mission Control is running, but active work could not be verified. Stop remains unavailable until the status check succeeds.'
                : safeToStop(preflight) ? 'Dashboard and task worker are running locally.' : 'Mission Control is running. Stop becomes available when queued work finishes.';
        return { ...base, state: 'running', canStart: false, message };
    }
    if (!preflight?.ready) return { ...base, state: 'error', canStart: false, message: preflight?.database?.ready === false ? 'The local database cannot be verified. Open Postgres.app and check its connection permissions and database settings, then try again.' : 'Local setup needs attention. Check that the project, PHP, Node, and built app files are available.' };
    if (ownJobs) return { ...base, state: 'error', message: 'One local service is offline. Start Mission Control to restore it.' };
    return base;
}

export class RuntimeController {
    constructor(config, operations) { this.config = config; this.operations = operations; }
    async status() { return deriveStatus(this.config, await this.operations.inspect()); }
    async start() {
        return this.operations.withLock(async () => {
            const initial = await this.operations.inspect();
            const current = deriveStatus(this.config, initial);
            if (!current.canStart) return current;
            for (const kind of ['web', 'worker']) {
                const snapshot = await this.operations.inspect();
                const status = deriveStatus(this.config, snapshot);
                if (status.state === 'external' || (!status.canStart && status.state !== 'running')) return status;
                if (snapshot[kind].pid) continue;
                await this.operations.ensureLoaded(kind);
                await this.operations.kickstart(kind);
                await this.operations.waitFor(kind);
            }
            return this.status();
        });
    }
    async stop() {
        return this.operations.withLock(async () => {
            const current = await this.status();
            if (!current.canStop) return { ...current, message: current.state === 'external' ? current.message : 'Stop is unavailable while work is queued, active, or cannot be verified.' };
            const guard = await this.operations.acquireGuard();
            try {
                for (const kind of ['worker', 'web']) {
                    if (!guard.alive()) throw new Error('The safe-stop check expired.');
                    await this.operations.bootout(kind);
                }
            } finally { await guard.release(); }
            return this.status();
        });
    }
}

function execute(file, args, config, timeout = 10000) {
    return new Promise(resolve => execFile(file, args, { cwd: config.repositoryPath, env: runtimeEnvironment(config), timeout, maxBuffer: 512000 }, (error, stdout) => resolve({ code: error ? error.code || 1 : 0, stdout })));
}

async function loadConfig(path) {
    const input = JSON.parse(await readFile(path, 'utf8'));
    for (const key of ['repositoryPath', 'nodePath', 'phpPath']) if (!isAbsolute(input[key] || '') || /[\n\r\0]/.test(input[key])) throw new Error('Invalid launcher configuration.');
    const repositoryPath = await realpath(input.repositoryPath);
    const hash = repositoryHash(repositoryPath);
    const stateDirectory = join(homedir(), 'Library', 'Application Support', 'Mission Control', hash.slice(0, 12));
    const config = { repositoryPath, nodePath: input.nodePath, phpPath: input.phpPath, repoRoot: hash, stateDirectory, logDirectory: join(stateDirectory, 'logs'), port: 8765, label: `com.mission-control.desktop.${hash.slice(0, 12)}`, domain: `gui/${userInfo().uid}` };
    await mkdir(config.logDirectory, { recursive: true, mode: 0o700 });
    await chmod(config.stateDirectory, 0o700);
    return config;
}

function health(config) {
    return new Promise(resolve => {
        const request = get(`http://127.0.0.1:${config.port}/desktop/health`, { timeout: 2000 }, response => {
            let text = '';
            response.on('data', chunk => { text += chunk; if (text.length > 32000) request.destroy(); });
            response.on('end', () => {
                try { const value = JSON.parse(text); resolve({ kind: response.statusCode === 200 && value.application === application && value.repo_root === config.repoRoot ? 'ours' : 'foreign' }); }
                catch { resolve({ kind: 'foreign' }); }
            });
        });
        request.on('timeout', () => request.destroy());
        request.on('error', error => resolve({ kind: error.code === 'ECONNREFUSED' ? 'absent' : 'foreign' }));
        request.on('close', () => resolve({ kind: 'foreign' }));
    });
}

async function preflight(config) {
    const result = await execute(config.phpPath, ['artisan', 'mission:desktop-status', '--json', '--no-interaction'], config);
    try { const data = JSON.parse(result.stdout); if (data.application === application && data.repo_root === config.repoRoot) return data; } catch {}
    return { ready: false, database: { ready: false } };
}

async function readJob(config, kind) {
    const result = await execute('/bin/launchctl', ['print', `${config.domain}/${config.label}.${kind}`], config);
    return result.code === 0 ? parseJob(result.stdout, config, kind) : { loaded: false, owned: false, pid: null };
}

async function externalWorkers(config, worker) {
    const result = await execute('/bin/ps', ['-axo', 'pid=,ppid=,uid=,command='], config);
    if (result.code !== 0) return ['unverified'];
    const rows = result.stdout.split('\n').map(line => line.trim().match(/^(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/)).filter(Boolean);
    const parents = new Map(rows.map(row => [Number(row[1]), Number(row[2])]));
    const isOwned = pid => { const seen = new Set(); while (pid && !seen.has(pid)) { if (worker.owned && pid === worker.pid) return true; seen.add(pid); pid = parents.get(pid); } return false; };
    const external = [];
    for (const row of rows) {
        const pid = Number(row[1]);
        if (Number(row[3]) !== userInfo().uid || !/(?:^|[ /])artisan queue:(?:listen|work)(?:\s|$)/.test(row[4]) || isOwned(pid)) continue;
        const cwd = await execute('/usr/sbin/lsof', ['-a', '-p', String(pid), '-d', 'cwd', '-Fn'], config);
        const directory = cwd.stdout.split('\n').find(line => line.startsWith('n'))?.slice(1);
        if (directory === config.repositoryPath) external.push(pid);
    }
    return external;
}

export async function withActionLock(config, operation) {
    const path = join(config.stateDirectory, 'action.lock');
    const gate = join(config.stateDirectory, 'action-gate.lock');
    const token = randomUUID();
    try { await mkdir(gate, { mode: 0o700 }); }
    catch { throw new Error('Another launcher operation is in progress.'); }
    try {
        try { await mkdir(path, { mode: 0o700 }); }
        catch (error) {
            if (error.code !== 'EEXIST') throw error;
            let owner;
            try { owner = JSON.parse(await readFile(join(path, 'owner.json'), 'utf8')); } catch {}
            let running = true;
            if (Number.isInteger(owner?.pid)) { try { process.kill(owner.pid, 0); } catch (error) { if (error.code === 'ESRCH') running = false; } }
            else running = Date.now() - (await stat(path)).mtimeMs < 120000;
            if (running) throw new Error('Another launcher operation is in progress.');
            await rm(path, { recursive: true });
            await mkdir(path, { mode: 0o700 });
        }
        await writeFile(join(path, 'owner.json'), JSON.stringify({ pid: process.pid, token }), { mode: 0o600 });
    } finally { await rm(gate, { recursive: true, force: true }); }
    try { return await operation(); }
    finally {
        const owner = JSON.parse(await readFile(join(path, 'owner.json'), 'utf8').catch(() => '{}'));
        if (owner.token === token) await rm(path, { recursive: true, force: true });
    }
}

export function acquireStopGuard(config) {
    return new Promise((resolve, reject) => {
        const child = spawn(config.phpPath, ['artisan', 'mission:desktop-stop-guard', '--no-interaction'], { cwd: config.repositoryPath, env: runtimeEnvironment(config), stdio: ['pipe', 'pipe', 'ignore'] });
        let buffer = '', accepted = false;
        const timer = setTimeout(() => { child.stdin.end(); child.kill(); reject(new Error('The safe-stop check timed out.')); }, 12000);
        child.on('error', () => { clearTimeout(timer); reject(new Error('The safe-stop check could not start.')); });
        child.on('exit', () => { clearTimeout(timer); if (!accepted) reject(new Error('Mission Control is busy. Let its current work finish before stopping.')); });
        child.stdout.on('data', chunk => {
            buffer += chunk;
            if (accepted || !buffer.includes('\n')) return;
            clearTimeout(timer);
            try {
                if (JSON.parse(buffer.split('\n')[0]).ready !== true) throw new Error();
                accepted = true;
                resolve({ alive: () => child.exitCode === null && child.signalCode === null, release: async () => { if (child.exitCode === null) { child.stdin.end('\n'); await Promise.race([new Promise(done => child.once('exit', done)), pause(2000)]); } } });
            } catch { child.stdin.end(); reject(new Error('Mission Control is busy. Let its current work finish before stopping.')); }
        });
        child.stdin.on('error', () => {});
    });
}

function operations(config) {
    const target = kind => `${config.domain}/${config.label}.${kind}`;
    const ops = {
        inspect: async () => {
            const [web, worker, probe, details] = await Promise.all([readJob(config, 'web'), readJob(config, 'worker'), health(config), preflight(config)]);
            return { web, worker, probe, preflight: details, externalWorkers: await externalWorkers(config, worker) };
        },
        withLock: operation => withActionLock(config, operation),
        ensureLoaded: async kind => {
            const job = await readJob(config, kind);
            if (job.loaded) { if (!job.owned) throw new Error('The launcher service identity changed.'); return; }
            const file = join(config.stateDirectory, `${kind}.plist`);
            await writeFile(`${file}.tmp`, launchAgentPlist(config, kind), { mode: 0o600 });
            await rename(`${file}.tmp`, file);
            const log = join(config.logDirectory, `${kind}.log`);
            await writeFile(log, '', { flag: 'a', mode: 0o600 });
            await chmod(log, 0o600);
            if ((await execute('/bin/launchctl', ['bootstrap', config.domain, file], config)).code !== 0) throw new Error('macOS could not register the local service.');
        },
        kickstart: async kind => {
            const job = await readJob(config, kind);
            if (!job.owned) throw new Error('The launcher service identity changed.');
            if (!job.pid && (await execute('/bin/launchctl', ['kickstart', target(kind)], config)).code !== 0) throw new Error('macOS could not start the local service.');
        },
        waitFor: async kind => {
            for (let attempt = 0; attempt < 30; attempt += 1) {
                const job = await readJob(config, kind);
                if (job.owned && job.pid && (kind === 'worker' || (await health(config)).kind === 'ours')) return;
                await pause(300);
            }
            throw new Error('A local service did not become ready. Open launcher logs for details.');
        },
        acquireGuard: () => acquireStopGuard(config),
        bootout: async kind => {
            const job = await readJob(config, kind);
            if (!job.loaded) return;
            if (!job.owned) throw new Error('The launcher service identity changed.');
            if ((await execute('/bin/launchctl', ['bootout', '--wait', target(kind)], config)).code !== 0) throw new Error('macOS could not stop a local service.');
            if ((await readJob(config, kind)).loaded) throw new Error('A local service is still stopping.');
        },
    };
    return ops;
}

async function main() {
    const command = process.argv[2];
    const configIndex = process.argv.indexOf('--config');
    let config, controller;
    try {
        if (!['status', 'start', 'stop'].includes(command) || configIndex < 0) throw new Error('Invalid launcher request.');
        config = await loadConfig(resolve(process.argv[configIndex + 1]));
        controller = new RuntimeController(config, operations(config));
        console.log(JSON.stringify(await controller[command]()));
    } catch (error) {
        const status = controller ? await controller.status().catch(() => ({})) : {};
        const safeMessages = ['Another launcher operation is in progress.', 'Mission Control is busy. Let its current work finish before stopping.', 'The safe-stop check timed out.', 'A local service did not become ready. Open launcher logs for details.'];
        console.log(JSON.stringify({ state: 'error', canStart: false, canStop: false, canOpen: false, ...status, message: safeMessages.includes(error.message) ? error.message : 'The local services could not be updated. Check the launcher logs and try again.' }));
    }
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
