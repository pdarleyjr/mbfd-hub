<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds:
 * - reason field for why a request is Pending/Declined/etc.
 * - archived status support (Completed and Declined auto-archive)
 * - is_archived boolean for soft archiving
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_equipment_requests', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('admin_notes');
            $table->boolean('is_archived')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('employee_equipment_requests', function (Blueprint $table) {
            $table->dropColumn(['reason', 'is_archived']);
        });
    }
};
