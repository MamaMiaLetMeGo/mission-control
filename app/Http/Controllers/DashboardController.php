<?php

namespace App\Http\Controllers;

use App\Models\Decision;
use App\Models\MissionTask;
use App\Services\MissionQueue;
use App\Services\MissionSettings;
use App\Services\RepositorySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MissionSettings $missionSettings, RepositorySync $repository, MissionQueue $queue): View
    {
        return view('mission.dashboard', [...$queue->data($this->filters($request)), 'settings' => $missionSettings->all(), 'readiness' => $missionSettings->readiness(), 'repositoryStatus' => $repository->status()]);
    }

    public function status(Request $request, MissionQueue $queue): JsonResponse
    {
        $data = $queue->data($this->filters($request));

        return response()->json([
            'html' => [
                'stats' => view('mission.queue-stats', $data)->render(),
                'queue' => view('mission.queue-list', $data)->render(),
            ],
            'counts' => $data['counts'],
            'active' => true,
            'poll_after_ms' => $data['counts']['queued'] + $data['counts']['running'] > 0 ? 5000 : 15000,
            'checked_at' => now()->toISOString(),
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(MissionTask::STATUSES)],
            'area' => ['nullable', Rule::in(MissionTask::AREAS)],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    public function profile(MissionSettings $missionSettings): View
    {
        return view('mission.profile', [
            'decisions' => Decision::query()->with('task')->latest('id')->paginate(20),
            'settings' => $missionSettings->all(),
            'readiness' => $missionSettings->readiness(),
        ]);
    }
}
