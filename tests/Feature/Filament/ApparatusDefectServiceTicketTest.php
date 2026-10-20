<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\DefectsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\InspectionsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\ServiceTicketsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\VehicleSwapReportsRelationManager;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\DefectResource\Pages\EditDefect;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusDefectServiceTicketService;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Support\Fleet\ApparatusOverview;
use App\Support\Fleet\DefectFollowUpState;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApparatusDefectServiceTicketTest extends TestCase
{
    use RefreshDatabase;

    private Apparatus $apparatus;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $station = Station::query()->create([
            'station_number' => 7,
            'name' => 'Station 7',
            'address' => '700 Test Street',
            'is_active' => true,
        ]);
        $this->apparatus = Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E7',
            'name' => 'Engine 7',
            'type' => 'Engine',
            'vehicle_number' => '7007',
            'designation' => 'E7',
            'slug' => 'engine-7',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2021,
            'status' => 'In Service',
            'current_engine_hours' => 120,
            'current_miles' => 9000,
        ]);
        $this->inspection = ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => '7007',
            'vehicle_number' => '7007',
            'designation_at_time' => 'E7',
            'inspection_reference' => 'INS-E7-2026-000007',
            'review_status' => 'approved',
            'completed_at' => now()->subDay(),
        ]);
        $this->defect = $this->makeDefect('Portable radio', ['apparatus_inspection_id' => $this->inspection->id]);
    }

    /* ---------------------------------------------------------------- service */

    /** @return array<string, array{0: string}> */
    public static function adminRoles(): array
    {
        return ['super_admin' => ['super_admin'], 'admin' => ['admin'], 'logistics_admin' => ['logistics_admin']];
    }

    #[DataProvider('adminRoles')]
    public function test_each_admin_role_may_create_a_service_ticket_from_a_defect(string $role): void
    {
        $actor = $this->userWithRole($role);

        $result = app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData());

        $this->assertTrue($result->created);
        $this->assertTrue(Gate::forUser($actor)->allows('createFromDefect', ApparatusServiceTicket::class));
    }

    /** @return array<string, array{0: ?string}> */
    public static function nonAdminRoles(): array
    {
        return ['training_admin' => ['training_admin'], 'member' => ['member'], 'no role' => [null]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_users_cannot_create_a_service_ticket_from_a_defect(?string $role): void
    {
        $actor = $this->userWithRole($role);

        try {
            app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData(), true);
            $this->fail('A non-administrator created a service ticket.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('apparatus_service_tickets', 0);
        $this->assertNull($this->defect->fresh()->service_ticket_id);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
    }

    public function test_it_creates_exactly_one_linked_ticket_and_changes_nothing_else(): void
    {
        $actor = $this->userWithRole('logistics_admin');

        $result = app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData());

        $ticket = ApparatusServiceTicket::query()->sole();
        $this->assertTrue($result->created);
        $this->assertSame($ticket->id, $result->ticket->id);
        $this->assertSame($this->apparatus->id, $ticket->apparatus_id);
        $this->assertSame('fleet', $ticket->origin);
        $this->assertSame('submitted', $ticket->status);
        $this->assertSame($actor->id, $ticket->created_by_user_id);
        $this->assertSame(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'mbfd:inspection-defect:'.$this->defect->id)->toString(),
            $ticket->client_submission_id,
        );
        $this->assertSame($ticket->id, $this->defect->fresh()->service_ticket_id);

        // Provenance: the defect and its source inspection are recorded on the ticket.
        $update = $ticket->updates()->sole();
        $this->assertStringContainsString("equipment issue #{$this->defect->id}", (string) $update->internal_note);
        $this->assertStringContainsString('INS-E7-2026-000007', (string) $update->internal_note);

        // Never repairs and never touches the unit's operational status.
        $defect = $this->defect->fresh();
        $this->assertFalse($defect->resolved);
        $this->assertSame('open', $defect->status);
        $this->assertNotSame('out_of_service', $defect->operational_impact);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
    }

    public function test_the_prefilled_defaults_describe_the_defect_and_its_source_inspection(): void
    {
        $defaults = app(ApparatusDefectServiceTicketService::class)->defaults($this->defect);

        $this->assertStringContainsString('Portable radio', $defaults['title']);
        $this->assertStringContainsString('E7', $defaults['title']);
        $this->assertStringContainsString('Cab', $defaults['description']);
        $this->assertStringContainsString('INS-E7-2026-000007', $defaults['description']);
        $this->assertStringContainsString('Not in assigned mount.', $defaults['description']);
        $this->assertSame('repair_mechanical', $defaults['category']);
        $this->assertSame('attention', $defaults['priority']);
    }

    public function test_repeating_the_request_is_idempotent_and_never_touches_the_unit(): void
    {
        $actor = $this->userWithRole('admin');
        $service = app(ApparatusDefectServiceTicketService::class);

        $first = $service->create($this->defect, $actor, $this->ticketData());
        $second = $service->create($this->defect->fresh(), $actor, $this->ticketData(), true);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->ticket->id, $second->ticket->id);
        $this->assertDatabaseCount('apparatus_service_tickets', 1);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 0);
    }

    public function test_it_adopts_a_ticket_already_opened_with_the_shared_deterministic_id(): void
    {
        $actor = $this->userWithRole('admin');
        $existing = app(ApparatusServiceTicketWorkflowService::class)->createFleetTicket($this->apparatus, $actor, [
            ...$this->ticketData(),
            'client_submission_id' => ApparatusDefectServiceTicketService::clientSubmissionId($this->defect),
        ])->ticket;
        $this->assertNull($this->defect->fresh()->service_ticket_id);

        $result = app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData());

        $this->assertFalse($result->created);
        $this->assertSame($existing->id, $result->ticket->id);
        $this->assertSame($existing->id, $this->defect->fresh()->service_ticket_id);
        $this->assertDatabaseCount('apparatus_service_tickets', 1);
    }

    public function test_the_out_of_service_toggle_marks_the_unit_and_records_a_ledger_event(): void
    {
        $actor = $this->userWithRole('logistics_admin');

        $result = app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData(), true);

        $this->assertSame('Out of Service', $this->apparatus->fresh()->status);
        $this->assertDatabaseHas('apparatus_operational_status_events', [
            'apparatus_id' => $this->apparatus->id,
            'previous_status' => 'In Service',
            'status' => 'Out of Service',
        ]);
        $this->assertDatabaseCount('apparatus_operational_status_events', 1);
        $this->assertSame('out_of_service', $this->defect->fresh()->operational_impact);
        $this->assertFalse($this->defect->fresh()->resolved);
        $this->assertContains(
            'apparatus_status_changed',
            $result->ticket->updates()->get()->map(fn ($update) => $update->metadata['event'] ?? null)->all(),
        );
    }

    public function test_a_resolved_defect_cannot_start_a_ticket(): void
    {
        $actor = $this->userWithRole('admin');
        $this->defect->update(['status' => 'resolved', 'resolution_notes' => 'Replaced.']);

        try {
            app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, $this->ticketData());
            $this->fail('A resolved defect started a ticket.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('defect', $exception->errors());
        }

        $this->assertDatabaseCount('apparatus_service_tickets', 0);
    }

    public function test_invalid_ticket_data_creates_nothing(): void
    {
        $actor = $this->userWithRole('admin');

        try {
            app(ApparatusDefectServiceTicketService::class)->create($this->defect, $actor, [...$this->ticketData(), 'title' => 'abc'], true);
            $this->fail('Invalid ticket data was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('apparatus_service_tickets', 0);
        $this->assertNull($this->defect->fresh()->service_ticket_id);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
    }

    /* --------------------------------------------------------- Filament UI */

    public function test_defects_relation_manager_offers_the_action_to_admins_only_while_no_ticket_exists(): void
    {
        $this->actAsPanelUser('logistics_admin');
        $resolved = $this->makeDefect('Spare hose', ['status' => 'resolved', 'resolution_notes' => 'Done.']);

        $this->defectsManager()
            ->assertTableActionVisible('create_service_ticket', $this->defect)
            ->assertTableActionHidden('view_service_ticket', $this->defect)
            ->assertTableActionHidden('create_service_ticket', $resolved);

        $this->defectsManager()
            ->mountTableAction('create_service_ticket', $this->defect)
            ->assertTableActionDataSet([
                'category' => 'repair_mechanical',
                'priority' => 'attention',
                'also_mark_out_of_service' => false,
            ]);

        $this->defectsManager()
            ->callTableAction('create_service_ticket', $this->defect, data: $this->ticketData())
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('create_service_ticket', $this->defect->fresh())
            ->assertTableActionVisible('view_service_ticket', $this->defect->fresh());

        $ticket = ApparatusServiceTicket::query()->sole();
        $this->assertSame($ticket->id, $this->defect->fresh()->service_ticket_id);
        $this->assertSame('In Service', $this->apparatus->fresh()->status);
    }

    public function test_the_out_of_service_choice_is_honoured_from_the_relation_manager(): void
    {
        $this->actAsPanelUser('admin');

        $this->defectsManager()
            ->callTableAction('create_service_ticket', $this->defect, data: [
                ...$this->ticketData(),
                'also_mark_out_of_service' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Out of Service', $this->apparatus->fresh()->status);
        $this->assertDatabaseCount('apparatus_operational_status_events', 1);
    }

    public function test_non_admin_panel_users_do_not_see_the_create_action(): void
    {
        $this->actAsPanelUser('view_only');

        $this->defectsManager()->assertTableActionHidden('create_service_ticket', $this->defect);
        $this->assertDatabaseCount('apparatus_service_tickets', 0);
    }

    public function test_defect_resource_table_and_edit_page_expose_the_same_action(): void
    {
        $this->actAsPanelUser('admin');

        Livewire::test(ListDefects::class)
            ->call('loadTable')
            ->assertTableActionVisible('create_service_ticket', $this->defect)
            ->callTableAction('create_service_ticket', $this->defect, data: $this->ticketData())
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('create_service_ticket', $this->defect->fresh())
            ->assertTableActionVisible('view_service_ticket', $this->defect->fresh());
        $this->assertDatabaseCount('apparatus_service_tickets', 1);

        $second = $this->makeDefect('Hose clamp');
        Livewire::test(EditDefect::class, ['record' => $second->getRouteKey()])
            ->assertActionVisible('create_service_ticket')
            ->assertActionHidden('view_service_ticket')
            ->callAction('create_service_ticket', data: $this->ticketData())
            ->assertHasNoActionErrors()
            ->assertActionHidden('create_service_ticket')
            ->assertActionVisible('view_service_ticket');
        $this->assertDatabaseCount('apparatus_service_tickets', 2);
        $this->assertNotNull($second->fresh()->service_ticket_id);
    }

    public function test_defect_follow_up_tabs_filter_by_the_real_data(): void
    {
        $this->actAsPanelUser('logistics_admin');
        $awaiting = $this->makeDefect('Nozzle', ['operational_impact' => 'needs_admin_review']);
        $acknowledged = $this->makeDefect('Axe', ['operational_impact' => 'needs_repair']);
        $ticketed = $this->makeDefect('Ladder', ['service_ticket_id' => $this->ticket('submitted')->id]);
        $closed = $this->makeDefect('Light', ['service_ticket_id' => $this->ticket('completed')->id]);
        $resolved = $this->makeDefect('Rope', ['status' => 'resolved', 'resolution_notes' => 'Done.']);
        $all = [$this->defect, $awaiting, $acknowledged, $ticketed, $closed, $resolved];

        $manager = $this->defectsManager();
        foreach ([
            DefectFollowUpState::NEW => $this->defect,
            DefectFollowUpState::AWAITING_REVIEW => $awaiting,
            DefectFollowUpState::ACKNOWLEDGED => $acknowledged,
            DefectFollowUpState::SERVICE_FOLLOW_UP => $ticketed,
            DefectFollowUpState::TICKET_CLOSED => $closed,
            DefectFollowUpState::RESOLVED => $resolved,
        ] as $tab => $expected) {
            $others = array_values(array_filter($all, fn (ApparatusDefect $defect): bool => $defect->id !== $expected->id));
            $manager->set('followUpTab', $tab)
                ->assertCanSeeTableRecords([$expected])
                ->assertCanNotSeeTableRecords($others);
        }

        $manager->set('followUpTab', 'all')->assertCanSeeTableRecords($all);
    }

    public function test_the_php_resolver_and_the_sql_filter_agree_on_every_state(): void
    {
        $open = $this->ticket('in_progress');
        $done = $this->ticket('cancelled');
        $rows = [
            $this->defect,
            $this->makeDefect('A', ['status' => 'in_progress']),
            $this->makeDefect('B', ['status' => 'in_progress', 'operational_impact' => 'needs_admin_review']),
            $this->makeDefect('C', ['operational_impact' => 'non_blocking']),
            $this->makeDefect('D', ['operational_impact' => 'out_of_service']),
            $this->makeDefect('E', ['operational_impact' => 'needs_admin_review']),
            $this->makeDefect('F', ['service_ticket_id' => $open->id, 'operational_impact' => 'needs_admin_review']),
            $this->makeDefect('G', ['service_ticket_id' => $done->id, 'status' => 'in_progress']),
            $this->makeDefect('H', ['status' => 'resolved', 'service_ticket_id' => $open->id, 'resolution_notes' => 'x']),
        ];

        $expected = [];
        foreach ($rows as $row) {
            $expected[DefectFollowUpState::for($row->fresh('serviceTicket'))][] = $row->id;
        }
        $this->assertEqualsCanonicalizing(
            array_keys(DefectFollowUpState::options()),
            array_keys($expected),
            'The fixture must exercise every state.',
        );

        foreach (array_keys(DefectFollowUpState::options()) as $state) {
            $ids = DefectFollowUpState::applyFilter(ApparatusDefect::query(), $state)->orderBy('id')->pluck('id')->all();
            $this->assertSame($expected[$state], $ids, "SQL filter disagrees with the resolver for {$state}.");
        }
    }

    /* -------------------------------------------------------------- profile */

    public function test_overview_counts_match_the_aggregate_data(): void
    {
        $this->makeDefect('Second open');
        $this->makeDefect('Closed out', ['status' => 'resolved', 'resolution_notes' => 'Done.']);
        $this->ticket('submitted');
        $this->ticket('waiting_for_parts');
        $this->ticket('completed');
        $latest = ApparatusInspection::query()->create([
            'apparatus_id' => $this->apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'B',
            'review_status' => 'pending_review',
            'completed_at' => now()->subHour(),
        ]);

        $overview = ApparatusOverview::for($this->apparatus);

        $this->assertSame(2, $overview->openDefects);
        $this->assertSame(2, $overview->openTickets);
        $this->assertSame($latest->id, $overview->lastInspection?->id);
    }

    public function test_apparatus_profile_shows_overview_and_the_relation_tabs_in_order(): void
    {
        $this->actAsPanelUser('logistics_admin');
        $this->ticket('submitted');

        $component = Livewire::test(ViewApparatus::class, ['record' => $this->apparatus->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('data-apparatus-overview')
            ->assertSeeInOrder(['Overview', 'Inspections', 'Service Tickets', 'Defects'])
            ->assertSeeInOrder(['Open missing / damaged items', 'Open service tickets', 'Last inspection']);

        $this->assertSame([
            InspectionsRelationManager::class,
            ServiceTicketsRelationManager::class,
            VehicleSwapReportsRelationManager::class,
            DefectsRelationManager::class,
        ], array_values(ApparatusResource::getRelations()), 'Vehicle swaps is the fourth tab, shown only to Fleet staff who can view swap reports.');
        $this->assertSame('Overview', $component->instance()->getContentTabLabel());
    }

    public function test_inspection_and_ticket_rows_link_to_their_records(): void
    {
        $this->actAsPanelUser('logistics_admin');
        $ticket = $this->ticket('submitted');

        $inspections = Livewire::test(InspectionsRelationManager::class, [
            'ownerRecord' => $this->apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])->call('loadTable');
        $this->assertSame(
            route('filament.admin.resources.apparatuses.view-inspection', [
                'record' => $this->apparatus->id,
                'inspection' => $this->inspection->id,
            ]),
            $inspections->instance()->getTable()->getRecordUrl($this->inspection),
        );

        $tickets = Livewire::test(ServiceTicketsRelationManager::class, [
            'ownerRecord' => $this->apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])->call('loadTable');
        $this->assertSame(
            ApparatusServiceTicketResource::getUrl('view', ['record' => $ticket]),
            $tickets->instance()->getTable()->getRecordUrl($ticket),
        );
    }

    /* -------------------------------------------------------------- helpers */

    private function defectsManager(): Testable
    {
        return Livewire::test(DefectsRelationManager::class, [
            'ownerRecord' => $this->apparatus,
            'pageClass' => ViewApparatus::class,
            'lazy' => false,
        ])->call('loadTable');
    }

    /** Panel users pass resource authorization via Gate::before; ticket creation stays role-based. */
    private function actAsPanelUser(string $role): void
    {
        $user = $this->userWithRole($role);
        Gate::before(function (User $candidate, string $ability): ?bool {
            if ($candidate->hasAnyRole(['admin', 'logistics_admin', 'super_admin', 'view_only'])
                && $ability !== 'createFromDefect') {
                return true;
            }

            return null;
        });
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    private function userWithRole(?string $role): User
    {
        $user = User::factory()->create();
        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        return $user;
    }

    /** @return array<string, string> */
    private function ticketData(): array
    {
        return [
            'category' => 'repair_mechanical',
            'priority' => 'attention',
            'title' => 'Portable radio missing - E7',
            'description' => 'Portable radio reported missing in Cab on E7.',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function makeDefect(string $item, array $overrides = []): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $this->apparatus->id,
            'compartment' => 'Cab',
            'item' => $item,
            'status' => 'open',
            'issue_type' => 'missing',
            'reported_date' => now()->toDateString(),
            'notes' => 'Not in assigned mount.',
            ...$overrides,
        ]);
    }

    private function ticket(string $status): ApparatusServiceTicket
    {
        $ticket = ApparatusServiceTicket::query()->create([
            'client_submission_id' => fake()->uuid(),
            'apparatus_id' => $this->apparatus->id,
            'station_id' => $this->apparatus->station_id,
            'unit_designation_snapshot' => 'E7',
            'requester_name_snapshot' => 'Fixture',
            'origin' => 'fleet',
            'category' => 'repair_mechanical',
            'title' => 'Fixture ticket '.$status,
            'description' => 'Service ticket fixture for the defect follow-up tests.',
            'priority' => 'routine',
            'status' => $status,
            'completed_at' => in_array($status, ['completed', 'cancelled'], true) ? now() : null,
        ]);

        return $ticket->refresh();
    }
}
