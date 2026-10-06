<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bid_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('payload_version');
            $table->unsignedSmallInteger('bid_year');
            $table->string('term_label', 32);
            $table->string('bid_session_id', 100);
            $table->string('position_id', 32);
            $table->string('rank_label', 100);
            $table->string('shift_label', 32);
            $table->string('station_label', 200);
            $table->string('division_label', 100)->nullable();
            $table->string('unit_label', 200);
            $table->string('position_label', 200)->nullable();
            $table->string('bid_selection_label', 200);
            $table->string('assignment_type', 32)->nullable();
            $table->string('assignment_source', 32);
            $table->string('a_day_code', 16)->nullable();
            $table->string('a_day_label', 32);
            $table->timestampTz('picked_at')->nullable();
            $table->string('idempotency_key', 200)->unique();
            $table->char('payload_hash', 64);
            $table->boolean('is_forced');
            $table->string('admin_actor_employee_id', 64)->nullable();
            $table->unsignedBigInteger('source_sequence')->nullable();
            $table->char('source_result_hash', 64)->nullable();
            $table->char('source_workbook_sha256', 64)->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->index(['employee_profile_id', 'bid_year', 'created_at']);
        });

        // Both supported databases (PostgreSQL production and SQLite tests)
        // enforce one current revision while preserving all previous revisions.
        DB::statement('CREATE UNIQUE INDEX employee_bid_assignments_current_unique '
            .'ON employee_bid_assignments (employee_profile_id, bid_year) WHERE superseded_at IS NULL');
    }

    public function down(): void
    {
        // Code/UI rollback retains imported history. An empty migration can be
        // reversed in a disposable environment; populated production cannot.
        if (DB::table('employee_bid_assignments')->exists()) {
            throw new LogicException('Bid assignment history must be retained during rollback.');
        }

        Schema::dropIfExists('employee_bid_assignments');
    }
};
