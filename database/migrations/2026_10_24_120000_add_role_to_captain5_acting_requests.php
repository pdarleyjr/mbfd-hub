<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acting requests now cover two shift roles: the shift's Captain 5 and the shift's 300 (Division
 * Chief). Additive only: one column with a default, so every existing row stays a Captain 5 request.
 * It creates, grants and changes no permission or role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captain5_acting_requests', function (Blueprint $table): void {
            $table->string('role', 20)->default('captain_5')->after('kind'); // captain_5 | command_300
            $table->index(['role', 'status'], 'captain5_acting_requests_role_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('captain5_acting_requests', function (Blueprint $table): void {
            $table->dropIndex('captain5_acting_requests_role_status_index');
            $table->dropColumn('role');
        });
    }
};
