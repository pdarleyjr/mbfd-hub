<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_inventory_submissions', function (Blueprint $table) {
            $table->string('employee_name')->nullable()->after('station_id');
            $table->string('shift')->nullable()->after('employee_name');
            $table->text('notes')->nullable()->after('items');
            $table->timestamp('submitted_at')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('station_inventory_submissions', function (Blueprint $table) {
            $table->dropColumn(['employee_name', 'shift', 'notes', 'submitted_at']);
        });
    }
};
