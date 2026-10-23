<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captain 5 follow-up (docs/captain5-ems-operations-center.md section 11). Additive only.
 *
 * - captain5_acting_requests: a member asks to be shown as the shift's acting Captain 5 (or asks to
 *   end the current acting display); an administrator approves. Display only, never a permission.
 *   The earlier captain5_duty_assignments table is left in place, unused, as audit history (it is
 *   empty in production and the previous image still reads it, which keeps a rollback safe).
 * - department_updates.origin / client_request_id: updates posted from the 300 or Captain 5 page.
 * - department_updates.author_name / author_rank: who posted it, stored when it is created (never edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captain5_acting_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 8); // act | end
            $table->foreignId('employee_profile_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('duration', 8)->nullable(); // 12h | 24h | custom
            $table->string('shift_letter', 1)->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | withdrawn
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('ended_by_request_id')->nullable()->constrained('captain5_acting_requests')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestampsTz();
            $table->index(['status', 'kind', 'ends_at']);
            $table->index(['requested_by_user_id', 'status']);
        });

        Schema::table('department_updates', function (Blueprint $table): void {
            $table->string('origin', 20)->nullable()->after('author_id');
            $table->string('client_request_id', 64)->nullable()->unique()->after('origin');
            $table->string('author_name', 160)->nullable()->after('author_id');
            $table->string('author_rank', 80)->nullable()->after('author_name');
        });
    }

    public function down(): void
    {
        Schema::table('department_updates', function (Blueprint $table): void {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['origin', 'client_request_id', 'author_name', 'author_rank']);
        });
        Schema::dropIfExists('captain5_acting_requests');
    }
};
