import { Codex } from '@openai/codex-sdk';
import path from 'node:path';
import { safeEnvironment } from './process.js';
import { emptyUsage, type AgentRunner, type Emit } from './types.js';

export function createCodexAgent(apiKey: string, runtimeHome: string, emit: Emit): AgentRunner {
  if (!apiKey.trim()) {
    throw new Error('OPENAI_API_KEY is missing. Add it to the app environment before running a task.');
  }
  const environment = safeEnvironment(runtimeHome);
  environment.CODEX_HOME = path.join(runtimeHome, '.codex');
  const codex = new Codex({
    apiKey,
    env: environment,
    config: {
      shell_environment_policy: {
        inherit: 'none',
        set: environment,
      },
    },
  });
  return async (request) => {
    const thread = codex.startThread({
      workingDirectory: request.workingDirectory,
      sandboxMode: request.role === 'review' ? 'read-only' : 'workspace-write',
      approvalPolicy: 'never',
      networkAccessEnabled: false,
      webSearchMode: 'disabled',
      threadSource: 'mission-control',
      ...(request.model?.trim() ? { model: request.model.trim() } : {}),
    });
    const input = request.images?.length
      ? [{ type: 'text' as const, text: request.prompt }, ...request.images.map((image) => ({ type: 'local_image' as const, path: image }))]
      : request.prompt;
    const { events } = await thread.runStreamed(input, {
      signal: request.signal,
      ...(request.outputSchema ? { outputSchema: request.outputSchema } : {}),
    });
    let response = '';
    let usage = emptyUsage();
    let completed = false;
    for await (const event of events) {
      if (event.type === 'thread.started') {
        emit({ type: 'thread', role: request.role, threadId: event.thread_id });
      } else if (event.type === 'turn.completed') {
        completed = true;
        usage = { ...usage, ...event.usage };
        emit({ type: 'usage', role: request.role, usage });
      } else if (event.type === 'turn.failed') {
        throw new Error(event.error.message);
      } else if (event.type === 'error') {
        throw new Error(event.message);
      } else if (event.type === 'item.completed' && event.item.type === 'agent_message') {
        response = event.item.text;
      } else if (event.type === 'item.completed' && event.item.type === 'file_change') {
        emit({ type: 'message', role: request.role, message: `${event.item.changes.length} file change(s) ${event.item.status}.` });
      } else if (event.type === 'item.completed' && event.item.type === 'command_execution') {
        emit({ type: 'message', role: request.role, message: `Agent command ${event.item.status}${event.item.exit_code === undefined ? '' : ` (exit ${event.item.exit_code})`}.` });
      }
    }
    if (!completed || !response.trim()) {
      throw new Error('Codex exited without a completed response.');
    }
    return { response, usage };
  };
}
