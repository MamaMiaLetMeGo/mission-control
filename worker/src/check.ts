import { mkdtemp, mkdir, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { createCodexAgent } from './agents.js';
import { git, safeEnvironment } from './process.js';
import { redact } from './validation.js';

export async function checkConnectivity(apiKey: string, model?: string): Promise<Record<string, unknown>> {
  if (!apiKey.trim()) {
    return { type: 'check', success: false, error: 'OPENAI_API_KEY is missing.' };
  }
  const directory = await mkdtemp(path.join(os.tmpdir(), 'mission-control-check-'));
  try {
    const repositoryPath = path.join(directory, 'repository');
    const runtimeHome = path.join(directory, 'home');
    await mkdir(repositoryPath);
    await mkdir(path.join(runtimeHome, '.codex'), { recursive: true, mode: 0o700 });
    const signal = AbortSignal.timeout(60000);
    await git(['init', '-b', 'main'], repositoryPath, safeEnvironment(runtimeHome), signal);
    const agent = createCodexAgent(apiKey, runtimeHome, () => {});
    const result = await agent({
      role: 'review', workingDirectory: repositoryPath, model, signal,
      prompt: 'This is a connection check. Do not use any tools, access files, run commands, or change anything. Reply with exactly MISSION_CONTROL_OK and nothing else.',
    });
    return {
      type: 'check',
      success: result.response.trim() === 'MISSION_CONTROL_OK',
      usage: result.usage,
      ...(result.response.trim() === 'MISSION_CONTROL_OK' ? {} : { error: 'Codex connected but returned an unexpected check response.' }),
    };
  } catch (error) {
    return { type: 'check', success: false, error: redact(error instanceof Error ? error.message : String(error), apiKey) };
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}
