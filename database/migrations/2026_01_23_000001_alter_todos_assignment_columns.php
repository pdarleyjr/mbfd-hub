<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alter todos table
        Schema::table('todos', function (Blueprint $table) {
            // Drop the old foreign key column
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
            
            // Add new columns for staff assignment
            $table->json('assigned_to')->nullable()->after('sort');
            $table->string('created_by')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        // Revert todos table
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn(['assigned_to', 'created_by']);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
