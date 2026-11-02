<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fire Boat 6 was the one required unit with no status, so members saw "Status unavailable". Nothing on record says it is
 * out of service. A status anyone has already set stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('apparatuses')
            ->where('slug', 'fb6')
            ->whereNull('status')
            ->update(['status' => 'In Service', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The boat keeps its status. A blank one only ever showed as "Status unavailable".
    }
};
