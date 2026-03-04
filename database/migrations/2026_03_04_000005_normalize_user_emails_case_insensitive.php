<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop the existing case-sensitive unique index
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');

        // Step 2: Find case-insensitive duplicates BEFORE normalizing
        $duplicates = DB::select("
            SELECT LOWER(email) as norm_email, array_agg(id ORDER BY id) as ids
            FROM users
            GROUP BY LOWER(email)
            HAVING COUNT(*) > 1
        ");

        foreach ($duplicates as $dup) {
            $ids = trim($dup->ids, '{}');
            $idArray = array_map('intval', explode(',', $ids));
            $keepId = $idArray[0];
            $deleteIds = array_slice($idArray, 1);

            foreach ($deleteIds as $deleteId) {
                // Update ALL foreign key references from deleted user to kept user
                // workgroup_members
                DB::statement('DELETE FROM workgroup_members WHERE user_id = ? AND workgroup_id IN (SELECT workgroup_id FROM workgroup_members WHERE user_id = ?)', [$deleteId, $keepId]);
                DB::statement('UPDATE workgroup_members SET user_id = ? WHERE user_id = ?', [$keepId, $deleteId]);
                // session_user
                DB::statement('DELETE FROM "session_user" WHERE user_id = ?', [$deleteId]);
                // evaluation_submissions
                DB::statement('UPDATE evaluation_submissions SET user_id = ? WHERE user_id = ?', [$keepId, $deleteId]);
                // workgroup_notes
                DB::statement('UPDATE workgroup_notes SET shared_with_user_id = ? WHERE shared_with_user_id = ?', [$keepId, $deleteId]);
                // workgroups.created_by
                DB::statement('UPDATE workgroups SET created_by = ? WHERE created_by = ?', [$keepId, $deleteId]);
                // workgroup_files.uploaded_by
                DB::statement('UPDATE workgroup_files SET uploaded_by = ? WHERE uploaded_by = ?', [$keepId, $deleteId]);
                // workgroup_shared_uploads
                DB::statement('UPDATE workgroup_shared_uploads SET uploaded_by = ? WHERE uploaded_by = ?', [$keepId, $deleteId]);
                // notifications
                DB::statement('UPDATE notifications SET notifiable_id = ? WHERE notifiable_type = ? AND notifiable_id = ?', [$keepId, 'App\\Models\\User', $deleteId]);
                // personal_access_tokens
                DB::statement('DELETE FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?', ['App\\Models\\User', $deleteId]);
                // model_has_roles
                DB::statement('DELETE FROM model_has_roles WHERE model_type = ? AND model_id = ?', ['App\\Models\\User', $deleteId]);
                // model_has_permissions
                DB::statement('DELETE FROM model_has_permissions WHERE model_type = ? AND model_id = ?', ['App\\Models\\User', $deleteId]);

                // Finally delete the duplicate user
                DB::statement('DELETE FROM users WHERE id = ?', [$deleteId]);
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
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
    }
};
