<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets Fleet clear a handled Take Home Vehicle Inspection: it leaves the active
 * list and the dashboards and is kept under Archived, restorable. Only these
 * columns may change on a submitted inspection (see the model's update guard);
 * every clear and restore is appended to archive_history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('take_home_vehicle_inspections', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->json('archive_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('take_home_vehicle_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archive_reason', 'archive_history']);
        });
    }
};
