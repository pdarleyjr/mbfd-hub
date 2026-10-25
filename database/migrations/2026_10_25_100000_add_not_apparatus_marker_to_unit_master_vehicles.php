<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Lets Fleet mark a Master Vehicle Inventory record "not an apparatus" (a support
 * or staff vehicle) so it leaves the Apparatus review worklist, and records who did it and why.
 * Nothing is removed, no existing row changes, and no access is granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_master_vehicles', function (Blueprint $table): void {
            $table->timestamp('not_apparatus_at')->nullable();
            $table->foreignId('not_apparatus_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('not_apparatus_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unit_master_vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('not_apparatus_by_user_id');
            $table->dropColumn(['not_apparatus_at', 'not_apparatus_note']);
        });
    }
};
