<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assigned_equipment', function (Blueprint $table): void {
            $table->foreignId('uniform_id')
                ->nullable()
                ->after('employee_portal_id')
                ->constrained('uniforms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assigned_equipment', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uniform_id');
        });
    }
};
