<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->string('title')->after('id');
            $table->text('description')->nullable()->after('title');
            $table->boolean('is_completed')->default(false)->after('description');
            $table->foreignId('created_by_user_id')->nullable()->after('is_completed')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->after('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('assigned_to_user_id');
            $table->dropColumn(['title', 'description', 'is_completed', 'due_at']);
        });
    }
};
