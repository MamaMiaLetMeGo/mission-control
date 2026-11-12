<?php

namespace App\Http\Controllers;

use App\Models\MissionTask;
use App\Services\ProductionPublication;
use App\Services\ProductionReleasePlan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductionPublicationController extends Controller
{
    public function plan(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        if ($request->input('additional_paths') === null) {
            $request->merge(['additional_paths' => []]);
        } elseif (is_string($request->input('additional_paths'))) {
            $request->merge(['additional_paths' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $request->input('additional_paths'))), fn (string $path): bool => $path !== ''))]);
        }
        $data = $request->validate([
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/'],
            'scope' => ['required', Rule::in(['task', 'staging'])],
            'additional_paths' => ['sometimes', 'array', 'list', 'max:40'],
            'additional_paths.*' => ['bail', 'required', 'string', 'max:1000', 'distinct:strict', function (string $attribute, mixed $value, Closure $fail): void {
                if (! ProductionReleasePlan::isPath($value)) {
                    $fail('Prerequisite paths must be explicit relative repository files without traversal.');
                }
            }],
        ]);

        return $this->response($request, $task, $production->plan($task, $data['revision'], $data['scope'], $data['additional_paths'] ?? []), 'Production plan prepared. Review its complete scope before publishing.');
    }

    public function publish(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = $this->pin($request);

        return $this->response($request, $task, $production->publish($task, $data['revision'], $data['plan_hash']), 'Pinned production plan published as a draft PR.');
    }

    public function verify(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = [...$this->pin($request), ...$request->validate(['evidence_file' => ['required', 'string', 'max:4096']])];

        return $this->response($request, $task, $production->verify($task, $data['revision'], $data['plan_hash'], $data['evidence_file']), 'Exact production release checks verified.');
    }

    public function ready(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = $this->pin($request);

        return $this->response($request, $task, $production->ready($task, $data['revision'], $data['plan_hash']), 'Production PR marked ready after local verification.');
    }

    public function refresh(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = $this->pin($request);

        return $this->response($request, $task, $production->refresh($task, $data['revision'], $data['plan_hash']), 'Production publication status refreshed.');
    }

    public function merge(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = $this->pin($request);
        $state = $production->merge($task, $data['revision'], $data['plan_hash']);

        return $this->response($request, $task, $state, $state['state'] === 'merged' ? 'Pinned release merged into production. Deployment status is tracked separately.' : 'GitHub accepted the merge request. Refresh to verify completion.');
    }

    public function closeConflict(Request $request, MissionTask $task, ProductionPublication $production): JsonResponse|RedirectResponse
    {
        $data = [...$this->pin($request), ...$request->validate(['pr_number' => ['required', 'integer', 'min:1'], 'head_revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/']])];

        return $this->response($request, $task, $production->closeConflict($task, $data['revision'], $data['plan_hash'], (int) $data['pr_number'], $data['head_revision']), 'The explicitly selected competing production PR was closed.');
    }

    public function diff(Request $request, MissionTask $task): BinaryFileResponse
    {
        $plan = data_get($task->production_publication, 'plan');
        abort_unless(is_array($plan) && is_string($request->query('plan_hash')) && hash_equals($plan['hash'], $request->query('plan_hash')), 404);
        $root = realpath(config('mission.production_release_root'));
        $directory = realpath($plan['artifact_path']);
        $file = $directory ? realpath($directory.'/diff.patch') : false;
        abort_unless($root && $directory && $file && str_starts_with($directory, $root.DIRECTORY_SEPARATOR) && str_starts_with($file, $directory.DIRECTORY_SEPARATOR) && is_file($file) && hash_equals($plan['diff_sha256'], hash_file('sha256', $file)), 404);

        return response()->download($file, 'production-'.$task->id.'-'.substr($plan['hash'], 0, 12).'.patch', ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function pin(Request $request): array
    {
        return $request->validate(['revision' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/'], 'plan_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
    }

    private function response(Request $request, MissionTask $task, array $production, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'productionPublication' => $production])->header('Cache-Control', 'no-store');
        }

        return to_route('tasks.show', $task)->with('status', $message);
    }
}
