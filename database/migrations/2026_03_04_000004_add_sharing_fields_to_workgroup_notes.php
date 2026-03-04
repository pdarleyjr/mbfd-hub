<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workgroup_notes', function (Blueprint $table) {
            $table->boolean('is_shared')->default(false)->after('content');
            $table->foreignId('shared_with_user_id')->nullable()->after('is_shared')
                ->constrained('users')->nullOnDelete();
            $table->index('is_shared');
            $table->index('shared_with_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('workgroup_notes', function (Blueprint $table) {
            $table->dropForeign(['shared_with_user_id']);
            $table->dropColumn(['is_shared', 'shared_with_user_id']);
        });
    }
};
