<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->text('public_response')->nullable();
        });
        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->uuid('client_submission_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archive_reason', 'public_response']);
        });
        Schema::table('station_inventory_submissions', function (Blueprint $table): void {
            $table->dropUnique(['client_submission_id']);
            $table->dropColumn('client_submission_id');
        });
    }
};
