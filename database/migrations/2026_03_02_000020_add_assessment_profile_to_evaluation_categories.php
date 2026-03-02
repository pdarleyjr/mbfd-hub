<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds assessment profile support for universal rubric and optional evaluator instructions.
     */
    public function up(): void
    {
        Schema::table('evaluation_categories', function (Blueprint $table) {
            $table->enum('assessment_profile', [
                'generic_apparatus',
                'powered_tool',
                'hand_tool_forcible', 
                'stabilization_support',
                'water_flow_appliance',
            ])->nullable()->default('generic_apparatus')->after('is_active');
            
            $table->text('instructions_markdown')->nullable()->after('assessment_profile');
            $table->text('score_visibility_notes')->nullable()->after('instructions_markdown');
            $table->integer('finalists_limit')->nullable()->unsigned()->after('score_visibility_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluation_categories', function (Blueprint $table) {
            $table->dropColumn([
                'assessment_profile',
                'instructions_markdown',
                'score_visibility_notes',
                'finalists_limit',
            ]);
        });
    }
};
