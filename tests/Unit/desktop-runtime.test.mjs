import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    RuntimeController, acquireStopGuard, deriveStatus, jobArguments, launchAgentPlist, parseJob,
    repositoryHash, runtimeEnvironment, safeToStop, withActionLock,
} from '../../resources/desktop/runtime.mjs';

const config = {
    repositoryPath: '/tmp/mission-control-fixture', nodePath: '/tmp/node/bin/node',
    phpPath: '/tmp/php/bin/php', stateDirectory: '/tmp/mission-control-fixture-state',
    logDirectory: '/tmp/mission-control-fixture-state/logs', port: 8765,
    label: 'com.mission-control.desktop.fixture', domain: 'gui/501',
};
const absent = () => ({ loaded: false, owned: false, pid: null });
const owned = pid => ({ loaded: true, owned: true, pid });
const ready = () => ({ ready: true, database: { ready: true }, active_tasks: 0, active_runs: 0, pending_jobs: 0, reserved_jobs: 0 });
const stopped = () => ({ web: absent(), worker: absent(), probe: { kind: 'absent' }, preflight: ready(), externalWorkers: [] });
const running = () => ({ ...stopped(), web: owned(101), worker: owned(102), probe: { kind: 'ours' } });

function controllerHarness(snapshot = stopped(), overrides = {}) {
    const state = structuredClone(snapshot), events = [];
    let guardAlive = true;
    const operations = {
        inspect: async () => structuredClone(state),
        withLock: async operation => { events.push('lock'); try { return await operation(); } finally { events.push('unlock'); } },
        ensureLoaded: async kind => { events.push(`load:${kind}`); state[kind] = owned(null); },
        kickstart: async kind => { events.push(`start:${kind}`); state[kind] = owned(kind === 'web' ? 101 : 102); },
        waitFor: async kind => { events.push(`ready:${kind}`); if (kind === 'web') state.probe = { kind: 'ours' }; },
        acquireGuard: async () => {
            events.push('guard');
            return { alive: () => guardAlive, release: async () => events.push('release') };
        },
        bootout: async kind => { events.push(`stop:${kind}`); state[kind] = absent(); if (kind === 'web') state.probe = { kind: 'absent' }; },
        ...overrides,
    };
    return { state, events, operations, expireGuard: () => { guardAlive = false; }, controller: new RuntimeController(config, operations) };
}

test('GUI child environment supplies absolute tool directories without inherited credentials or shell startup hooks', () => {
    const environment = runtimeEnvironment({ ...config, OPENAI_API_KEY: 'fixture-never-export', NODE_OPTIONS: '--require=fixture' });
    assert.deepEqual(environment.PATH.split(':').slice(0, 2), ['/tmp/node/bin', '/tmp/php/bin']);
    for (const key of ['OPENAI_API_KEY', 'GH_TOKEN', 'GITHUB_TOKEN', 'DATABASE_URL', 'NODE_OPTIONS', 'BASH_ENV', 'ENV', 'DYLD_INSERT_LIBRARIES']) {
        assert.equal(Object.hasOwn(environment, key), false);
    }
    assert.equal(environment.HOME.startsWith('/'), true);
    assert.equal(environment.PATH.split(':').includes('/usr/bin'), true);
});

test('private launchd jobs are on demand and own PHP descendants without a shell', { skip: process.platform !== 'darwin' }, async () => {
    const directory = await mkdtemp(join(tmpdir(), 'mission-launchd-test-'));
    try {
        const escaped = { ...config, repositoryPath: '/tmp/project & <review> "quoted"', logDirectory: '/tmp/logs & review' };
        for (const kind of ['web', 'worker']) {
            const file = join(directory, `${kind}.plist`);
            await writeFile(file, launchAgentPlist(escaped, kind));
            const document = JSON.parse(execFileSync('/usr/bin/plutil', ['-convert', 'json', '-o', '-', file], { encoding: 'utf8' }));
            assert.equal(document.RunAtLoad, false);
            assert.equal(document.AbandonProcessGroup, false);
            assert.equal(Object.hasOwn(document, 'KeepAlive'), false);
            assert.equal(document.ExitTimeOut > 0 && document.ExitTimeOut <= 10, true);
            assert.equal(document.WorkingDirectory, escaped.repositoryPath);
            assert.deepEqual(document.ProgramArguments, jobArguments(escaped, kind));
            assert.equal(document.ProgramArguments[0], config.phpPath);
            assert.equal(document.EnvironmentVariables.OPENAI_API_KEY, undefined);
            assert.equal(document.StandardOutPath, join(escaped.logDirectory, `${kind}.log`));
        }
    } finally { await rm(directory, { recursive: true, force: true }); }
});

