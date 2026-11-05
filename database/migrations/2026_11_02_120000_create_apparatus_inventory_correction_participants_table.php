<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One equipment change request for everyone who reported the same change
 * (docs/apparatus-inventory-corrections.md). Additive only: the reporter columns on the
 * correction stay authoritative for the original reporter, and old code ignores both the
 * participants table and the duplicate link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_inventory_correction_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inventory_correction_id')->constrained('apparatus_inventory_corrections')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // 'reporter' (filed the request) or 'joined' (reported the same change later).
            $table->string('source', 16);
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['apparatus_inventory_correction_id', 'user_id'], 'inv_correction_participants_unique');
            $table->index('user_id', 'inv_correction_participants_user_idx');
            $table->index('employee_id', 'inv_correction_participants_employee_idx');
        });

        Schema::table('apparatus_inventory_corrections', function (Blueprint $table): void {
            $table->foreignId('duplicate_of_correction_id')->nullable()->after('lock_version')
                ->constrained('apparatus_inventory_corrections')->nullOnDelete();
        });

        // Every existing request already has its reporter as a participant.
        DB::table('apparatus_inventory_correction_participants')->insertUsing(
            ['apparatus_inventory_correction_id', 'user_id', 'employee_id', 'source', 'joined_at', 'created_at', 'updated_at'],
            DB::table('apparatus_inventory_corrections')
                ->where(fn ($query) => $query->whereNotNull('reporter_user_id')->orWhereNotNull('reporter_employee_id'))
                ->select(['id', 'reporter_user_id', 'reporter_employee_id', DB::raw("'reporter'"), DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'), 'created_at', 'created_at']),
        );
    }

    public function down(): void
    {
        Schema::table('apparatus_inventory_corrections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_correction_id');
        });
        Schema::dropIfExists('apparatus_inventory_correction_participants');
    }
};
