<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds rubric result fields to evaluation_submissions for the universal rubric system.
     * These fields store pre-calculated SAVER category scores and decision metadata.
     */
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            // Rubric versioning and profile
            $table->string('rubric_version', 50)->nullable()->after('status');
            $table->string('assessment_profile', 50)->nullable()->after('rubric_version');
            
            // Pre-calculated SAVER category scores
            $table->decimal('overall_score', 5, 2)->nullable()->after('assessment_profile');
            $table->decimal('capability_score', 5, 2)->nullable()->after('overall_score');
            $table->decimal('usability_score', 5, 2)->nullable()->after('capability_score');
            $table->decimal('affordability_score', 5, 2)->nullable()->after('usability_score');
            $table->decimal('maintainability_score', 5, 2)->nullable()->after('affordability_score');
            $table->decimal('deployability_score', 5, 2)->nullable()->after('maintainability_score');
            
            // Decision metadata
            $table->enum('advance_recommendation', ['yes', 'maybe', 'no'])->nullable()->after('deployability_score');
            $table->enum('confidence_level', ['low', 'medium', 'high'])->nullable()->after('advance_recommendation');
            $table->boolean('has_deal_breaker')->default(false)->after('confidence_level');
            $table->text('deal_breaker_note')->nullable()->after('has_deal_breaker');
            
            // JSON payloads for detailed data
            $table->jsonb('criterion_payload')->nullable()->after('deal_breaker_note');
            $table->jsonb('narrative_payload')->nullable()->after('criterion_payload');
            
            // Add index for faster queries
            $table->index('overall_score');
            $table->index('advance_recommendation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->dropIndex(['overall_score', 'advance_recommendation']);
            
            $table->dropColumn([
                'rubric_version',
                'assessment_profile',
                'overall_score',
                'capability_score',
                'usability_score',
                'affordability_score',
                'maintainability_score',
                'deployability_score',
                'advance_recommendation',
                'confidence_level',
                'has_deal_breaker',
                'deal_breaker_note',
                'criterion_payload',
                'narrative_payload',
            ]);
        });
    }
};
