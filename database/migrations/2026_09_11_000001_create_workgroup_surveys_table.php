<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workgroup_surveys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workgroup_id')->constrained()->restrictOnDelete();
            $table->foreignId('workgroup_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_anonymous')->default(false);
            $table->string('eligibility_mode', 30)->default('active_members');
            $table->json('demographic_fields')->nullable();
            $table->unsignedSmallInteger('minimum_subgroup_size')->default(3);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workgroup_id', 'status']);
        });

        Schema::create('workgroup_survey_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('workgroup_surveys')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 30);
            $table->text('prompt');
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->json('configuration');
            $table->timestamps();

            $table->unique(['survey_id', 'position']);
        });

        // This ledger deliberately holds the protected, opaque association between
        // eligibility/completion and de-identified response content. Ordinary
        // response/report/export queries never select this table with answers.
        Schema::create('workgroup_survey_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('workgroup_surveys')->cascadeOnDelete();
            $table->foreignId('workgroup_member_id')->constrained()->restrictOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->boolean('include_in_analysis')->default(true);
            $table->uuid('response_token')->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'workgroup_member_id']);
            $table->index(['survey_id', 'is_eligible', 'include_in_analysis']);
        });

        Schema::create('workgroup_survey_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('workgroup_surveys')->cascadeOnDelete();
            $table->unsignedInteger('survey_revision');
            $table->uuid('participant_token')->unique();
            $table->json('demographics')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'submitted_at']);
        });

        Schema::create('workgroup_survey_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_response_id')->constrained('workgroup_survey_responses')->cascadeOnDelete();
            $table->foreignId('survey_question_id')->constrained('workgroup_survey_questions')->restrictOnDelete();
            $table->json('answer');
            $table->json('question_snapshot');
            $table->timestamps();

            $table->unique(['survey_response_id', 'survey_question_id']);
        });

        Schema::create('workgroup_survey_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('workgroup_surveys')->cascadeOnDelete();
            $table->unsignedInteger('survey_revision');
            $table->unsignedInteger('included_response_count');
            $table->string('analytics_hash', 64);
            $table->json('analytics');
            $table->text('executive_narrative')->nullable();
            $table->string('gateway_request_id')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['survey_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workgroup_survey_reports');
        Schema::dropIfExists('workgroup_survey_answers');
        Schema::dropIfExists('workgroup_survey_responses');
        Schema::dropIfExists('workgroup_survey_participants');
        Schema::dropIfExists('workgroup_survey_questions');
        Schema::dropIfExists('workgroup_surveys');
    }
};

