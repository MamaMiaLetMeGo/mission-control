<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\MissionPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MissionPreviewController extends Controller
{
    public function store(Request $request, MissionTask $task, MissionPreview $previews): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/']]);
        $preview = $previews->start($task, $data['revision']);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Preview started for this revision.', 'preview' => $preview]);
        }

        return to_route('tasks.show', $task)->with('status', 'Preview started for this revision.');
    }

    public function destroy(Request $request, MissionTask $task, MissionPreview $previews): RedirectResponse|JsonResponse
    {
        Cache::lock('mission-workflow', 60)->block(5, function () use ($task, $previews): void {
            $task->refresh();
            if (in_array($task->status, ['queued', 'running'], true)) {
                throw ValidationException::withMessages(['preview' => 'Wait for the active run to finish before stopping its preview.']);
            }
            $previews->stop($task);
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Preview stopped.', 'preview' => null]);
        }

        return to_route('tasks.show', $task)->with('status', 'Preview stopped.');
    }

    public function status(MissionTask $task, MissionPreview $previews): JsonResponse
    {
        try {
            return response()->json(['preview' => $previews->status($task)])->header('Cache-Control', 'no-store');
        } catch (ValidationException $exception) {
            return response()->json(['preview' => null, 'error' => collect($exception->errors())->flatten()->first()], 503)->header('Cache-Control', 'no-store');
        }
    }
}