test('web binding is fixed to loopback without fallback ports and queue has its bounded execution settings', () => {
    const web = jobArguments(config, 'web'), worker = jobArguments(config, 'worker');
    assert.ok(web.includes('--host=127.0.0.1'));
    assert.ok(web.includes('--port=8765'));
    assert.ok(web.includes('--tries=1'));
    assert.ok(web.includes('--no-reload'));
    assert.ok(worker.includes('--queue=missions,default'));
    assert.ok(worker.includes('--timeout=900'));
    assert.ok(worker.includes('--tries=1'));
});

function jobOutput(kind = 'web', changes = {}) {
    const fields = {
        path: join(config.stateDirectory, `${kind}.plist`), program: config.phpPath,
        'working directory': config.repositoryPath, pid: '101', ...changes,
    };
    return `gui/501/fixture = {\n${Object.entries(fields).map(([key, value]) => `\t${key} = ${value}`).join('\n')}\n\targuments = {\n${jobArguments(config, kind).map(value => `\t\t${value}`).join('\n')}\n\t}\n}\n`;
}

test('launchd ownership requires the exact private plist, command, arguments, and repository', () => {
    assert.deepEqual(parseJob(jobOutput(), config, 'web'), owned(101));
    for (const changes of [
        { path: '/tmp/foreign.plist' }, { program: '/tmp/foreign-php' }, { 'working directory': '/tmp/other-project' },
    ]) assert.equal(parseJob(jobOutput('web', changes), config, 'web').owned, false);
    assert.equal(parseJob(jobOutput().replace('--port=8765', '--port=8766'), config, 'web').owned, false);
    assert.equal(parseJob(jobOutput('worker'), config, 'web').owned, false);
    assert.equal(parseJob(jobOutput().replace('\tpid = 101\n', ''), config, 'web').pid, null);
});

test('repository fingerprint distinguishes checkouts without exposing the path', () => {
    assert.match(repositoryHash(config.repositoryPath), /^[a-f0-9]{64}$/);
    assert.notEqual(repositoryHash(config.repositoryPath), repositoryHash(`${config.repositoryPath}-other`));
});

test('safe shutdown requires verified zero task, run, pending, and reserved counts', () => {
    assert.equal(safeToStop(ready()), true);
    assert.equal(safeToStop({ ...ready(), database: { ready: false } }), false);
    for (const key of ['active_tasks', 'active_runs', 'pending_jobs', 'reserved_jobs']) {
        for (const value of [1, null, undefined, '0']) assert.equal(safeToStop({ ...ready(), [key]: value }), false);
    }
    assert.equal(safeToStop(null), false);
});

test('status distinguishes stopped, owned running, external workers, and foreign identities', () => {
    assert.equal(deriveStatus(config, stopped()).canStart, true);
    assert.equal(deriveStatus(config, running()).canStop, true);
    assert.equal(deriveStatus(config, running()).canOpen, true);
    assert.equal(deriveStatus(config, { ...running(), preflight: { ...ready(), pending_jobs: 1 } }).canStop, false);
    const external = deriveStatus(config, { ...stopped(), probe: { kind: 'ours' } });
    assert.equal(external.state, 'external');
    assert.equal(external.canOpen, true);
    assert.equal(external.canStart, false);
    assert.equal(external.canStop, false);
    const workerOnly = deriveStatus(config, { ...stopped(), externalWorkers: [900] });
    assert.equal(workerOnly.canStart, false);
    assert.equal(workerOnly.canOpen, false);
    for (const snapshot of [
        { ...running(), web: { loaded: true, owned: false, pid: 101 } },
        { ...running(), probe: { kind: 'foreign' } },
    ]) {
        const status = deriveStatus(config, snapshot);
        assert.equal(status.state, 'error');
        assert.equal(status.canStart || status.canStop || status.canOpen, false);
    }
});

test('running services explain unverified database permissions without claiming queued work or enabling stop', () => {
    for (const database of [{ ready: false }, { ready: null }, undefined]) {
        const preflight = { ready: false, database, active_tasks: null, active_runs: null, pending_jobs: null, reserved_jobs: null };
        const status = deriveStatus(config, { ...running(), preflight });
        assert.equal(status.state, 'running');
        assert.equal(status.canOpen, true);
        assert.equal(status.canStart, false);
        assert.equal(status.canStop, false);
        assert.match(status.message, /cannot verify the database/);
        assert.match(status.message, /Postgres\.app connection permissions/);
        assert.doesNotMatch(status.message, /queued work/);
    }
});

