<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('request');
            $table->text('acceptance_criteria');
            $table->string('area')->default('engineering');
            $table->string('priority')->default('normal');
            $table->string('status')->default('backlog');
            $table->text('recommendation')->nullable();
            $table->json('review_data')->nullable();
            $table->string('branch')->nullable();
            $table->text('worktree_path')->nullable();
            $table->string('revision', 64)->nullable();
            $table->string('base_revision', 64)->nullable();
            $table->text('artifact_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_task_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('engineering');
            $table->string('status')->default('queued');
            $table->string('thread_id')->nullable();
            $table->json('usage')->nullable();
            $table->json('events')->nullable();
            $table->json('settings');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_task_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->text('feedback')->nullable();
            $table->string('revision', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('mission_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_settings');
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('mission_tasks');
    }
};
