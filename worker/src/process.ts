import { spawn } from 'node:child_process';

export interface CommandResult {
  exitCode: number;
  output: string;
  truncated: boolean;
}

export function safeEnvironment(homeDirectory: string): Record<string, string> {
  const environment: Record<string, string> = {
    HOME: homeDirectory,
    PATH: process.env.PATH || '/usr/local/bin:/usr/bin:/bin',
    LANG: 'en_US.UTF-8',
    GIT_TERMINAL_PROMPT: '0',
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_CONFIG_GLOBAL: process.platform === 'win32' ? 'NUL' : '/dev/null',
  };
  for (const key of ['TMPDIR', 'TEMP', 'TMP', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'PATHEXT']) {
    if (process.env[key]) {
      environment[key] = process.env[key]!;
    }
  }
  return environment;
}

export async function command(
  argv: string[],
  cwd: string,
  environment: Record<string, string>,
  signal?: AbortSignal,
  maxOutput = 65536,
): Promise<CommandResult> {
  signal?.throwIfAborted();
  if (!argv[0]) {
    throw new Error('No executable was configured.');
  }
  return await new Promise<CommandResult>((resolve, reject) => {
    const child = spawn(argv[0]!, argv.slice(1), {
      cwd,
      env: environment,
      shell: false,
      detached: process.platform !== 'win32',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let output = '';
    let truncated = false;
    let killTimer: ReturnType<typeof setTimeout> | undefined;
    const kill = (killSignal: NodeJS.Signals) => {
      try {
        if (process.platform !== 'win32' && child.pid) {
          process.kill(-child.pid, killSignal);
        } else {
          child.kill(killSignal);
        }
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ESRCH') {
          child.kill(killSignal);
        }
      }
    };
    const abort = () => {
      kill('SIGTERM');
      killTimer = setTimeout(() => kill('SIGKILL'), 1000);
      killTimer.unref();
    };
    const collect = (next: string) => {
      const remaining = maxOutput - output.length;
      if (next.length > remaining) {
        truncated = true;
      }
      if (remaining > 0) {
        output += next.slice(0, remaining);
      }
    };
    child.stdout.setEncoding('utf8').on('data', collect);
    child.stderr.setEncoding('utf8').on('data', collect);
    signal?.addEventListener('abort', abort, { once: true });
    child.once('error', (error) => {
      signal?.removeEventListener('abort', abort);
      reject(error);
    });
    child.once('close', (code) => {
      signal?.removeEventListener('abort', abort);
      if (killTimer) {
        clearTimeout(killTimer);
      }
      if (signal?.aborted) {
        reject(signal.reason ?? new Error('The run was interrupted.'));
      } else {
        resolve({ exitCode: code ?? 1, output: output + (truncated ? '\n[Output truncated]\n' : ''), truncated });
      }
    });
    if (signal?.aborted) {
      abort();
    }
  });
}

export async function git(
  arguments_: string[],
  cwd: string,
  environment: Record<string, string>,
  signal?: AbortSignal,
  maxOutput = 65536,
  trimOutput = true,
): Promise<string> {
  const result = await command(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...arguments_], cwd, environment, signal, maxOutput);
  if (result.exitCode !== 0 || result.truncated) {
    throw new Error(`Git ${arguments_[0]} failed: ${result.output.trim() || `exit ${result.exitCode}`}`);
  }
  return trimOutput ? result.output.trim() : result.output;
}
