<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. The AI Response receipt for station work:
 *  - station request updates get the intake id that wrote them, so the receipt is told apart from staff rows
 *    with a plain indexed column and a unique key makes writing it twice impossible;
 *  - station supply requests have no update history, so the one receipt lives on the request itself in its
 *    own columns and never touches status, public_response or updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_request_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('automation_intake_id')->nullable()->unique('station_request_updates_automation_intake_unique');
        });

        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->text('ai_receipt_text')->nullable();
            $table->timestamp('ai_receipt_at')->nullable();
            $table->unsignedBigInteger('ai_receipt_intake_id')->nullable()->unique('station_supply_requests_ai_receipt_intake_unique');
        });
    }

    public function down(): void
    {
        Schema::table('station_supply_requests', function (Blueprint $table): void {
            $table->dropUnique('station_supply_requests_ai_receipt_intake_unique');
            $table->dropColumn(['ai_receipt_text', 'ai_receipt_at', 'ai_receipt_intake_id']);
        });

        Schema::table('station_request_updates', function (Blueprint $table): void {
            $table->dropUnique('station_request_updates_automation_intake_unique');
            $table->dropColumn('automation_intake_id');
        });
    }
};