test('missing work counts are distinguished from verified queued work', () => {
    const unknown = deriveStatus(config, { ...running(), preflight: { ...ready(), active_runs: null } });
    assert.equal(unknown.canOpen, true);
    assert.equal(unknown.canStop, false);
    assert.match(unknown.message, /active work could not be verified/);
    assert.doesNotMatch(unknown.message, /queued work/);
    const queued = deriveStatus(config, { ...running(), preflight: { ...ready(), pending_jobs: 1 } });
    assert.equal(queued.canStop, false);
    assert.match(queued.message, /queued work finishes/);
});

test('stopped services point to database connection permission settings and remain blocked', () => {
    const status = deriveStatus(config, { ...stopped(), preflight: { ready: false, database: { ready: false } } });
    assert.equal(status.state, 'error');
    assert.equal(status.canStart || status.canStop || status.canOpen, false);
    assert.match(status.message, /Open Postgres\.app/);
    assert.match(status.message, /connection permissions and database settings/);
});

test('startup launches the web and worker once under the action lock', async () => {
    const harness = controllerHarness();
    assert.equal((await harness.controller.start()).state, 'running');
    assert.deepEqual(harness.events, ['lock', 'load:web', 'start:web', 'ready:web', 'load:worker', 'start:worker', 'ready:worker', 'unlock']);
    harness.events.length = 0;
    await harness.controller.start();
    assert.deepEqual(harness.events, ['lock', 'unlock']);
});

test('startup repairs only a missing owned service and never restarts a live one', async () => {
    for (const missing of ['web', 'worker']) {
        const snapshot = running();
        snapshot[missing] = absent();
        if (missing === 'web') snapshot.probe = { kind: 'absent' };
        const harness = controllerHarness(snapshot);
        assert.equal((await harness.controller.start()).state, 'running');
        assert.deepEqual(harness.events, ['lock', `load:${missing}`, `start:${missing}`, `ready:${missing}`, 'unlock']);
    }
});

test('startup does not mutate foreign services, existing external workers, or an unready installation', async () => {
    for (const snapshot of [
        { ...stopped(), probe: { kind: 'foreign' } },
        { ...stopped(), probe: { kind: 'ours' } },
        { ...stopped(), externalWorkers: [900] },
        { ...stopped(), preflight: { ...ready(), ready: false } },
    ]) {
        const harness = controllerHarness(snapshot);
        await harness.controller.start();
        assert.deepEqual(harness.events, ['lock', 'unlock']);
    }
});

test('startup rechecks identity before changing either service', async () => {
    let inspections = 0;
    const harness = controllerHarness(stopped(), { inspect: async () => ++inspections === 1 ? stopped() : { ...stopped(), probe: { kind: 'foreign' } } });
    assert.equal((await harness.controller.start()).state, 'error');
    assert.deepEqual(harness.events, ['lock', 'unlock']);
});

test('stop holds the guard until worker then web have stopped and releases it afterward', async () => {
    const harness = controllerHarness(running());
    assert.equal((await harness.controller.stop()).state, 'stopped');
    assert.deepEqual(harness.events, ['lock', 'guard', 'stop:worker', 'stop:web', 'release', 'unlock']);
});

test('stop refuses active or unverifiable work without acquiring the shutdown guard', async () => {
    for (const preflight of [{ ...ready(), active_tasks: 1 }, { ...ready(), active_runs: 1 }, { ...ready(), pending_jobs: 1 }, { ...ready(), reserved_jobs: 1 }, { ...ready(), database: { ready: false } }]) {
        const harness = controllerHarness({ ...running(), preflight });
        await harness.controller.stop();
        assert.deepEqual(harness.events, ['lock', 'unlock']);
    }
});

test('a refused guard prevents all service termination', async () => {
    const harness = controllerHarness(running(), { acquireGuard: async () => { throw new Error('busy'); } });
    await assert.rejects(harness.controller.stop(), /busy/);
    assert.deepEqual(harness.events, ['lock', 'unlock']);
});

test('an expired guard halts partial shutdown and still releases its control stream', async () => {
    const harness = controllerHarness(running());
    harness.operations.bootout = async kind => { harness.events.push(`stop:${kind}`); harness.expireGuard(); };
    await assert.rejects(harness.controller.stop(), /expired/);
    assert.deepEqual(harness.events, ['lock', 'guard', 'stop:worker', 'release', 'unlock']);
});

test('service termination failure releases the guard and leaves the other service untouched', async () => {
    const harness = controllerHarness(running(), { bootout: async () => { throw new Error('service identity changed'); } });
    await assert.rejects(harness.controller.stop(), /identity changed/);
    assert.deepEqual(harness.events, ['lock', 'guard', 'release', 'unlock']);
});

