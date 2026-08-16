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
        // PostgreSQL was normalized to varchar by the PDF-alignment migration.
        // Keep SQLite/MySQL test and recovery schemas capable of representing
        // every historical workflow status before backfill reads them.
        if (Schema::hasTable('fire_equipment_requests') && DB::connection()->getDriverName() !== 'pgsql') {
            Schema::table('fire_equipment_requests', function (Blueprint $table): void {
                $table->string('status', 50)->default('pending')->change();
            });
        }

        Schema::create('station_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->nullable()->unique();
            $table->string('request_number')->nullable()->unique();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('room_name_snapshot')->nullable();
            $table->foreignId('requested_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('requester_name_snapshot');
            $table->string('request_type', 40);
            $table->string('subject_type', 100)->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 40)->default('pending');
            $table->text('current_public_response')->nullable();
            $table->text('status_detail')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_vendor')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('legacy_source')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['station_id', 'status']);
            $table->index(['station_id', 'request_type']);
            $table->index(['room_id', 'status']);
            $table->index(['requested_by_employee_id', 'created_at'], 'station_requests_employee_created_idx');
        });

        Schema::create('station_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_request_id')->constrained('station_requests')->cascadeOnDelete();
            $table->foreignId('room_asset_id')->nullable()->constrained('room_assets')->nullOnDelete();
            $table->string('item_name');
            $table->string('category')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('reason')->nullable();
            $table->string('requested_action')->nullable();
            $table->string('condition')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model_number')->nullable();
            $table->string('pd_case_number')->nullable();
            $table->string('photo_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['station_request_id', 'room_asset_id'], 'station_request_items_request_asset_idx');
        });

        Schema::create('station_request_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_request_id')->constrained('station_requests')->cascadeOnDelete();
            $table->string('status', 40);
            $table->text('public_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['station_request_id', 'created_at'], 'station_request_updates_request_created_idx');
        });

        Schema::create('room_asset_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_asset_id')->constrained('room_assets')->restrictOnDelete();
            $table->foreignId('station_request_id')->nullable()->constrained('station_requests')->nullOnDelete();
            $table->string('event_type', 60);
            $table->timestamp('event_at');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['room_asset_id', 'event_at'], 'room_asset_events_asset_event_idx');
            $table->index(['station_request_id', 'event_at'], 'room_asset_events_request_event_idx');
        });

        Schema::table('room_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('room_assets', 'asset_tag')) {
                $table->string('asset_tag')->nullable()->index();
            }
            if (! Schema::hasColumn('room_assets', 'unit')) {
                $table->string('unit')->nullable();
            }
            if (! Schema::hasColumn('room_assets', 'manufacturer')) {
                $table->string('manufacturer')->nullable();
            }
            if (! Schema::hasColumn('room_assets', 'model_number')) {
                $table->string('model_number')->nullable();
            }
            if (! Schema::hasColumn('room_assets', 'location_within_room')) {
                $table->string('location_within_room')->nullable();
            }
            if (! Schema::hasColumn('room_assets', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
            if (! Schema::hasColumn('room_assets', 'retired_at')) {
                $table->timestamp('retired_at')->nullable();
            }
            if (! Schema::hasColumn('room_assets', 'replaced_by_room_asset_id')) {
                $table->foreignId('replaced_by_room_asset_id')
                    ->nullable()
                    ->constrained('room_assets')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_asset_events');
        Schema::dropIfExists('station_request_updates');
        Schema::dropIfExists('station_request_items');
        Schema::dropIfExists('station_requests');
    }
};
