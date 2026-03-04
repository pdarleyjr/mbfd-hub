<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize all user emails to lowercase and add a case-insensitive unique index.
 * This prevents duplicate accounts with different email casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Normalize all emails to lowercase
        DB::statement('UPDATE users SET email = LOWER(email)');

        // Merge any duplicates that arise (keep lowest ID)
        $duplicates = DB::select('
            SELECT LOWER(email) as email, array_agg(id ORDER BY id) as ids
            FROM users
            GROUP BY LOWER(email)
            HAVING COUNT(*) > 1
        ');

        foreach ($duplicates as $dup) {
            $ids = trim($dup->ids, '{}');
            $idArray = explode(',', $ids);
            $keepId = (int) $idArray[0];
            $deleteIds = array_slice($idArray, 1);

            foreach ($deleteIds as $deleteId) {
                $deleteId = (int) $deleteId;
                // Move FK references to the kept user
                DB::statement('UPDATE workgroup_members SET user_id = ? WHERE user_id = ? AND NOT EXISTS (SELECT 1 FROM workgroup_members WHERE user_id = ? AND workgroup_id = (SELECT workgroup_id FROM workgroup_members WHERE user_id = ? LIMIT 1))', [$keepId, $deleteId, $keepId, $deleteId]);
                DB::statement('DELETE FROM workgroup_members WHERE user_id = ?', [$deleteId]);
                DB::statement('UPDATE evaluation_submissions SET user_id = ? WHERE user_id = ?', [$keepId, $deleteId]);
                DB::statement('DELETE FROM "session_user" WHERE user_id = ?', [$deleteId]);
                DB::statement('DELETE FROM users WHERE id = ?', [$deleteId]);
            }
        }

        // Add case-insensitive unique index
        DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
    }
};
