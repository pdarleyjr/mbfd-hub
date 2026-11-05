<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One responsible queue per equipment issue (docs/unified-activity-and-ownership.md): the stored decision
 * of DefectOwnership, the "member says it is fixed, awaiting verification" marker, and the standalone
 * stretcher reports. Additive only; the previous image ignores every new column and table. No data is
 * written here: owners are projected by `php artisan defects:project-ownership` after a dry run.
 *
 * Foreign keys to users are Postgres only. On SQLite Laravel adds one by rebuilding the table, which turns
 * apparatus_defects' partial "one open issue per item" indexes into full unique indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->string('responsible_owner', 16)->nullable();
            $table->string('owner_basis', 32)->nullable();
            $table->timestampTz('owner_set_at')->nullable();
            $table->unsignedBigInteger('owner_set_by_user_id')->nullable();
            $table->timestampTz('correction_reported_at')->nullable();
            $table->unsignedBigInteger('correction_reported_by_user_id')->nullable();
            $table->index(['responsible_owner', 'resolved'], 'apparatus_defects_owner_open_index');
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->foreign('owner_set_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('correction_reported_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::create('stretcher_issue_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->unique();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->foreignId('apparatus_defect_id')->nullable()->constrained('apparatus_defects')->nullOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('problem', 24);
            $table->text('description');
            $table->boolean('unusable')->default(false);
            $table->string('photo_path')->nullable();
            $table->boolean('attached_to_existing')->default(false);
            $table->timestamps();
            $table->index(['reporter_user_id', 'created_at']);
            $table->index(['apparatus_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Rollback in production is image only (the previous image ignores all of this); data is never dropped there.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Ownership columns and stretcher reports are only dropped in local or testing.');
        }
        Schema::dropIfExists('stretcher_issue_reports');
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->dropForeign(['owner_set_by_user_id']);
                $table->dropForeign(['correction_reported_by_user_id']);
            });
        }
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropIndex('apparatus_defects_owner_open_index');
            $table->dropColumn([
                'responsible_owner', 'owner_basis', 'owner_set_at', 'owner_set_by_user_id',
                'correction_reported_at', 'correction_reported_by_user_id',
            ]);
        });
    }
};
