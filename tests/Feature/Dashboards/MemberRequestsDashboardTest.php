<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Pages\IssueGroupPage;
use App\Filament\Pages\MemberRequestsDashboard;
use App\Filament\Pages\VehicleCheckoutDashboard;
use App\Livewire\Dashboards\DashboardBoard;
use App\Models\Employee;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestLine;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\PersonnelRequestUpdate;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\Activity\OperationsActivityPreferences;
use App\Services\Dashboards\DashboardAccess;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\Sources\Requests\MedicalSupplyRequestSource;
use App\Services\Dashboards\Sources\Requests\NarcoticsCheckSource;
use App\Services\Dashboards\Sources\Requests\PersonnelRequestSource;
use App\Services\Dashboards\Sources\Station\StationSupplyRequestSource;
use App\Services\Security\ApplicationAccessService;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

/** The Member Requests board (4 of 4), its sources and the Overview's Requests lane. */
final class MemberRequestsDashboardTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;
    use StationDashboardFixtures;

    private User $admin;

    private int $requestSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:30:00', 'America/New_York'));
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create(['account_status' => 'active', 'name' => 'Pat Example', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAsCanonicalUser($this->admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  list<array{0: string, 1: ?string, 2: int, 3?: int}>  $items  [name, size, quantity, issued]
     * @param  array<string, mixed>  $attributes
     */
    private function personnelRequest(Employee $member, string $type, PersonnelRequestStatus $status, array $items = [['Class B shirt', 'L', 2]], array $attributes = [], ?Carbon $at = null): PersonnelRequest
    {
        $publicId = (string) Str::ulid();
        $at ??= now()->subHours(5);
        $request = PersonnelRequest::query()->create($attributes + [
            'public_id' => $publicId, 'request_number' => sprintf('UR-%04d', ++$this->requestSeq),
            'type' => $type, 'status' => $status,
            'beneficiary_employee_id' => $member->id, 'requester_employee_id' => $member->id,
            'beneficiary_name' => $member->name, 'beneficiary_employee_number' => $member->employee_id,
            'requester_name' => $member->name, 'requester_employee_number' => $member->employee_id,
            'idempotency_key' => 'fixture-'.$publicId,
        ]);
        $request->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        foreach ($items as $index => $item) {
            PersonnelRequestItem::query()->create([
                'personnel_request_id' => $request->id, 'category' => $type, 'item_code' => 'item-'.$index, 'item_name' => $item[0], 'size' => $item[1],
                'quantity' => $item[2], 'fulfilled_quantity' => $item[3] ?? 0, 'fulfillment_status' => ($item[3] ?? 0) >= $item[2] ? 'fulfilled' : 'unfulfilled',
            ]);
        }

        return $request->refresh();
    }

    private function medicalRequest(string $status = InventoryRequest::SUBMITTED, ?User $member = null): InventoryRequest
    {
        $request = InventoryRequest::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => sprintf('MIR-%06d', ++$this->requestSeq), 'status' => $status,
            'submitted_by_user_id' => $member?->id, 'submitter_name' => $member->name ?? 'Casey Medic', 'submitted_at' => now()->subHours(3),
            'target_kind' => InventoryRequest::TARGET_DETAIL, 'target_label' => 'Rescue 22',
            'client_submission_id' => (string) Str::uuid(), 'source_form_versions' => [], 'snapshot' => [], 'snapshot_sha256' => str_repeat('a', 64),
        ]);
        InventoryRequestLine::query()->create([
            'request_id' => $request->id, 'bag_key' => 'bag', 'item_key' => 'gauze-'.$this->requestSeq, 'label' => 'Gauze 4x4',
            'response_type' => 'quantity', 'requested_qty' => 10, 'issued_qty' => 0, 'unit' => 'each', 'sort' => 0,
        ]);

        return $request->refresh();
    }

    private function narcoticsCheck(string $status = 'pending', ?User $member = null): int
    {
        return (int) DB::table('narcotics_checks')->insertGetId([
            'uuid' => (string) Str::uuid(), 'status' => $status, 'submitted_by_user_id' => $member?->id,
            'submitter_name' => $member->name ?? 'Robin Medic', 'submitted_at' => now()->subHours(2), 'client_submission_id' => (string) Str::uuid(),
            'rescue_unit' => 'R22', 'seal_number' => 'A-1234',
            'image_disk' => 'local', 'image_path' => 'x/full.webp', 'preview_path' => 'x/preview.webp', 'thumb_path' => 'x/thumb.webp',
            'image_mime' => 'image/webp', 'image_width' => 10, 'image_height' => 10, 'image_bytes' => 100, 'image_sha256' => str_repeat('b', 64),
            'decided_at' => $status === 'pending' ? null : now()->subHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(DashboardView $view, string $filter = 'all', ?User $user = null): DashboardSnapshot
    {
        return app(DashboardReadModel::class)->load($user ?? $this->admin, $view, $filter, fresh: true);
    }

    private static function stat(string $key, int $value): string
    {
        return '#data-stat="'.$key.'".*?mbfd-dash-stat__value">'.$value.'<#s';
    }

    // ---------------------------------------------------------------- pages

    public function test_the_fourth_board_has_a_url_tab_strip_and_pager_position(): void
    {
        $this->assertSame('/admin/dashboards/member-requests', parse_url(MemberRequestsDashboard::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame(DashboardView::MemberRequests, DashboardView::StationOperations->next());

        $html = $this->get('/admin/dashboards/member-requests')->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="mbfd-dash-header__title">Member Requests Dashboard</h1>', $html);
        $this->assertStringContainsString('4 of 4', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/station-operations"[^>]*rel="prev"[^>]*aria-label="Previous dashboard: Station Operations \(3 of 4\)"#', $html);
        $this->assertMatchesRegularExpression('#aria-disabled="true"[^>]*aria-label="No next dashboard"#', $html);
        $this->assertStringContainsString('mbfd-dash-fit', $html);
        foreach (['overview', 'vehicle-checkout', 'station-operations', 'member-requests'] as $tab) {
            $this->assertStringContainsString('data-dashboard-tab="'.$tab.'"', $html);
        }

        $overview = $this->get('/admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/member-requests" class="mbfd-dash-pager__tab"#', $overview);
        $this->assertSame('Operations', MemberRequestsDashboard::getNavigationGroup());
    }

    public function test_stats_queue_and_rows_show_member_items_sizes_status_and_age(): void
    {
        $jordan = $this->employee('Jordan Lee');
        $sam = $this->employee('Sam Ortiz');
        $alex = $this->employee('Alex Kim');
        $this->personnelRequest($jordan, 'uniform', PersonnelRequestStatus::Pending, [['Class B shirt', 'L', 2], ['Trousers', '34x32', 1]], at: now()->subDays(3));
        $this->personnelRequest($sam, 'equipment', PersonnelRequestStatus::Acknowledged, [['Structural gloves', 'XL', 1]]);
        $this->personnelRequest($sam, 'uniform', PersonnelRequestStatus::Ordered);
        $this->personnelRequest($alex, 'uniform', PersonnelRequestStatus::ReadyForPickup);
        $this->personnelRequest($alex, 'uniform', PersonnelRequestStatus::NeedsInformation);
        $this->personnelRequest($alex, 'uniform', PersonnelRequestStatus::Completed, [['Job shirt', 'M', 1, 1]], ['completed_at' => now()->subHour()]);

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests'])->html();

        $this->assertMatchesRegularExpression(self::stat('open', 5), $html);
        $this->assertMatchesRegularExpression(self::stat('awaiting_response', 1), $html);
        $this->assertMatchesRegularExpression(self::stat('awaiting_pickup', 2), $html);
        $this->assertMatchesRegularExpression(self::stat('resolved_today', 1), $html);
        $this->assertStringNotContainsString('↑', $html);

        // One row per member; a single request shows its items with sizes, status badge and how long it has waited.
        $this->assertStringContainsString('Jordan Lee', $html);
        $this->assertStringContainsString('Class B shirt (L) ×2, Trousers (34x32) ×1', $html);
        $this->assertMatchesRegularExpression('#data-request-status>Pending<#', $html);
        $this->assertStringContainsString('Waiting 3 d', $html);
        $this->assertStringContainsString('2 open requests', $html);
        $this->assertStringContainsString('Sam Ortiz', $html);
        // Sam has no Hub sign-in: his two requests still share one row, which opens the page for both.
        $this->assertStringContainsString('data-group-key="employee:'.$sam->id.'"', $html);
        $this->get(IssueGroupPage::getUrl(['group' => 'employee:'.$sam->id], panel: 'admin'))->assertOk()
            ->assertSee('Uniform and PPE requests')->assertSee('Structural gloves (XL) ×1');

        // Stage queue: exact counts by real status.
        $this->assertStringContainsString('Where requests stand', $html);
        $this->assertMatchesRegularExpression('#Waiting on the member</span>\s*(<!--.*?-->\s*)*<span class="mbfd-dash-queue__count"[^>]*>1<#', $html);
        $this->assertMatchesRegularExpression('#Ordered or awaiting pickup</span>\s*(<!--.*?-->\s*)*<span class="mbfd-dash-queue__count"[^>]*>2<#', $html);
        $this->assertStringNotContainsString('data-stage="member_replied"', $html);
    }

    public function test_filter_chips_narrow_to_one_kind_and_count_open_requests(): void
    {
        $member = $this->employee('Jordan Lee');
        $other = $this->employee('Sam Ortiz');
        $this->personnelRequest($member, 'uniform', PersonnelRequestStatus::Pending, [['Class B shirt', 'L', 2]]);
        $this->personnelRequest($other, 'equipment', PersonnelRequestStatus::Pending, [['Structural gloves', 'XL', 1]]);
        $this->medicalRequest();

        $board = Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests']);
        $chip = static fn (string $label, int $count): string => '#'.$label.'</span>\s*<span class="mbfd-dash-chip__count">· '.$count.'<#s';
        $html = $board->html();
        $this->assertMatchesRegularExpression($chip('All', 3), $html);
        $this->assertMatchesRegularExpression($chip('Uniforms', 1), $html);
        $this->assertMatchesRegularExpression($chip('PPE / equipment', 1), $html);
        $this->assertMatchesRegularExpression($chip('Medical supplies', 1), $html);
        $this->assertMatchesRegularExpression($chip('Needs review', 3), $html);
        $this->assertStringNotContainsString('Narcotics checks</span>', $html);

        $board->call('setFilter', 'ppe')->assertSet('filter', 'ppe')
            ->assertSee('Structural gloves (XL) ×1')
            ->assertDontSee('Class B shirt (L) ×2')
            ->assertDontSee('Gauze 4x4');
        $board->call('setFilter', 'medical')->assertSee('Gauze 4x4 ×10')->assertDontSee('Structural gloves');
    }

    public function test_overview_has_a_requests_lane_and_chip_that_shows_it_alone(): void
    {
        $this->personnelRequest($this->employee('Jordan Lee'), 'uniform', PersonnelRequestStatus::Pending);
        $this->medicalRequest();

        $board = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview']);
        $html = $board->html();
        $this->assertStringContainsString('data-card="lane-requests"', $html);
        $this->assertStringContainsString('data-lanes="4"', $html);
        $this->assertMatchesRegularExpression('#Requests</span>\s*<span class="mbfd-dash-chip__count">· 2<#s', $html);
        $this->assertStringContainsString('Class B shirt (L) ×2', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/member-requests" class="mbfd-dash-card__link"#', $html);

        $only = $board->call('setFilter', 'requests')->html();
        $this->assertStringContainsString('data-card="lane-requests"', $only);
        foreach (['lane-vehicle', 'lane-station', 'lane-messages'] as $other) {
            $this->assertStringNotContainsString('data-card="'.$other.'"', $only);
        }
    }

    public function test_recently_completed_requests_stay_72_hours_after_the_open_ones(): void
    {
        $open = $this->employee('Olive Open');
        $recent = $this->employee('Rita Recent');
        $old = $this->employee('Otto Old');
        $this->personnelRequest($open, 'uniform', PersonnelRequestStatus::Pending, at: now()->subDays(2));
        $this->personnelRequest($recent, 'uniform', PersonnelRequestStatus::Completed, [['Job shirt', 'M', 1, 1]], ['completed_at' => now()->subHours(70)]);
        $this->personnelRequest($old, 'uniform', PersonnelRequestStatus::Denied, [['Job shirt', 'M', 1]], ['denied_at' => now()->subHours(80)]);

        $snapshot = $this->snapshot(DashboardView::MemberRequests);
        $subjects = array_map(static fn ($row): ?string => $row->subject, $snapshot->rowsIn(DashboardSnapshot::LANE_REQUESTS));
        $this->assertContains('Rita Recent', $subjects);
        $this->assertNotContains('Otto Old', $subjects);
        // Completed 70 hours ago is shown but is not "completed today" (that is the operational day).
        $this->assertSame(0, $snapshot->count('resolved_today', DashboardSnapshot::LANE_REQUESTS));

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests'])->html();
        $this->assertLessThan(strpos($html, 'Rita Recent'), strpos($html, 'Olive Open'));
    }

    public function test_the_overview_sort_preference_also_orders_the_requests_lane(): void
    {
        $this->personnelRequest($this->employee('Older Member'), 'uniform', PersonnelRequestStatus::Pending, at: now()->subDays(4));
        $this->personnelRequest($this->employee('Newer Member'), 'uniform', PersonnelRequestStatus::Pending, at: now()->subHours(2));

        $default = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->html();
        $this->assertLessThan(strpos($default, 'Older Member'), strpos($default, 'Newer Member'));

        Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->set('issueSort', 'oldest_unresolved');
        $this->assertSame('oldest_unresolved', app(OperationsActivityPreferences::class)->issueSort($this->admin));
        Cache::flush();
        $sorted = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->assertSet('issueSort', 'oldest_unresolved')->html();
        $this->assertLessThan(strpos($sorted, 'Newer Member'), strpos($sorted, 'Older Member'));
    }

    // ------------------------------------------------------------- sources

    public function test_personnel_actions_run_through_the_workflow_service(): void
    {
        $member = $this->employee('Jordan Lee');
        $request = $this->personnelRequest($member, 'uniform', PersonnelRequestStatus::Pending);
        $source = app(PersonnelRequestSource::class);
        $detail = $source->detail($this->admin, (string) $request->id);

        $keys = array_map(static fn ($action): string => $action->key, $detail->actions);
        $this->assertContains('acknowledge', $keys);
        $this->assertContains('deny', $keys);
        $this->assertNotContains('complete', $keys, 'Complete waits until every item is issued.');
        $this->assertSame('Class B shirt (L) ×2', $detail->issue->title);

        $result = $source->perform($this->admin, (string) $request->id, 'acknowledge', ['employee_note' => 'Got it.'], $detail->issue->version);
        $this->assertSame('done', $result->outcome);
        $this->assertSame(PersonnelRequestStatus::Acknowledged, $request->fresh()->status);
        $this->assertSame('Got it.', PersonnelRequestUpdate::query()->where('personnel_request_id', $request->id)->latest('id')->value('employee_visible_note'));
        // The same click again is a no-op, not a conflict.
        $this->assertSame('Already saved.', $source->perform($this->admin, (string) $request->id, 'acknowledge', ['employee_note' => 'Got it.'], $detail->issue->version)->message);

        $fresh = $source->detail($this->admin, (string) $request->id);
        $this->assertSame('invalid', $source->perform($this->admin, (string) $request->id, 'deny', ['employee_note' => 'no'], $fresh->issue->version)->outcome);
        $this->assertSame('conflict', $source->perform($this->admin, (string) $request->id, 'order', [], 'stale')->outcome);
        $this->assertSame('done', $source->perform($this->admin, (string) $request->id, 'order', [], $fresh->issue->version)->outcome);
        $this->assertSame(PersonnelRequestStatus::Ordered, $request->fresh()->status);
    }

    public function test_member_replied_is_read_from_the_members_own_response(): void
    {
        $member = $this->employee('Jordan Lee');
        $request = $this->personnelRequest($member, 'equipment', PersonnelRequestStatus::Acknowledged, [['Helmet', null, 1]]);
        PersonnelRequestUpdate::query()->create([
            'personnel_request_id' => $request->id, 'event' => 'employee_responded', 'status' => PersonnelRequestStatus::Acknowledged,
            'employee_visible_note' => 'Here is the case number.', 'changed_by_employee_id' => $member->id,
        ]);
        $source = app(PersonnelRequestSource::class);

        $rows = $source->rows($this->admin, DashboardQuery::now(), 50);
        $this->assertContains('member_replied', $rows[0]->tags);
        $this->assertSame(1, $source->counts($this->admin, DashboardQuery::now())['member_replied']);
        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests'])->html();
        $this->assertStringContainsString('data-stage="member_replied"', $html);
        $this->assertStringContainsString('data-member-replied>Member replied</span>', $html);
        $this->assertMatchesRegularExpression('#Member replied</span>\s*<span class="mbfd-dash-chip__count">· 1<#s', $html);

        // Staff answering clears it.
        $detail = $source->detail($this->admin, (string) $request->id);
        $source->perform($this->admin, (string) $request->id, 'add_note', ['employee_note' => 'Thanks, ordering now.'], $detail->issue->version);
        $this->assertSame(0, $source->counts($this->admin, DashboardQuery::now())['member_replied']);
    }

    public function test_member_replied_also_reads_the_shared_awaiting_staff_flag(): void
    {
        $request = $this->medicalRequest();
        $source = app(MedicalSupplyRequestSource::class);
        $this->assertNotContains('member_replied', $source->rows($this->admin, DashboardQuery::now(), 50)[0]->tags);

        \Illuminate\Support\Facades\DB::table('submission_reply_states')->insert([
            'subject_type' => \App\Support\MemberReplies\ReplySubject::EMS_INVENTORY_REQUEST, 'subject_id' => $request->id,
            'awaiting_staff_reply' => true, 'last_member_reply_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertContains('member_replied', $source->rows($this->admin, DashboardQuery::now(), 50)[0]->tags);
        $this->assertSame(1, $source->counts($this->admin, DashboardQuery::now())['member_replied']);

        \Illuminate\Support\Facades\DB::table('submission_reply_states')->update(['awaiting_staff_reply' => false]);
        $this->assertNotContains('member_replied', $source->rows($this->admin, DashboardQuery::now(), 50)[0]->tags);
        $this->assertSame(0, $source->counts($this->admin, DashboardQuery::now())['member_replied']);
    }

    public function test_completed_personnel_requests_can_be_archived_off_the_board(): void
    {
        $request = $this->personnelRequest($this->employee('Jordan Lee'), 'uniform', PersonnelRequestStatus::Completed, [['Job shirt', 'M', 1, 1]], ['completed_at' => now()->subHour()]);
        $source = app(PersonnelRequestSource::class);
        $detail = $source->detail($this->admin, (string) $request->id);
        $this->assertSame(['archive'], array_map(static fn ($action): string => $action->key, $detail->actions));

        $this->assertSame('done', $source->perform($this->admin, (string) $request->id, 'archive', [], $detail->issue->version)->outcome);
        $this->assertNotNull($request->fresh()->archived_at);
        $this->assertSame([], $source->rows($this->admin, DashboardQuery::now(), 50));
    }

    public function test_medical_supply_requests_acknowledge_and_go_back_to_the_member(): void
    {
        $medic = $this->member('Casey Medic');
        $request = $this->medicalRequest(member: $medic);
        $source = app(MedicalSupplyRequestSource::class);

        $row = $source->rows($this->admin, DashboardQuery::now(), 50)[0];
        $this->assertSame('awaiting_response', $row->stage);
        $this->assertSame('Gauze 4x4 ×10', $row->title);
        $this->assertSame('member:'.$medic->id, $row->groupKey);
        $this->assertSame(['medical'], $row->tags);

        $detail = $source->detail($this->admin, (string) $request->id);
        $this->assertSame(['acknowledge', 'return_to_member'], array_map(static fn ($action): string => $action->key, $detail->actions));
        $this->assertSame('done', $source->perform($this->admin, (string) $request->id, 'acknowledge', [], $detail->issue->version)->outcome);
        $this->assertSame(InventoryRequest::REVIEWED, $request->fresh()->status);

        $fresh = $source->detail($this->admin, (string) $request->id);
        $this->assertSame('done', $source->perform($this->admin, (string) $request->id, 'return_to_member', ['reason' => 'Count the gauze again.'], $fresh->issue->version)->outcome);
        $this->assertSame(InventoryRequest::RETURNED, $request->fresh()->status);
        $returned = $source->rows($this->admin, DashboardQuery::now(), 50)[0];
        $this->assertSame('in_progress', $returned->stage);
        $this->assertContains('waiting_member', $returned->tags);
    }

    public function test_narcotics_checks_are_listed_read_only_with_the_review_link(): void
    {
        $id = $this->narcoticsCheck(member: $this->member('Robin Medic'));
        $this->narcoticsCheck('approved');
        $source = app(NarcoticsCheckSource::class);

        $rows = $source->rows($this->admin, DashboardQuery::now(), 50);
        $this->assertCount(2, $rows);
        $this->assertSame('Narcotics check for R22, waiting for review', $rows[0]->title);
        $this->assertSame(1, $source->counts($this->admin, DashboardQuery::now())['open']);

        $detail = $source->detail($this->admin, (string) $id);
        $this->assertSame([], $detail->actions);
        $this->assertStringContainsString('Captain 5 narcotics review', (string) $detail->readOnlyReason);
        $this->assertSame('/captain-5', $detail->issue->sourceUrl);
        $this->assertSame('invalid', $source->perform($this->admin, (string) $id, 'approve', [], $detail->issue->version)->outcome);
        $this->assertSame('pending', DB::table('narcotics_checks')->where('id', $id)->value('status'));
    }

    public function test_station_supply_requests_show_on_station_operations_and_the_overview(): void
    {
        $manager = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $request = StationSupplyRequest::query()->create([
            'station_id' => $this->station(3)->id, 'request_text' => 'Paper towels and dish soap', 'status' => 'open', 'created_by_name' => 'Lee Member', 'created_by_shift' => 'A',
        ]);
        $registry = app(IssueSourceRegistry::class);
        $this->assertContains($registry->get('station_supply_request'), $registry->forView(DashboardView::StationOperations));
        $this->assertContains($registry->get('station_supply_request'), $registry->forView(DashboardView::Overview));

        $snapshot = $this->snapshot(DashboardView::StationOperations, user: $manager);
        $this->assertSame(1, $snapshot->count('supplies'));
        $this->assertSame('station:'.$this->station(3)->id, $snapshot->rows[0]->groupKey);
        $this->assertSame(DashboardSnapshot::LANE_STATION, $this->snapshot(DashboardView::Overview, user: $manager)->laneFor($snapshot->rows[0]));

        $source = app(StationSupplyRequestSource::class);
        $detail = $source->detail($manager, (string) $request->id);
        $this->assertSame('invalid', $source->perform($manager, (string) $request->id, 'deny', ['public_response' => 'No'], $detail->issue->version)->outcome);
        $this->assertSame('done', $source->perform($manager, (string) $request->id, 'order', ['public_response' => 'Ordered today.'], $detail->issue->version)->outcome);
        $this->assertSame('ordered', $request->fresh()->status);
        $this->assertSame('Ordered today.', $request->fresh()->public_response);

        $viewer = $this->adminWith(['admin.stations.view']);
        $this->assertSame('forbidden', $source->perform($viewer, (string) $request->id, 'replenish', [], $source->detail($viewer, (string) $request->id)->issue->version)->outcome);
    }

    // -------------------------------------------------------------- access

    public function test_uniform_managers_get_the_board_and_operations_only_admins_do_not(): void
    {
        $this->personnelRequest($this->employee('Jordan Lee'), 'uniform', PersonnelRequestStatus::Pending);

        $uniform = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $uniform->givePermissionTo(ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($uniform->refresh());
        $this->assertTrue(MemberRequestsDashboard::canAccess());
        $this->assertFalse(VehicleCheckoutDashboard::canAccess());
        $html = $this->get('/admin/dashboards/member-requests')->assertOk()->getContent();
        $this->assertStringContainsString('Class B shirt (L) ×2', Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests'])->html());
        $this->assertStringNotContainsString('data-dashboard-tab="vehicle-checkout"', $html);
        // Their home page links straight to it, past the boards they cannot open.
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/member-requests" class="mbfd-dash-pager__tab"#', $this->get('/admin')->getContent());

        $fleet = $this->adminWith(['admin.fleet.view']);
        $this->actingAs($fleet);
        $this->assertFalse(DashboardAccess::canView($fleet, DashboardView::MemberRequests));
        $this->get('/admin/dashboards/member-requests')->assertForbidden();
        Livewire::test(DashboardBoard::class, ['dashboard' => 'member-requests'])->assertForbidden();
        $overview = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->html();
        $this->assertStringNotContainsString('data-card="lane-requests"', $overview);
        $this->assertStringNotContainsString('Requests</span>', $overview);
        $this->assertStringContainsString('data-lanes="3"', $overview);

        $member = $this->member('Plain Member');
        $this->actingAs($member);
        $this->get('/admin/dashboards/member-requests')->assertForbidden();
    }

    public function test_request_sources_never_reach_the_wall_display(): void
    {
        $this->personnelRequest($this->employee('Jordan Lee'), 'uniform', PersonnelRequestStatus::Pending);
        foreach ([PersonnelRequestSource::class, MedicalSupplyRequestSource::class, NarcoticsCheckSource::class] as $class) {
            $this->assertFalse(app($class)->visibleTo($this->admin, true), $class);
        }
        $this->assertStringNotContainsString('Jordan Lee', $this->get('/admin?display=1')->assertOk()->getContent());
    }
}
