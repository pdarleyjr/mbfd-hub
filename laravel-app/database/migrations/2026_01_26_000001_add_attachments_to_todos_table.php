<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            // Add missing fields that don't exist
            $table->string('assigned_by')->nullable()->after('created_by');
            $table->timestamp('completed_at')->nullable()->after('priority');
            $table->foreignId('created_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['assigned_by', 'completed_at', 'created_by_user_id']);
        });
    }
};
