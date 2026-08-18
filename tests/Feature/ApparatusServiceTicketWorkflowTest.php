<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ApparatusServiceTicketStatus;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Notifications\ApparatusServiceTicketEmployeeNotification;
use App\Notifications\NewSubmissionNotification;
use App\Services\ApparatusServiceTicketWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApparatusServiceTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Apparatus $apparatus;

    private Employee $employee;

    private User $fleetAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('logistics_admin', 'web');
        $this->fleetAdmin = User::factory()->create();
        $this->fleetAdmin->assignRole($role);

        $this->station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1051 Jefferson Avenue',
            'is_active' => true,
        ]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $this->station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2022,
            'status' => 'In Service',
            'vin' => 'SECRET-VIN',
            'notes' => 'Internal fleet note',
            'current_engine_hours' => 410.5,
            'current_miles' => 32100,
        ]);
        $this->employee = Employee::query()->create([
            'employee_id' => '9001',
            'name' => 'Firefighter Requester',
            'rank' => 'Firefighter',
            'password' => 'test-password',
        ]);
    }

    public function test_employee_submission_is_idempotent_and_snapshots_station_and_unit(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $payload = [
            'client_submission_id' => '0876e1c3-e8d0-43ee-b8fd-38672e25442f',
            'category' => 'repair_mechanical',
            'title' => 'Air leak at rear brake chamber',
            'description' => 'Audible air leak during morning checkout.',
            'priority' => 'urgent',
        ];

        $first = $service->submitFromEmployee($this->employee, $this->apparatus, $payload);
        $second = $service->submitFromEmployee($this->employee, $this->apparatus, $payload);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->ticket->id, $second->ticket->id);
        $this->assertMatchesRegularExpression('/^AST-\d{4}-\d{6}$/', $first->ticket->ticket_number);
        $this->assertSame('station', $first->ticket->origin);
        $this->assertSame($this->station->id, $first->ticket->station_id);
        $this->assertSame('E1', $first->ticket->unit_designation_snapshot);
        $this->assertSame('Firefighter Requester', $first->ticket->requester_name_snapshot);
        $this->assertSame('submitted', $first->ticket->status);
        $this->assertSame('410.5', $first->ticket->opened_engine_hours);
        $this->assertSame(32100, $first->ticket->opened_miles);
        $this->assertDatabaseCount('apparatus_service_tickets', 1);
        $this->assertDatabaseCount('apparatus_service_ticket_updates', 1);
        Notification::assertSentTo($this->fleetAdmin, NewSubmissionNotification::class);

        $otherStation = Station::query()->create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '2300 Pine Tree Drive',
            'is_active' => true,
        ]);
        $this->apparatus->update(['station_id' => $otherStation->id]);

        $this->assertSame($this->station->id, $first->ticket->refresh()->station_id);
        $this->assertSame('E1', $first->ticket->unit_designation_snapshot);
    }

    public function test_workflow_enforces_legal_transitions_and_append_only_updates(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $ticket = $service->submitFromEmployee($this->employee, $this->apparatus, [
            'client_submission_id' => 'b7e97ef8-15f6-4796-b321-af5bba786976',
            'category' => 'electrical',
            'title' => 'Scene light intermittent',
            'description' => 'Right-side scene light cuts out under vibration.',
            'priority' => 'attention',
        ])->ticket;

        try {
            $service->transition($ticket, $this->fleetAdmin, ApparatusServiceTicketStatus::Completed, [
                'public_note' => 'Invalid shortcut.',
            ]);
            $this->fail('An illegal submitted-to-completed transition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $service->transition($ticket, $this->fleetAdmin, ApparatusServiceTicketStatus::Acknowledged, [
            'public_note' => 'Fleet has reviewed the report.',
            'internal_note' => 'Inspect wiring at connector J4.',
        ]);
        Notification::assertSentTo($this->employee, ApparatusServiceTicketEmployeeNotification::class);
        $service->transition($ticket, $this->fleetAdmin, ApparatusServiceTicketStatus::Scheduled, [
            'scheduled_for' => now()->addDay()->toDateTimeString(),
            'service_type' => 'Electrical diagnosis',
            'scheduled_location' => 'Fire Fleet',
            'public_note' => 'Scheduled with Fleet for tomorrow.',
        ]);
        $service->transition($ticket, $this->fleetAdmin, ApparatusServiceTicketStatus::InProgress, []);
        $completed = $service->transition($ticket, $this->fleetAdmin, ApparatusServiceTicketStatus::Completed, [
            'public_note' => 'Connector repaired and function checked.',
            'resolution_summary' => 'Replaced the loose J4 connector and completed a load test.',
            'completed_engine_hours' => 413.2,
            'completed_miles' => 32140,
        ]);

        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('Replaced the loose J4 connector and completed a load test.', $completed->resolution_summary);
        $this->assertSame('413.2', $completed->completed_engine_hours);
        $this->assertSame(32140, $completed->completed_miles);
        $this->assertCount(5, $completed->updates);
        $this->assertSame('submitted', $completed->updates[0]->status);
        $this->assertSame('completed', $completed->updates[4]->status);

        $update = $completed->updates[1];
        $this->expectException(LogicException::class);
        $update->update(['public_note' => 'History cannot be rewritten.']);
    }

    public function test_pm_log_creates_one_completed_ticket_and_updates_pm_fields_atomically(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $payload = [
            'client_submission_id' => '9370fbd1-a58b-4bc9-ab09-2059543587ca',
            'service_date' => '2026-08-18',
            'service_type' => 'PMA',
            'service_engine_hours' => 412.0,
            'service_mileage' => 32125,
            'service_notes' => 'Oil and fuel filters replaced; chassis lubricated.',
        ];

        $first = $service->logPmService($this->apparatus, $this->fleetAdmin, $payload);
        $second = $service->logPmService($this->apparatus, $this->fleetAdmin, $payload);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->ticket->id, $second->ticket->id);
        $this->assertSame('pm', $first->ticket->origin);
        $this->assertSame('preventive_maintenance', $first->ticket->category);
        $this->assertSame('completed', $first->ticket->status);
        $this->assertSame('PMA', $first->ticket->service_type);

        $apparatus = $this->apparatus->refresh();
        $this->assertSame('2026-08-18', $apparatus->last_pm_date?->format('Y-m-d'));
        $this->assertSame('412.0', $apparatus->last_pm_engine_hours);
        $this->assertSame(32125, $apparatus->last_pm_mileage);
        $this->assertSame('412.0', $apparatus->current_engine_hours);
        $this->assertSame(32125, $apparatus->current_miles);
        $this->assertDatabaseCount('apparatus_service_tickets', 1);
        $this->assertDatabaseCount('apparatus_service_ticket_updates', 1);
    }

    public function test_operational_status_is_separate_preserves_maintenance_and_rejects_unknown_values(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);

        $service->changeOperationalStatus($this->apparatus, $this->fleetAdmin, 'Out of Service');
        $this->assertSame('Out of Service', $this->apparatus->refresh()->status);
        $this->assertDatabaseCount('apparatus_service_tickets', 0);

        $service->changeOperationalStatus($this->apparatus, $this->fleetAdmin, 'Maintenance');
        $this->assertSame('Maintenance', $this->apparatus->refresh()->status);

        $this->expectException(ValidationException::class);
        $service->changeOperationalStatus($this->apparatus, $this->fleetAdmin, 'Workshop');
    }

    public function test_ticket_originated_operational_status_change_is_appended_and_publicly_auditable(): void
    {
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $ticket = $service->submitFromEmployee($this->employee, $this->apparatus, [
            'client_submission_id' => '19248ae0-6923-46df-aa27-d6d65cd5fe51',
            'category' => 'repair_mechanical',
            'title' => 'Steering pulls under braking',
            'description' => 'The unit pulls right during a controlled brake check.',
            'priority' => 'urgent',
        ])->ticket;

        $service->changeOperationalStatus(
            $this->apparatus,
            $this->fleetAdmin,
            'Out of Service',
            $ticket,
            'Unit placed out of service pending Fleet inspection.',
            'Officer notified by Fleet.',
        );

        $this->assertSame('Out of Service', $this->apparatus->refresh()->status);
        $this->assertSame('Unit placed out of service pending Fleet inspection.', $ticket->refresh()->current_public_response);
        $this->assertDatabaseHas('apparatus_service_ticket_updates', [
            'apparatus_service_ticket_id' => $ticket->id,
            'status' => 'submitted',
            'public_note' => 'Unit placed out of service pending Fleet inspection.',
            'internal_note' => 'Officer notified by Fleet.',
        ]);
    }
}
