<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite already stores full timestamp text. Avoid rebuilding its table,
        // which would lose the current-revision index's partial WHERE clause.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('employee_bid_assignments', function (Blueprint $table): void {
            $table->timestampTz('picked_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // A precision downgrade would change immutable historical pick instants.
        if (DB::table('employee_bid_assignments')->exists()) {
            throw new LogicException('Bid assignment timestamp precision must be retained during rollback.');
        }

        Schema::table('employee_bid_assignments', function (Blueprint $table): void {
            $table->timestampTz('picked_at')->nullable()->change();
        });
    }
};
