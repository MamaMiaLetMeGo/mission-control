<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\BrowserEvidence;
use App\Services\MissionPreview;
use App\Services\MissionTaskViewData;
use App\Services\MissionWorkflow;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MissionTaskController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $task = MissionTask::query()->create($request->validate([
            'title' => ['required', 'string', 'max:200'],
            'request' => ['required', 'string', 'max:20000'],
            'acceptance_criteria' => ['required', 'string', 'max:10000'],
            'area' => ['required', Rule::in(MissionTask::AREAS)],
            'priority' => ['required', Rule::in(MissionTask::PRIORITIES)],
        ]));

        return to_route('tasks.show', $task)->with('status', 'Task added to the backlog.');
    }

    public function show(MissionTask $task, MissionPreview $previews, MissionTaskViewData $viewData): View
    {
        $task->load(['runs', 'decisions']);
        $preview = null;
        $previewError = null;
        try {
            $preview = $previews->status($task);
        } catch (ValidationException $exception) {
            $previewError = collect($exception->errors())->flatten()->first();
        }

        return view('mission.task', [...$viewData->data($task), 'preview' => $preview, 'previewError' => $previewError]);
    }

    public function progress(Request $request, MissionTask $task, MissionTaskViewData $viewData): JsonResponse
    {
        $data = $request->validate(['run_id' => ['required', 'integer', 'min:1']]);
        $snapshot = DB::transaction(function () use ($task, $data): MissionTask {
            $snapshot = MissionTask::query()->sharedLock()->with(['runs', 'decisions'])->findOrFail($task->id);
            $run = $snapshot->runs->first();
            $expectedRunStatus = ['queued' => 'queued', 'running' => 'running', 'review' => 'completed', 'failed' => 'failed'][$snapshot->status] ?? null;
            $consistent = $run && $run->id === (int) $data['run_id'] && $expectedRunStatus && $run->status === $expectedRunStatus;
            if ($consistent && in_array($snapshot->status, ['queued', 'running'], true)) {
                $consistent = $snapshot->revision === null && $snapshot->review_data === null;
            } elseif ($consistent) {
                $consistent = $snapshot->review_data === $run->result && $snapshot->revision === data_get($run->result, 'revision');
                if ($snapshot->status === 'review') {
                    $consistent = $consistent && is_string($snapshot->revision) && preg_match('/^[a-f0-9]{40,64}$/', $snapshot->revision);
                }
            }
            if (! $consistent) {
                throw new HttpResponseException(response()->json(['message' => 'This task run or review changed. Reload to inspect the current run before continuing.', 'reload' => true], 409));
            }

            return $snapshot;
        });

        return response()->json([
            ...$viewData->progress($snapshot),
            'html' => view('mission.task-content', $viewData->data($snapshot))->render(),
            'checked_at' => now()->toISOString(),
        ])->header('Cache-Control', 'no-store');
    }

    public function run(MissionTask $task, MissionWorkflow $workflow): RedirectResponse
    {
        $workflow->queue($task);

        return to_route('tasks.show', $task)->with('status', 'Task queued. The worker will begin shortly.');
    }

    public function decide(Request $request, MissionTask $task, MissionWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'request_changes', 'defer', 'reject'])],
            'feedback' => ['required_if:action,request_changes,reject', 'nullable', 'string', 'max:10000'],
            'revision' => ['required_if:action,approve', 'nullable', 'string', 'regex:/^[a-f0-9]{40,64}$/'],
        ]);
        $workflow->decide($task, $data['action'], $data['feedback'] ?? null, $data['revision'] ?? null);

        return to_route('tasks.show', $task)->with('status', $data['action'] === 'approve' ? 'Revision approved. Merge is a separate action.' : 'Decision saved.');
    }

    public function merge(Request $request, MissionTask $task, MissionWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/']]);
        $workflow->merge($task, $data['revision']);

        return to_route('tasks.show', $task)->with('status', 'Approved revision merged locally.');
    }

    public function artifact(MissionTask $task, string $file): BinaryFileResponse
    {
        abort_unless(in_array($file, ['diff.patch', 'tests.txt', 'review.json', 'result.json'], true), 404);
        $root = realpath(config('mission.artifact_root'));
        $directory = $task->artifact_path ? realpath($task->artifact_path) : false;
        abort_unless($root && $directory && str_starts_with($directory, $root.DIRECTORY_SEPARATOR), 404);
        $path = realpath($directory.DIRECTORY_SEPARATOR.$file);
        abort_unless($path && is_file($path) && str_starts_with($path, $directory.DIRECTORY_SEPARATOR), 404);

        return response()->download($path, $file, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function screenshot(Request $request, MissionTask $task, string $file, BrowserEvidence $evidence): BinaryFileResponse
    {
        $path = $evidence->screenshotPath($task, $file);
        abort_unless($path, 404);
        $hash = hash_file('sha256', $path);
        $cacheKey = $request->query('sha256');
        abort_if($cacheKey !== null && (! is_string($cacheKey) || ! hash_equals($hash, $cacheKey)), 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cacheKey ? 'private, max-age=31536000, immutable' : 'private, no-cache',
            'ETag' => '"'.$hash.'"',
            'X-Reviewed-Revision' => $task->revision,
        ])->setContentDisposition('inline', $file);
    }
}
