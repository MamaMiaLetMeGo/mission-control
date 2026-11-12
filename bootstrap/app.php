<?php

use App\Http\Middleware\LocalAccessOnly;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LocalAccessOnly::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport([LockTimeoutException::class]);
        $exceptions->render(function (LockTimeoutException $exception, Request $request): JsonResponse|RedirectResponse {
            $message = 'Another Mission Control operation is in progress. Wait a moment, then try again.';
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['busy' => true, 'message' => $message], 409)
                    ->header('Cache-Control', 'no-store')
                    ->header('Retry-After', '3');
            }

            return back()->withErrors(['workflow' => $message])->withInput($request->only([
                'title', 'request', 'acceptance_criteria', 'area', 'priority', 'action', 'feedback',
                'revision', 'plan_hash', 'scope', 'additional_paths', 'evidence_file', 'pr_number', 'head_revision',
                'repository_path', 'base_branch', 'model', 'test_command', 'max_runtime_seconds', 'max_tokens', 'daily_run_limit',
                'preview_enabled', 'preview_driver', 'preview_directory', 'preview_command', 'preview_paths', 'preview_ttl_seconds',
                'expected_repository', 'expected_base', 'expected_head', 'expected_remote',
            ]));
        });
    })->create();
