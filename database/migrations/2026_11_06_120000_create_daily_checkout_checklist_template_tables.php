<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Checkout checklist template editor (docs/checklist-template-editor.md section 2).
 *
 * Additive only. With no `published` row the resolver keeps reading the repository file exactly as
 * before, so deploying this migration changes no checklist_version. Rolling back = redeploy the
 * previous image (old code ignores these tables); down() drops only these tables.
 *
 * - daily_checkout_checklist_template_versions: drafts, the published version and its predecessors.
 *   At most one draft and one published row per checklist type (partial unique indexes; both
 *   Postgres and SQLite support them). `base_checklist` is the live baseline the draft started from,
 *   so rename/move aliases are always computed against what members actually had.
 * - daily_checkout_checklist_template_events: append-only audit trail.
 */
return new class extends Migration
{
    private const ONE_DRAFT_INDEX = 'checklist_template_versions_one_draft';

    private const ONE_PUBLISHED_INDEX = 'checklist_template_versions_one_published';

    public function up(): void
    {
        Schema::create('daily_checkout_checklist_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('checklist_type', 40)->index();
            $table->string('status', 20);
            $table->unsignedInteger('version_number')->nullable();
            $table->json('checklist');
            $table->json('base_checklist')->nullable();
            $table->char('checklist_hash', 64);
            $table->char('based_on_hash', 64);
            $table->foreignId('based_on_version_id')->nullable()
                ->constrained('daily_checkout_checklist_template_versions')->nullOnDelete();
            $table->char('source_file_hash', 64)->nullable();
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index(['checklist_type', 'status'], 'checklist_template_versions_type_status');
            $table->unique(['checklist_type', 'version_number'], 'checklist_template_versions_number_unique');
        });

        DB::statement('CREATE UNIQUE INDEX '.self::ONE_DRAFT_INDEX
            ." ON daily_checkout_checklist_template_versions (checklist_type) WHERE status = 'draft'");
        DB::statement('CREATE UNIQUE INDEX '.self::ONE_PUBLISHED_INDEX
            ." ON daily_checkout_checklist_template_versions (checklist_type) WHERE status = 'published'");

        Schema::create('daily_checkout_checklist_template_events', function (Blueprint $table): void {
            $table->id();
            $table->string('checklist_type', 40);
            $table->foreignId('template_version_id')->nullable()
                ->constrained('daily_checkout_checklist_template_versions')->nullOnDelete();
            $table->string('action', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['checklist_type', 'id'], 'checklist_template_events_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checkout_checklist_template_events');
        DB::statement('DROP INDEX IF EXISTS '.self::ONE_PUBLISHED_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.self::ONE_DRAFT_INDEX);
        Schema::dropIfExists('daily_checkout_checklist_template_versions');
    }
};
