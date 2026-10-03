<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['personnel_requests', 'station_requests', 'apparatus_service_tickets', 'hub_support_tickets'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('archive_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('archived_by');
                $table->dropIndex(['archived_at']);
                $table->dropColumn(['archived_at', 'archive_reason']);
            });
        }
    }
};
