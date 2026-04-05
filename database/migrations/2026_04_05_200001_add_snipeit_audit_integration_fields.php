<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Link apparatus to Snipe-IT asset for equipment audit integration
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->unsignedInteger('snipeit_asset_id')->nullable()->after('status');
            $table->string('snipeit_asset_tag', 20)->nullable()->after('snipeit_asset_id');
        });

        // Add employee linkage and inspection reference to inspections
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('operator_name');
            $table->string('inspection_reference', 30)->nullable()->after('completed_at');

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();

            $table->index('inspection_reference');
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropIndex(['inspection_reference']);
            $table->dropColumn(['employee_id', 'inspection_reference']);
        });

        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropColumn(['snipeit_asset_id', 'snipeit_asset_tag']);
        });
    }
};
