<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Services messaging: a structured "reason" for each conversation
 * (App\Services\EmployeeMessaging\EmployeeMessageReason). Additive and nullable: existing
 * conversations keep a null reason and read exactly as before; prior images ignore the column.
 * No permission is created or granted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_logistics_threads', 'reason')) {
            return;
        }

        Schema::table('employee_logistics_threads', function (Blueprint $table): void {
            $table->string('reason', 40)->nullable()->after('subject');
            $table->index('reason', 'emp_log_threads_reason');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Employee messages are department records and are never altered destructively outside local or testing.');
        }

        if (! Schema::hasColumn('employee_logistics_threads', 'reason')) {
            return;
        }

        Schema::table('employee_logistics_threads', function (Blueprint $table): void {
            $table->dropIndex('emp_log_threads_reason');
            $table->dropColumn('reason');
        });
    }
};
