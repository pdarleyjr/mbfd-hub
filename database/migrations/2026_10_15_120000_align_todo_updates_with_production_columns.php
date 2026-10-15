<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production todo_updates has username and comment (what TodoUpdate and the
 * Todo view page write), while a fresh migrate still created a required
 * `content` column, so adding a Todo update failed outside production.
 * Production already matches: every step here is guarded and a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todo_updates', function (Blueprint $table): void {
            if (! Schema::hasColumn('todo_updates', 'username')) {
                $table->string('username')->nullable();
            }
            if (! Schema::hasColumn('todo_updates', 'comment')) {
                $table->text('comment')->nullable();
            }
        });

        if (Schema::hasColumn('todo_updates', 'content')) {
            Schema::table('todo_updates', function (Blueprint $table): void {
                $table->text('content')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Columns that exist in production are never dropped; only the fresh-install
        // `content` requirement is restored where that legacy column still exists.
        if (Schema::hasColumn('todo_updates', 'content')) {
            Schema::table('todo_updates', function (Blueprint $table): void {
                $table->text('content')->nullable(false)->change();
            });
        }
    }
};
