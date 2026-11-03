<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * AI Response automation (docs/ai-response-automation.md). Additive only. The master switch row is created
 * OFF; nothing in this feature acts until an administrator turns it on. The permission row is created and
 * granted to nobody (super_admin passes through the Gate bypass), so the deploy gate sees no grant change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Bumped on every ON/OFF change. Work queued under an older epoch can never post.
            $table->unsignedBigInteger('epoch')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('ai_automation_switch_events', function (Blueprint $table): void {
            $table->id();
            $table->boolean('from_enabled');
            $table->boolean('to_enabled');
            $table->unsignedBigInteger('epoch');
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160);
            $table->string('source', 24); // admin | console
            $table->timestampTz('created_at');
        });

        Schema::create('ai_backlog_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('state', 16); // previewed | running | paused | completed | cancelled
            $table->unsignedBigInteger('epoch')->nullable();
            $table->json('preview')->nullable();
            $table->json('cursor')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('previewed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ai_model_calls', function (Blueprint $table): void {
            $table->id();
            $table->date('call_date');
            $table->string('model', 120);
            $table->string('state', 16); // reserved | completed | failed
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_class', 80)->nullable();
            $table->timestampsTz();
            $table->index(['call_date', 'state']);
        });

        Schema::create('ai_intake_events', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 16); // vehicle | station | support
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->string('event_key', 64);
            $table->string('thread_key', 80)->nullable();
            $table->string('origin', 12); // live | backlog
            $table->foreignId('backlog_run_id')->nullable()->constrained('ai_backlog_runs')->nullOnDelete();
            $table->unsignedBigInteger('epoch');
            $table->string('state', 16); // queued | claimed | retry | sent | skipped | cancelled | failed
            $table->string('decision', 40)->nullable();
            $table->string('risk_tier', 16)->nullable(); // routine | elevated | critical
            $table->boolean('needs_human')->default(true);
            $table->string('template_key', 64)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->text('reply_text')->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('knowledge_refs')->nullable();
            $table->foreignId('model_call_id')->nullable()->constrained('ai_model_calls')->nullOnDelete();
            $table->string('reply_record_type', 64)->nullable();
            $table->unsignedBigInteger('reply_record_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            // One automated response per source event, whoever (hook, sweeper, backlog) gets there first.
            $table->unique(['source_type', 'source_id', 'event_key']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['domain', 'created_at']);
            $table->index(['thread_key', 'state']);
        });

        Schema::create('ai_automation_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('kind', 12); // template | list
            $table->string('label', 120);
            $table->text('help')->nullable();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('ai_automation_playbook_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_id')->constrained('ai_automation_playbooks')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edited_by_name', 160);
            $table->timestampTz('created_at');
            $table->unique(['playbook_id', 'version']);
        });

        Schema::create('ai_knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->json('topic_tags')->nullable();
            $table->boolean('member_safe')->default(true);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ai_knowledge_article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('ai_knowledge_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->longText('body');
            $table->string('status', 16); // draft | in_review | published | superseded | withdrawn
            $table->string('change_summary', 255)->nullable();
            // Internal provenance (which procedure or owner decision this came from). Never shown to members.
            $table->text('source_note')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 160);
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_by_name', 160)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();
            $table->unique(['article_id', 'version']);
            $table->index('status');
        });

        DB::table('ai_automation_settings')->insert([
            'enabled' => false,
            'epoch' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Permission::findOrCreate('admin.ai_automation.manage', 'web');
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('AI automation tables are only dropped in local or testing.');
        }

        Schema::dropIfExists('ai_knowledge_article_versions');
        Schema::dropIfExists('ai_knowledge_articles');
        Schema::dropIfExists('ai_automation_playbook_revisions');
        Schema::dropIfExists('ai_automation_playbooks');
        Schema::dropIfExists('ai_intake_events');
        Schema::dropIfExists('ai_model_calls');
        Schema::dropIfExists('ai_backlog_runs');
        Schema::dropIfExists('ai_automation_switch_events');
        Schema::dropIfExists('ai_automation_settings');
    }
};
