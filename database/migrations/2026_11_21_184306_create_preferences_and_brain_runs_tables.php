<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->string('text', 300);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('source')->default('manual');
            $table->json('evidence')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('brain_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('queued');
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->json('usage')->nullable();
            $table->json('events')->nullable();
            $table->json('settings');
            $table->json('decision_ids');
            $table->unsignedBigInteger('last_decision_id');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_runs');
        Schema::dropIfExists('preferences');
    }
};
