<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\ProductionPublication;
use App\Services\PublicationPolling;
use App\Services\StagingPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicationPollingController extends Controller
{
    public function __invoke(Request $request, MissionTask $task, PublicationPolling $polling, StagingPublication $staging, ProductionPublication $production): JsonResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(['staging', 'production'])],
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/'],
            'plan_hash' => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $result = $polling->refresh($task, $data['environment'], $data['revision'], $data['plan_hash']);
        $task->load(['runs', 'decisions']);
        $publication = $staging->status($task);
        $productionPublication = $production->status($task);
        $showProduction = $publication['state'] === 'merged' || $task->production_publication;

        return response()->json([
            ...$result,
            'revision' => $task->revision,
            'plan_hash' => data_get($task->production_publication, 'plan.hash'),
            'panels' => [
                'staging' => view('mission.staging-publication', compact('task', 'publication'))->render(),
                'production' => $showProduction ? view('mission.production-publication', compact('task', 'productionPublication'))->render() : null,
            ],
            'polling' => $polling->metadata($task),
        ])->header('Cache-Control', 'no-store');
    }
}
