<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeDuplicateUsers extends Command
{
    protected $signature = 'users:merge-duplicates {--dry-run}';
    protected $description = 'Merge duplicate users with same email (case-insensitive). Keeps the lowest ID.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $duplicates = DB::select("
            SELECT LOWER(email) as norm_email, array_agg(id ORDER BY id) as ids
            FROM users
            GROUP BY LOWER(email)
            HAVING COUNT(*) > 1
        ");

        if (empty($duplicates)) {
            $this->info('No duplicate users found.');
            // Still normalize and add CI unique index
            if (!$dryRun) {
                DB::statement('UPDATE users SET email = LOWER(email)');
                DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
                DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');
                $this->info('All emails normalized to lowercase. CI unique index created.');
            }
            return 0;
        }

        $this->info('Found ' . count($duplicates) . ' duplicate email groups.');

        // Get all tables that reference users
        $fkTables = DB::select("
            SELECT tc.table_name, kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.referential_constraints rc ON tc.constraint_name = rc.constraint_name
            JOIN information_schema.constraint_column_usage ccu ON rc.unique_constraint_name = ccu.constraint_name
            WHERE ccu.table_name = 'users' AND ccu.column_name = 'id' AND tc.constraint_type = 'FOREIGN KEY'
        ");

        $this->info('Found ' . count($fkTables) . ' FK references to users table:');
        foreach ($fkTables as $fk) {
            $this->line("  - {$fk->table_name}.{$fk->column_name}");
        }

        foreach ($duplicates as $dup) {
            $ids = trim($dup->ids, '{}');
            $idArray = array_map('intval', explode(',', $ids));
            $keepId = $idArray[0];
            $deleteIds = array_slice($idArray, 1);

            $this->info("\nMerging email: {$dup->norm_email}");
            $this->info("  Keep ID: {$keepId}, Delete IDs: " . implode(', ', $deleteIds));

            if ($dryRun) continue;

            foreach ($deleteIds as $deleteId) {
                // For each FK table, reassign or delete
                foreach ($fkTables as $fk) {
                    $table = $fk->table_name;
                    $column = $fk->column_name;

                    // Check if there's a unique constraint involving this column
                    $count = DB::selectOne("SELECT COUNT(*) as cnt FROM \"$table\" WHERE \"$column\" = ?", [$deleteId]);

                    if ($count->cnt > 0) {
                        // Try update first, if unique violation then delete
                        try {
                            DB::statement("UPDATE \"$table\" SET \"$column\" = ? WHERE \"$column\" = ?", [$keepId, $deleteId]);
                            $this->line("  Updated {$table}.{$column}: {$deleteId} -> {$keepId} ({$count->cnt} rows)");
                        } catch (\Throwable $e) {
                            // Unique violation - delete the duplicate rows
                            DB::statement("DELETE FROM \"$table\" WHERE \"$column\" = ?", [$deleteId]);
                            $this->line("  Deleted from {$table} where {$column} = {$deleteId} ({$count->cnt} rows) [unique conflict]");
                        }
                    }
                }

                // Also handle polymorphic morph relations
                $morphTables = ['notifications', 'personal_access_tokens', 'model_has_roles', 'model_has_permissions'];
                foreach ($morphTables as $morphTable) {
                    if (Schema::hasTable($morphTable)) {
                        $morphCol = $morphTable === 'notifications' ? 'notifiable_id' : ($morphTable === 'personal_access_tokens' ? 'tokenable_id' : 'model_id');
                        $morphType = $morphTable === 'notifications' ? 'notifiable_type' : ($morphTable === 'personal_access_tokens' ? 'tokenable_type' : 'model_type');

                        try {
                            DB::statement("UPDATE \"$morphTable\" SET \"$morphCol\" = ? WHERE \"$morphType\" = 'App\\Models\\User' AND \"$morphCol\" = ?", [$keepId, $deleteId]);
                        } catch (\Throwable $e) {
                            DB::statement("DELETE FROM \"$morphTable\" WHERE \"$morphType\" = 'App\\Models\\User' AND \"$morphCol\" = ?", [$deleteId]);
                        }
                    }
                }

                // Delete the duplicate user
                DB::statement('DELETE FROM users WHERE id = ?', [$deleteId]);
                $this->info("  Deleted user ID {$deleteId}");
            }
        }

        if (!$dryRun) {
            // Normalize all emails
            DB::statement('UPDATE users SET email = LOWER(email)');
            // Drop old constraint if exists, add CI unique
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');

            // Mark migration as done
            $maxBatch = DB::selectOne('SELECT MAX(batch) as b FROM migrations')->b ?? 0;
            DB::table('migrations')->insert([
                'migration' => '2026_03_04_000005_normalize_user_emails_case_insensitive',
                'batch' => $maxBatch + 1,
            ]);

            $this->info('\nAll emails normalized. CI unique index created. Migration marked as run.');
        }

        return 0;
    }
}
