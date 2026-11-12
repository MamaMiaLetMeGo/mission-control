<?php

namespace App\Http\Controllers;

use App\Services\RepositorySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RepositorySyncController extends Controller
{
    public function status(RepositorySync $repository): JsonResponse
    {
        return response()->json(['repository' => $repository->status()])->header('Cache-Control', 'no-store');
    }

    public function check(Request $request, RepositorySync $repository): JsonResponse|RedirectResponse
    {
        $status = $repository->check();

        return $this->response($request, $status, 'Remote checked. Your local checkout was not changed.');
    }

    public function sync(Request $request, RepositorySync $repository): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'expected_repository' => ['required', 'string', 'max:4096'],
            'expected_base' => ['required', 'string', 'max:200'],
            'expected_head' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/'],
            'expected_remote' => ['required', 'string', 'regex:/^[a-f0-9]{40,64}$/'],
        ]);
        $status = $repository->sync($data);

        return $this->response($request, $status, 'Local checkout fast-forwarded to the checked remote revision.');
    }

    /** @param array<string, mixed> $status */
    private function response(Request $request, array $status, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'repository' => $status])->header('Cache-Control', 'no-store');
        }

        return back()->with('status', $message);
    }
}
