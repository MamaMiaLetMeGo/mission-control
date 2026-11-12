<?php

namespace App\Services;

use App\Models\AgentRun;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

class CodexProcess
{
    public function __construct(private MissionSettings $missionSettings) {}

    /** @param Closure(array<string, mixed>): void $onEvent
     * @return array<string, mixed>
     */
    public function run(AgentRun $run, Closure $onEvent): array
    {
        $task = $run->task;
        $settings = $run->settings;
        $feedback = $task->decisions()->whereNotNull('feedback')->latest('id')->limit(5)->pluck('feedback')->reverse()->implode("\n- ");
        $previousReview = $settings['previous_review'] ?? null;
        $context = is_array($previousReview) ? "\n\nPrevious attempt: revision ".($previousReview['revision'] ?? 'unavailable')."\nSummary: ".($previousReview['summary'] ?? '')."\nApply the human corrections below to the current task." : '';
        $payload = [
            'taskId' => $task->id,
            'title' => $task->title,
            'request' => $task->request.$context.($feedback ? "\n\nPrevious human feedback for this task:\n- ".$feedback : ''),
            'acceptanceCriteria' => $task->acceptance_criteria,
            'repositoryPath' => $settings['repository_path'],
            'baseBranch' => $settings['base_branch'],
            'testCommand' => $settings['test_command'],
            'maxRuntimeSeconds' => $settings['max_runtime_seconds'],
            'maxTokens' => $settings['max_tokens'],
            'worktreeRoot' => config('mission.worktree_root'),
            'artifactRoot' => config('mission.artifact_root'),
            'preview' => $this->missionSettings->previewConfiguration($settings),
            'previewRoot' => config('mission.preview_root'),
            'previewDatabase' => config('mission.preview_database'),
        ];
        if (! empty($settings['model'])) {
            $payload['model'] = $settings['model'];
        }
        $process = new Process([config('mission.node_binary'), config('mission.worker_path')], base_path(), ['OPENAI_API_KEY' => config('mission.openai_api_key')]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($settings['max_runtime_seconds'] + 30);
        $buffer = '';
        $stderr = '';
        $result = null;
        $consume = function (string $line) use (&$result, $onEvent): void {
            $event = json_decode($this->redact($line), true);
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                return;
            }
            if ($event['type'] === 'result') {
                $result = $event;
            } else {
                $onEvent($event);
            }
        };
        $process->run(function (string $type, string $chunk) use (&$buffer, &$stderr, $consume, $process): void {
            if ($type === Process::ERR) {
                $stderr = mb_substr($stderr.$chunk, -4000);
                $process->clearErrorOutput();

                return;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 2_000_000) {
                throw new RuntimeException('The worker exceeded the event size limit.');
            }
            while (($position = strpos($buffer, "\n")) !== false) {
                $consume(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);
            }
            $process->clearOutput();
        });
        if (trim($buffer) !== '') {
            $consume($buffer);
        }
        if (! is_array($result)) {
            throw new RuntimeException('The worker stopped without a review package. '.$this->redact($stderr));
        }
        if (! $process->isSuccessful()) {
            $result['success'] = false;
            $result['error'] = $result['error'] ?? 'The worker exited with an error. '.$this->redact($stderr);
        }

        return $result;
    }

    public function redact(string $text): string
    {
        $key = config('mission.openai_api_key');
        if (is_string($key) && $key !== '') {
            $text = str_replace($key, '[redacted]', $text);
        }

        return preg_replace('/\bsk-(?:proj-)?[A-Za-z0-9_-]{12,}/', '[redacted]', $text) ?? '[redacted output]';
    }
}
