<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Models\Preference;
use Closure;
use Illuminate\Support\Collection;
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
            'reviewRounds' => ($settings['mode'] ?? null) === 'refresh' ? 0 : max(0, min(3, (int) ($settings['review_rounds'] ?? 0))),
        ];
        if ($task->lead) {
            $payload['lead'] = ['name' => $task->lead->name, 'instructions' => $task->lead->instructions];
        }
        if ($task->goal && ($settings['mode'] ?? null) !== 'refresh') {
            $goal = mb_substr(str_replace("\0", '', (string) $task->goal->request), 0, 5000);
            $clean = fn (mixed $value, int $max): string => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '', 0, $max);
            if (trim($goal) !== '') {
                $payload['goal'] = $goal;
            }
            $payload['team'] = $task->goal->tasks()->with('lead')->whereKeyNot($task->id)->limit(12)->get()->map(fn (MissionTask $teammate): array => [
                'title' => $clean($teammate->title, 200),
                'lead' => $clean($teammate->lead?->name ?? 'Unassigned', 100),
                'status' => $clean($teammate->status, 30),
                'summary' => $clean($teammate->recommendation, 600),
                'files' => array_slice(array_values(array_filter(
                    (array) ($teammate->review_data['diff']['files'] ?? []),
                    fn (mixed $file): bool => is_string($file) && $file !== '' && mb_strlen($file) <= 300 && preg_match('/[\x00-\x1F\x7F]/u', $file) === 0,
                )), 0, 30),
            ])->all();
        }
        if (! empty($settings['model'])) {
            $payload['model'] = $settings['model'];
        }
        if (($settings['mode'] ?? null) === 'refresh') {
            $payload['mode'] = 'refresh';
            $payload['sourceRevision'] = $settings['source_revision'] ?? null;
            $payload['previousSummary'] = $settings['previous_summary'] ?? '';
        }

        return $this->stream($payload, $settings['max_runtime_seconds'], 'result', $onEvent, 'The worker stopped without a review package. ');
    }

    /**
     * Run the planning agent for a goal and return its final `plan` event.
     *
     * @param  Closure(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    public function plan(Goal $goal, Closure $onEvent): array
    {
        $settings = $goal->settings;
        $payload = [
            'mode' => 'plan',
            'goalId' => $goal->id,
            'goal' => $goal->request,
            'leads' => Lead::query()->active()->orderBy('id')->limit(20)->get()->map(fn (Lead $lead): array => ['key' => $lead->key, 'name' => $lead->name, 'instructions' => $lead->instructions])->all(),
            'repositoryPath' => $settings['repository_path'],
            'baseBranch' => $settings['base_branch'],
            'worktreeRoot' => config('mission.worktree_root'),
            'maxRuntimeSeconds' => $settings['max_runtime_seconds'],
            'maxTokens' => $settings['max_tokens'],
        ];
        if (! empty($settings['model'])) {
            $payload['model'] = $settings['model'];
        }

        return $this->stream($payload, $settings['max_runtime_seconds'], 'plan', $onEvent, 'The planner stopped without a plan. ');
    }

    /**
     * Run the Brain agent on a learning run's decisions and return its final `learn` event. Every text is cleaned to the worker's limits first.
     *
     * @param  Closure(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    public function learn(BrainRun $run, Closure $onEvent): array
    {
        $settings = $run->settings;
        $line = fn (mixed $value, int $max): string => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', ' ', (string) $value) ?? '', 0, $max));
        $leads = Lead::query()->orderBy('position')->orderBy('id')->limit(20)->get()
            ->map(fn (Lead $lead): array => ['id' => $lead->id, 'key' => $line($lead->key, 50), 'name' => $line($lead->name, 100)])
            ->filter(fn (array $lead): bool => $lead['key'] !== '' && $lead['name'] !== '')
            ->unique('key')->keyBy('id');
        $leadKey = fn (?int $leadId): ?string => $leads->get($leadId)['key'] ?? null;
        $preferences = fn (string $source): Collection => Preference::query()->active()->where('source', $source)->orderBy('id')->get()
            ->map(fn (Preference $preference): array => ['id' => $preference->id, 'text' => $line($preference->text, 300), 'lead' => $leadKey($preference->lead_id)])
            ->filter(fn (array $preference): bool => $preference['text'] !== '')
            ->take(Preference::MAX_ACTIVE)->values();
        $decisions = Decision::query()->whereIn('id', (array) $run->decision_ids)->whereIn('action', Decision::HUMAN_ACTIONS)->with('task')->orderBy('id')->get()
            ->map(fn (Decision $decision): array => [
                'id' => $decision->id,
                'action' => $decision->action,
                'feedback' => $line(str_replace("\0", '', (string) $decision->feedback), 10000),
                'task' => $line($decision->task?->title, 200) ?: 'Untitled task',
                'lead' => $leadKey($decision->task?->lead_id),
                'summary' => $line($decision->task?->recommendation, 600),
            ])
            ->filter(fn (array $decision): bool => $decision['feedback'] !== '')
            ->values()->all();
        $payload = [
            'mode' => 'learn',
            'runId' => $run->id,
            'worktreeRoot' => config('mission.worktree_root'),
            'maxRuntimeSeconds' => $settings['max_runtime_seconds'],
            'maxTokens' => $settings['max_tokens'],
            'leads' => $leads->map(fn (array $lead): array => ['key' => $lead['key'], 'name' => $lead['name']])->values()->all(),
            'preferences' => $preferences('learned')->all(),
            'fixed' => $preferences('manual')->pluck('text')->all(),
            'decisions' => $decisions,
        ];
        if (! empty($settings['model'])) {
            $payload['model'] = $settings['model'];
        }

        return $this->stream($payload, $settings['max_runtime_seconds'], 'learn', $onEvent, 'The Brain stopped without a result. ');
    }

    /**
     * Run the worker with the payload, pass every event except the final one to $onEvent and return the final event.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    private function stream(array $payload, int $maxRuntimeSeconds, string $finalType, Closure $onEvent, string $missingMessage): array
    {
        $process = new Process([config('mission.node_binary'), config('mission.worker_path')], base_path(), ['OPENAI_API_KEY' => config('mission.openai_api_key')]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($maxRuntimeSeconds + 30);
        $buffer = '';
        $stderr = '';
        $result = null;
        $consume = function (string $line) use (&$result, $onEvent, $finalType): void {
            $event = json_decode($this->redact($line), true);
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                return;
            }
            if ($event['type'] === $finalType) {
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
            throw new RuntimeException($missingMessage.$this->redact($stderr));
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
