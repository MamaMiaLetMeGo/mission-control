<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesktopHealthController;
use App\Http\Controllers\MissionPreviewController;
use App\Http\Controllers\MissionSettingController;
use App\Http\Controllers\MissionTaskController;
use App\Http\Controllers\ProductionPublicationController;
use App\Http\Controllers\PublicationPollingController;
use App\Http\Controllers\RepositorySyncController;
use App\Http\Controllers\StagingPublicationController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/desktop/health', DesktopHealthController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('desktop.health');
Route::get('/queue/status', [DashboardController::class, 'status'])->name('queue.status');
Route::post('/tasks', [MissionTaskController::class, 'store'])->name('tasks.store');
Route::get('/tasks/{task}', [MissionTaskController::class, 'show'])->name('tasks.show');
Route::get('/tasks/{task}/progress', [MissionTaskController::class, 'progress'])->name('tasks.progress');
Route::get('/tasks/{task}/artifacts/{file}', [MissionTaskController::class, 'artifact'])->name('tasks.artifact');
Route::get('/tasks/{task}/screenshots/{file}', [MissionTaskController::class, 'screenshot'])->name('tasks.screenshot');
Route::post('/tasks/{task}/preview', [MissionPreviewController::class, 'store'])->name('tasks.preview.start');
Route::post('/tasks/{task}/preview/stop', [MissionPreviewController::class, 'destroy'])->name('tasks.preview.stop');
Route::get('/tasks/{task}/preview/status', [MissionPreviewController::class, 'status'])->name('tasks.preview.status');
Route::post('/tasks/{task}/run', [MissionTaskController::class, 'run'])->name('tasks.run');
Route::post('/tasks/{task}/decisions', [MissionTaskController::class, 'decide'])->name('tasks.decide');
Route::post('/tasks/{task}/merge', [MissionTaskController::class, 'merge'])->name('tasks.merge');
Route::post('/tasks/{task}/publication', [StagingPublicationController::class, 'publish'])->name('tasks.publish');
Route::post('/tasks/{task}/publication/refresh', [StagingPublicationController::class, 'refresh'])->name('tasks.publication.refresh');
Route::post('/tasks/{task}/publication/merge', [StagingPublicationController::class, 'merge'])->name('tasks.publication.merge');
Route::post('/tasks/{task}/publications/poll', PublicationPollingController::class)->name('tasks.publications.poll');
Route::post('/tasks/{task}/production/plan', [ProductionPublicationController::class, 'plan'])->name('tasks.production.plan');
Route::post('/tasks/{task}/production/publish', [ProductionPublicationController::class, 'publish'])->name('tasks.production.publish');
Route::post('/tasks/{task}/production/verify', [ProductionPublicationController::class, 'verify'])->name('tasks.production.verify');
Route::post('/tasks/{task}/production/ready', [ProductionPublicationController::class, 'ready'])->name('tasks.production.ready');
Route::post('/tasks/{task}/production/refresh', [ProductionPublicationController::class, 'refresh'])->name('tasks.production.refresh');
Route::post('/tasks/{task}/production/merge', [ProductionPublicationController::class, 'merge'])->name('tasks.production.merge');
Route::post('/tasks/{task}/production/close-conflict', [ProductionPublicationController::class, 'closeConflict'])->name('tasks.production.close-conflict');
Route::get('/tasks/{task}/production/diff', [ProductionPublicationController::class, 'diff'])->name('tasks.production.diff');
Route::get('/settings', [MissionSettingController::class, 'edit'])->name('settings.edit');
Route::post('/settings', [MissionSettingController::class, 'update'])->name('settings.update');
Route::get('/repository/status', [RepositorySyncController::class, 'status'])->name('repository.status');
Route::post('/repository/check', [RepositorySyncController::class, 'check'])->name('repository.check');
Route::post('/repository/sync', [RepositorySyncController::class, 'sync'])->name('repository.sync');
Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
