import { checkConnectivity } from './check.js';
import { initialResult, runMission } from './runner.js';
import { redact, validateInput } from './validation.js';

const apiKey = process.env.OPENAI_API_KEY || '';
const write = (value: unknown) => process.stdout.write(redact(JSON.stringify(value), apiKey) + '\n');
const controller = new AbortController();
process.once('SIGTERM', () => controller.abort(new Error('The worker was stopped.')));
process.once('SIGINT', () => controller.abort(new Error('The worker was interrupted.')));

if (process.argv.includes('--check')) {
  const result = await checkConnectivity(apiKey, process.env.MISSION_MODEL);
  write(result);
  process.exitCode = result.success ? 0 : 1;
} else {
  try {
    let input = '';
    process.stdin.setEncoding('utf8');
    for await (const chunk of process.stdin) {
      input += chunk;
      if (input.length > 1024 * 1024) {
        throw new Error('The task input exceeds 1 MB.');
      }
    }
    const result = await runMission(validateInput(JSON.parse(input)), { apiKey, signal: controller.signal, emit: write });
    write(result);
    process.exitCode = result.success ? 0 : 1;
  } catch (error) {
    write({ ...initialResult(), error: redact(error instanceof Error ? error.message : String(error), apiKey) });
    process.exitCode = 1;
  }
}
