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
        Schema::table('station_inspections', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('pending_review')->after('overall_status');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->index(['station_id', 'review_status', 'inspection_date'], 'station_inspections_review_index');
        });

        DB::table('station_inspections')
            ->where(fn ($query) => $query->whereNotNull('reviewed_by')->orWhereNotNull('reviewed_at'))
            ->update(['review_status' => 'reviewed']);
    }

    public function down(): void
    {
        Schema::table('station_inspections', function (Blueprint $table): void {
            $table->dropIndex('station_inspections_review_index');
            $table->dropColumn(['review_status', 'review_note']);
        });
    }
};
