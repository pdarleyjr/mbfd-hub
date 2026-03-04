<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize all user emails to lowercase and add a case-insensitive unique index.
 * Merges any duplicate accounts that have the same email with different casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop the existing case-sensitive unique index
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');

        // Step 2: Find case-insensitive duplicates BEFORE normalizing
        $duplicates = DB::select('
            SELECT LOWER(email) as norm_email, array_agg(id ORDER BY id) as ids
            FROM users
            GROUP BY LOWER(email)
            HAVING COUNT(*) > 1
        ');

        foreach ($duplicates as $dup) {
            $ids = trim($dup->ids, '{}');
            $idArray = array_map('intval', explode(',', $ids));
            $keepId = $idArray[0]; // Keep the lowest ID
            $deleteIds = array_slice($idArray, 1);

            foreach ($deleteIds as $deleteId) {
                // Reassign FK references from deleted user to kept user
                // workgroup_members - only if no conflict
                $existingWgs = DB::select('SELECT workgroup_id FROM workgroup_members WHERE user_id = ?', [$keepId]);
                $existingWgIds = array_column($existingWgs, 'workgroup_id');
                DB::delete('DELETE FROM workgroup_members WHERE user_id = ? AND workgroup_id = ANY(?)', [$deleteId, '{' . implode(',', $existingWgIds) . '}']);
                DB::update('UPDATE workgroup_members SET user_id = ? WHERE user_id = ?', [$keepId, $deleteId]);

                // session_user
                DB::delete('DELETE FROM "session_user" WHERE user_id = ?', [$deleteId]);

                // evaluation_submissions
                DB::update('UPDATE evaluation_submissions SET user_id = ? WHERE user_id = ?', [$keepId, $deleteId]);

                // workgroup_notes shared_with_user_id
                DB::update('UPDATE workgroup_notes SET shared_with_user_id = ? WHERE shared_with_user_id = ?', [$keepId, $deleteId]);

                // Delete the duplicate user
                DB::delete('DELETE FROM users WHERE id = ?', [$deleteId]);
            }
        }

        // Step 3: Normalize all emails to lowercase
        DB::statement('UPDATE users SET email = LOWER(email)');

        // Step 4: Add case-insensitive unique index
        DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
        // Re-add original unique constraint
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
    }
};
