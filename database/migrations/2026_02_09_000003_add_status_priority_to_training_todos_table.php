<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_todos', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('is_completed');
            $table->string('priority')->default('medium')->after('status');
        });

        // Convert existing is_completed boolean to status
        DB::table('training_todos')
            ->where('is_completed', true)
            ->update(['status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('training_todos', function (Blueprint $table) {
            $table->dropColumn(['status', 'priority']);
        });
    }
};
