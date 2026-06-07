<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update station_inspections: add sog_mandate_acknowledged, extinguishing_system_date
        // (These columns may already exist from the create migration)
        Schema::table('station_inspections', function (Blueprint $table) {
            if (! Schema::hasColumn('station_inspections', 'sog_mandate_acknowledged')) {
                $table->boolean('sog_mandate_acknowledged')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('station_inspections', 'extinguishing_system_date')) {
                $table->date('extinguishing_system_date')->nullable()->after('sog_mandate_acknowledged');
            }
        });

        // Update fire_equipment_requests: add officer_signature, pd_case_number, expand status enum
        Schema::table('fire_equipment_requests', function (Blueprint $table) {
            $table->text('officer_signature')->nullable()->after('signature');
            $table->string('pd_case_number')->nullable()->after('officer_signature');
            $table->string('requested_by_name')->nullable()->after('requested_by');
            $table->text('explanation')->nullable()->after('description');
        });

        // Expand the status enum for equipment requests to support multi-step
        // approval. These are Postgres-only DDL statements; SQLite stores the
        // column as plain text already, so skip on non-pgsql drivers.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fire_equipment_requests DROP CONSTRAINT IF EXISTS fire_equipment_requests_status_check');
            DB::statement('ALTER TABLE fire_equipment_requests ALTER COLUMN status TYPE varchar(50)');
            DB::statement("ALTER TABLE fire_equipment_requests ALTER COLUMN status SET DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('station_inspections', function (Blueprint $table) {
            $table->dropColumn(['sog_mandate_acknowledged', 'extinguishing_system_date']);
        });

        Schema::table('fire_equipment_requests', function (Blueprint $table) {
            $table->dropColumn(['officer_signature', 'pd_case_number', 'requested_by_name', 'explanation']);
        });
    }
};
