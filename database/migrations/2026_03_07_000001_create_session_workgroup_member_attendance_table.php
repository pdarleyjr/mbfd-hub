<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_workgroup_member_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_session_id')
                ->constrained('workgroup_sessions')
                ->cascadeOnDelete();
            $table->foreignId('workgroup_member_id')
                ->constrained('workgroup_members')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workgroup_session_id', 'workgroup_member_id'], 'attendance_unique');
        });

        // ---------------------------------------------------------------
        // BACKFILL: Grant attendance to every member who already has
        //           an evaluation submission for a given session.
        //
        // evaluation_submissions -> candidate_products -> workgroup_session_id
        // ---------------------------------------------------------------
        $existingAttendance = DB::table('evaluation_submissions as es')
            ->join('candidate_products as cp', 'cp.id', '=', 'es.candidate_product_id')
            ->whereNotNull('cp.workgroup_session_id')
            ->select(
                'cp.workgroup_session_id as workgroup_session_id',
                'es.workgroup_member_id as workgroup_member_id'
            )
            ->distinct()
            ->get();

        $now = now();
        $rows = $existingAttendance->map(fn($row) => [
            'workgroup_session_id' => $row->workgroup_session_id,
            'workgroup_member_id'  => $row->workgroup_member_id,
            'created_at'           => $now,
            'updated_at'           => $now,
        ])->toArray();

        if (!empty($rows)) {
            // Insert in chunks, ignoring duplicates
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('session_workgroup_member_attendance')
                    ->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('session_workgroup_member_attendance');
    }
};
