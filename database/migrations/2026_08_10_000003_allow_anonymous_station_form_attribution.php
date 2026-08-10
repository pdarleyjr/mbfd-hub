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
        Schema::table('big_ticket_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('big_ticket_requests')->whereNull('created_by')->exists()
            || DB::table('station_inventory_submissions')->whereNull('created_by')->exists()) {
            throw new RuntimeException(
                'Cannot restore required created_by columns while anonymous station submissions exist.'
            );
        }

        Schema::table('big_ticket_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
