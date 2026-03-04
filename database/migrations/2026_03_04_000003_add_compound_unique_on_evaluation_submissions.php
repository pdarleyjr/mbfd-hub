<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add compound unique constraint to prevent duplicate submissions
     * by the same user for the same product in the same session.
     * Note: We add user_id column to evaluation_submissions for direct lookups.
     */
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('workgroup_member_id')
                ->constrained()->nullOnDelete();
        });

        // Backfill user_id from workgroup_members
        \Illuminate\Support\Facades\DB::statement('
            UPDATE evaluation_submissions es
            SET user_id = wm.user_id
            FROM workgroup_members wm
            WHERE es.workgroup_member_id = wm.id
        ');

        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->unique(['user_id', 'candidate_product_id', 'session_id'], 'eval_sub_user_product_session_unique');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->dropUnique('eval_sub_user_product_session_unique');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
