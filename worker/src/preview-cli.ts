import { startPreview, statusPreview, stopPreview, type PreviewRequest, type PreviewSelector } from './preview.js';
import { redact } from './validation.js';

const controller = new AbortController();
process.once('SIGTERM', () => controller.abort(new Error('Preview setup was stopped.')));
process.once('SIGINT', () => controller.abort(new Error('Preview setup was interrupted.')));

try {
  process.stdin.setEncoding('utf8');
  let input = '';
  for await (const chunk of process.stdin) {
    input += chunk;
    if (input.length > 1024 * 1024) throw new Error('The preview request exceeds 1 MB.');
  }
  const request = JSON.parse(input);
  const mode = process.argv[2];
  let preview = null;
  if (mode === 'start') preview = await startPreview(request as PreviewRequest, controller.signal);
  else if (mode === 'status') preview = await statusPreview(request as PreviewSelector);
  else if (mode === 'stop') await stopPreview(request as PreviewSelector);
  else throw new Error('Expected preview command start, status, or stop.');
  process.stdout.write(JSON.stringify({ success: true, preview }) + '\n');
} catch (error) {
  process.stdout.write(JSON.stringify({ success: false, error: redact(error instanceof Error ? error.message : String(error)) }) + '\n');
  process.exitCode = 1;
}
