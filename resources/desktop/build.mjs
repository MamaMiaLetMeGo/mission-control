import { execFileSync } from 'node:child_process';
import { cp, mkdir, readFile, writeFile, rename, symlink, lstat, realpath } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

if (process.platform !== 'darwin') throw new Error('The desktop launcher builds on macOS.');
const source = dirname(fileURLToPath(import.meta.url));
const repositoryPath = await realpath(resolve(source, '../..'));
const output = join(repositoryPath, 'storage/app/desktop');
const appName = 'Mission Control.app';
const bundle = join(output, appName);
const contents = join(bundle, 'Contents');
const resources = join(contents, 'Resources');
const executable = join(contents, 'MacOS/MissionControl');
const run = (command, args) => execFileSync(command, args, { cwd: repositoryPath, stdio: ['ignore', 'pipe', 'pipe'] });
const phpPath = run('/usr/bin/which', ['php']).toString().trim();
await mkdir(resources, { recursive: true });
await mkdir(dirname(executable), { recursive: true });
run('/usr/bin/swiftc', ['-swift-version', '5', '-O', '-target', `${process.arch === 'arm64' ? 'arm64' : 'x86_64'}-apple-macosx13.0`, join(source, 'MissionControl.swift'), '-framework', 'AppKit', '-o', executable]);
await cp(join(source, 'runtime.mjs'), join(resources, 'runtime.mjs'));
await writeFile(join(resources, 'launcher.json'), JSON.stringify({ repositoryPath, nodePath: process.execPath, phpPath }, null, 2), { mode: 0o600 });
await writeFile(join(contents, 'Info.plist'), `<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>
<key>CFBundleIdentifier</key><string>com.mission-control.desktop</string>
<key>CFBundleName</key><string>Mission Control</string><key>CFBundleDisplayName</key><string>Mission Control</string>
<key>CFBundleExecutable</key><string>MissionControl</string><key>CFBundleIconFile</key><string>MissionControl</string>
<key>CFBundlePackageType</key><string>APPL</string><key>CFBundleVersion</key><string>1</string><key>CFBundleShortVersionString</key><string>1.0</string>
<key>LSMinimumSystemVersion</key><string>13.0</string><key>NSHighResolutionCapable</key><true/><key>NSPrincipalClass</key><string>NSApplication</string>
</dict></plist>`);
const iconset = join(output, 'MissionControl.iconset');
await mkdir(iconset, { recursive: true });
for (const size of [16, 32, 128, 256, 512]) {
    run(executable, ['--render-icon', join(iconset, `icon_${size}x${size}.png`), String(size)]);
    run(executable, ['--render-icon', join(iconset, `icon_${size}x${size}@2x.png`), String(size * 2)]);
}
run('/usr/bin/iconutil', ['-c', 'icns', iconset, '-o', join(resources, 'MissionControl.icns')]);
run('/usr/bin/codesign', ['--force', '--sign', '-', bundle]);
run('/usr/bin/codesign', ['--verify', '--strict', bundle]);
if (process.argv.includes('--install')) {
    const destination = join(homedir(), 'Applications', appName);
    await mkdir(dirname(destination), { recursive: true });
    try {
        await lstat(destination);
        const existing = JSON.parse(await readFile(join(destination, 'Contents/Resources/launcher.json'), 'utf8'));
        if (existing.repositoryPath !== repositoryPath) throw new Error('A different desktop app already exists at the installation path.');
        const backup = `${destination}.previous`;
        try { await lstat(backup); throw new Error('A previous app backup already exists. Review it before reinstalling.'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
        await rename(destination, backup);
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
    await cp(bundle, destination, { recursive: true, errorOnExist: true, force: false });
    const shortcut = join(homedir(), 'Desktop', appName);
    let shortcutMessage = `Desktop shortcut: ${shortcut}`;
    try { await symlink(destination, shortcut); }
    catch (error) {
        if (error.code !== 'EEXIST') throw error;
        if (await realpath(shortcut).catch(() => '') !== destination) shortcutMessage = `Existing Desktop item preserved: ${shortcut}`;
    }
    console.log(`Installed: ${destination}\n${shortcutMessage}`);
} else console.log(`Built: ${bundle}`);
