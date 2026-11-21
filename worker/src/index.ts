import { runLearning, validateLearnInput } from './brain.js';
import { checkConnectivity } from './check.js';
import { runPlanning, validatePlanInput } from './planner.js';
import { initialResult, runMission } from './runner.js';
import { emptyUsage } from './types.js';
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
  let planning = false;
  let learning = false;
  try {
    let input = '';
    process.stdin.setEncoding('utf8');
    for await (const chunk of process.stdin) {
      input += chunk;
      if (input.length > 1024 * 1024) {
        throw new Error('The task input exceeds 1 MB.');
      }
    }
    const parsed = JSON.parse(input);
    planning = parsed?.mode === 'plan';
    learning = parsed?.mode === 'learn';
    const result = learning
      ? await runLearning(validateLearnInput(parsed), { apiKey, signal: controller.signal, emit: write })
      : planning
        ? await runPlanning(validatePlanInput(parsed), { apiKey, signal: controller.signal, emit: write })
        : await runMission(validateInput(parsed), { apiKey, signal: controller.signal, emit: write });
    write(result);
    process.exitCode = result.success ? 0 : 1;
  } catch (error) {
    const message = redact(error instanceof Error ? error.message : String(error), apiKey);
    write(learning
      ? { type: 'learn', success: false, summary: '', changes: [], usage: emptyUsage(), error: message }
      : planning
        ? { type: 'plan', success: false, overview: '', briefs: [], usage: emptyUsage(), error: message }
        : { ...initialResult(), error: message });
    process.exitCode = 1;
  }
}
