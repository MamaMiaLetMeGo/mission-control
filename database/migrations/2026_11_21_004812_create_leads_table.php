<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->text('instructions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('leads')->insert([
            ['key' => 'product', 'name' => 'Product Lead', 'instructions' => 'You own what gets built and why. Clarify the user problem, shape the scope and acceptance criteria, and keep copy and content clear and on-message.', 'position' => 1],
            ['key' => 'engineering', 'name' => 'Engineering Lead', 'instructions' => 'You own how it gets built. Implement changes correctly and simply, follow the existing code conventions, and prove the work with focused tests.', 'position' => 2],
            ['key' => 'design', 'name' => 'Design Lead', 'instructions' => 'You own how it looks and feels. Keep the interface consistent, accessible, and responsive, and reuse existing components and styles before inventing new ones.', 'position' => 3],
            ['key' => 'operations', 'name' => 'Operations Lead', 'instructions' => 'You own how it runs and ships. Keep deployments, configuration, and data migrations safe and reversible, and watch for anything that could break a live install.', 'position' => 4],
        ]);
        DB::table('leads')->update(['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
        });

        $leadIds = DB::table('leads')->pluck('id', 'key');
        $areaToLead = ['engineering' => 'engineering', 'quality' => 'engineering', 'content' => 'product', 'growth' => 'product'];

        foreach ($areaToLead as $area => $leadKey) {
            DB::table('mission_tasks')->where('area', $area)->update(['lead_id' => $leadIds[$leadKey]]);
        }

        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }

    public function down(): void
    {
        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->string('area')->default('engineering');
        });

        Schema::table('mission_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });

        Schema::dropIfExists('leads');
    }
};
