<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status');
            $table->foreignId('session_id')->nullable()->after('candidate_product_id')
                ->constrained('workgroup_sessions')->nullOnDelete();
            $table->index('is_locked');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
            $table->dropIndex(['is_locked']);
            $table->dropIndex(['session_id']);
            $table->dropColumn(['is_locked', 'session_id']);
        });
    }
};
