<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->decimal('engine_hours', 10, 1)->nullable()->after('unit_number');
            $table->unsignedInteger('miles')->nullable()->after('engine_hours');
            $table->unique('inspection_reference');
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropUnique(['inspection_reference']);
            $table->dropColumn(['engine_hours', 'miles']);
        });
    }
};
