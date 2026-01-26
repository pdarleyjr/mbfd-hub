<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // Change status column if it doesn't exist, or modify it to ensure proper values
            if (!Schema::hasColumn('todos', 'status')) {
                $table->string('status')->default('pending')->after('is_completed');
            } else {
                $table->string('status')->default('pending')->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // Don't drop status column as it may have been added by previous migration
        });
    }
};
