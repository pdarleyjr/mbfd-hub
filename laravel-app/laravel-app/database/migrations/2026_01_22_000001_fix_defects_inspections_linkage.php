<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table) {
            $table->foreignId('apparatus_inspection_id')->nullable()->after('apparatus_id')
                  ->constrained('apparatus_inspections')->nullOnDelete();
            $table->string('issue_type')->default('missing')->after('status');
            $table->date('reported_date')->nullable()->after('issue_type');
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table) {
            $table->dropForeign(['apparatus_inspection_id']);
            $table->dropColumn(['apparatus_inspection_id', 'issue_type', 'reported_date']);
        });
    }
};
