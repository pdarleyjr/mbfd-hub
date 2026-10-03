<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'operational_form_records',
        'station_inspections',
        'station_inventory_submissions',
        'trt_inventory_sessions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable()->index();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('archive_reason')->nullable();
                if ($table->getTable() !== 'operational_form_records') {
                    $table->json('archive_history')->nullable();
                }
            });
        }
        Schema::table('operational_form_events', static function (Blueprint $table): void {
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operational_form_events', static function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('archived_by');
                $table->dropIndex(['archived_at']);
                $table->dropColumn(['archived_at', 'archive_reason']);
                if ($table->getTable() !== 'operational_form_records') {
                    $table->dropColumn('archive_history');
                }
            });
        }
    }
};