test('filesystem action lock excludes a concurrent operation and is released after an error', async () => {
    const stateDirectory = await mkdtemp(join(tmpdir(), 'mission-action-lock-test-'));
    let release, entered;
    const started = new Promise(resolve => { entered = resolve; });
    const wait = new Promise(resolve => { release = resolve; });
    try {
        const first = withActionLock({ ...config, stateDirectory }, async () => { entered(); await wait; });
        await started;
        await assert.rejects(withActionLock({ ...config, stateDirectory }, async () => assert.fail('second operation entered')), /operation is in progress/);
        release();
        await first;
        await assert.rejects(withActionLock({ ...config, stateDirectory }, async () => { throw new Error('operation failed'); }), /operation failed/);
        await assert.rejects(readFile(join(stateDirectory, 'action.lock', 'owner.json')), { code: 'ENOENT' });
        let completed = false;
        await withActionLock({ ...config, stateDirectory }, async () => { completed = true; });
        assert.equal(completed, true);
    } finally { release?.(); await rm(stateDirectory, { recursive: true, force: true }); }
});

test('simultaneous stale-lock recovery admits only one operation', async () => {
    const stateDirectory = await mkdtemp(join(tmpdir(), 'mission-stale-lock-test-'));
    const path = join(stateDirectory, 'action.lock');
    const deadPid = Number(execFileSync(process.execPath, ['-e', 'process.stdout.write(String(process.pid))'], { encoding: 'utf8' }));
    let release, finishDenied, finishEntered, timer, entered = 0, denied = 0;
    const hold = new Promise(resolve => { release = resolve; });
    const allDenied = new Promise(resolve => { finishDenied = resolve; });
    const firstEntered = new Promise(resolve => { finishEntered = resolve; });
    let attempts = [];
    try {
        await mkdir(path);
        await writeFile(join(path, 'owner.json'), JSON.stringify({ pid: deadPid, token: 'abandoned' }));
        attempts = Array.from({ length: 8 }, () => withActionLock({ ...config, stateDirectory }, async () => {
            entered += 1;
            finishEntered();
            await hold;
        }).catch(error => {
            assert.match(error.message, /operation is in progress/);
            denied += 1;
            if (denied === 7) finishDenied();
        }));
        await Promise.race([
            Promise.all([allDenied, firstEntered]),
            new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Concurrent recovery admitted multiple owners or never settled')), 2000); }),
        ]);
        assert.equal(entered, 1);
        const owner = JSON.parse(await readFile(join(path, 'owner.json'), 'utf8'));
        assert.equal(owner.pid, process.pid);
        assert.notEqual(owner.token, 'abandoned');
    } finally {
        clearTimeout(timer);
        release?.();
        await Promise.allSettled(attempts);
        await rm(stateDirectory, { recursive: true, force: true });
    }
});

test('lock release cannot remove a replacement owner and abandoned recovery gates fail closed', async () => {
    const stateDirectory = await mkdtemp(join(tmpdir(), 'mission-lock-owner-test-'));
    const ownerFile = join(stateDirectory, 'action.lock', 'owner.json');
    try {
        await withActionLock({ ...config, stateDirectory }, async () => {
            await writeFile(ownerFile, JSON.stringify({ pid: process.pid, token: 'replacement-owner' }));
        });
        assert.equal(JSON.parse(await readFile(ownerFile, 'utf8')).token, 'replacement-owner');
        await mkdir(join(stateDirectory, 'action-gate.lock'));
        await assert.rejects(withActionLock({ ...config, stateDirectory }, async () => assert.fail('abandoned gate was bypassed')), /operation is in progress/);
        assert.equal(JSON.parse(await readFile(ownerFile, 'utf8')).token, 'replacement-owner');
    } finally { await rm(stateDirectory, { recursive: true, force: true }); }
});

test('shutdown guard protocol keeps its child alive until release and refuses a busy response', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'mission-stop-guard-test-'));
    const phpPath = join(directory, 'fake-php');
    let guard;
    try {
        await writeFile(phpPath, `#!${process.execPath}\nprocess.stdout.write(JSON.stringify({ready:true})+'\\n');process.stdin.resume();process.stdin.on('data', chunk=>{if(chunk.includes(10))process.exit(0)});process.stdin.on('end',()=>process.exit(0));\n`, { mode: 0o700 });
        guard = await acquireStopGuard({ ...config, repositoryPath: directory, nodePath: process.execPath, phpPath });
        assert.equal(guard.alive(), true);
        await guard.release();
        assert.equal(guard.alive(), false);
        guard = undefined;
        await writeFile(phpPath, `#!${process.execPath}\nprocess.stdout.write(JSON.stringify({ready:false})+'\\n');process.stdin.resume();process.stdin.on('end',()=>process.exit(1));\n`, { mode: 0o700 });
        await assert.rejects(acquireStopGuard({ ...config, repositoryPath: directory, nodePath: process.execPath, phpPath }), /busy/);
    } finally { await guard?.release(); await rm(directory, { recursive: true, force: true }); }
});
