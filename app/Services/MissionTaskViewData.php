<?php

namespace App\Services;

use App\Models\MissionTask;

class MissionTaskViewData
{
    public function __construct(private MissionSettings $settings, private StagingPublication $staging, private ProductionPublication $production, private PublicationPolling $polling) {}

    /** @return array<string, mixed> */
    public function data(MissionTask $task): array
    {
        return [
            'task' => $task,
            'settings' => $this->settings->all(),
            'readiness' => $this->settings->readiness(),
            'preview' => null,
            'previewError' => null,
            'publication' => $this->staging->status($task),
            'productionPublication' => $this->production->status($task),
            'publicationPolling' => $this->polling->metadata($task),
            'taskProgress' => $this->progress($task),
        ];
    }

    /** @return array{run_id: ?int, status: string, revision: ?string, active: bool, poll_after_ms: int} */
    public function progress(MissionTask $task): array
    {
        return [
            'run_id' => $task->runs->first()?->id,
            'status' => $task->status,
            'revision' => $task->revision,
            'active' => in_array($task->status, ['queued', 'running'], true),
            'poll_after_ms' => 5000,
        ];
    }
}
