<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable: which sign-in a push device was enrolled from, as a
 * sha256 of the canonical session registry id. Signing out (or revoking every
 * session) removes the devices bound to the ended sessions, so a shared station
 * browser stops showing the previous member's alerts. Existing rows stay NULL
 * (unbound) and keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->string('session_binding', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table): void {
            $table->dropIndex(['session_binding']);
            $table->dropColumn('session_binding');
        });
    }
};
