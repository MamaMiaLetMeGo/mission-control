<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->text('request');
            $table->string('status')->default('queued');
            $table->text('overview')->nullable();
            $table->text('error')->nullable();
            $table->json('usage')->nullable();
            $table->json('events')->nullable();
            $table->json('settings');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('goal_position')->nullable();
            $table->json('depends_on')->nullable();
            $table->string('signal', 10)->nullable();
            $table->string('signal_reason')->nullable();
            $table->index('signal');
        });
    }

    public function down(): void
    {
        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->dropIndex(['signal']);
            $table->dropConstrainedForeignId('goal_id');
            $table->dropColumn(['goal_position', 'depends_on', 'signal', 'signal_reason']);
        });

        Schema::dropIfExists('goals');
    }
};
