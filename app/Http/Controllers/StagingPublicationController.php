<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\StagingPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StagingPublicationController extends Controller
{
    public function publish(Request $request, MissionTask $task, StagingPublication $publication): JsonResponse|RedirectResponse
    {
        $state = $publication->publish($task, $this->revision($request));

        return $this->response($request, $task, $state, 'Reviewed revision published as a staging pull request.');
    }

    public function refresh(Request $request, MissionTask $task, StagingPublication $publication): JsonResponse|RedirectResponse
    {
        $state = $publication->refresh($task, $this->revision($request));

        return $this->response($request, $task, $state, 'Staging publication status refreshed.');
    }

    public function merge(Request $request, MissionTask $task, StagingPublication $publication): JsonResponse|RedirectResponse
    {
        $state = $publication->merge($task, $this->revision($request));

        return $this->response($request, $task, $state, $state['state'] === 'merged' ? 'Reviewed pull request merged into staging. Deployment status is tracked separately.' : 'GitHub accepted the merge request. Refresh to verify completion.');
    }

    private function revision(Request $request): string
    {
        return $request->validate(['revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/']])['revision'];
    }

    /** @param array<string, mixed> $publication */
    private function response(Request $request, MissionTask $task, array $publication, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'publication' => $publication])->header('Cache-Control', 'no-store');
        }

        return to_route('tasks.show', $task)->with('status', $message);
    }
}
