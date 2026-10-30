<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Enums\AccountStatus;
use App\Filament\Resources\RosterImportResource;
use App\Filament\Resources\RosterImportResource\Pages\ListRosterImports;
use App\Filament\Resources\RosterImportResource\Pages\ViewRosterImport;
use App\Health\RosterImportCheck;
use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use App\Services\DailyRoster\RosterAlerts;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterImportActions;
use App\Services\DailyRoster\RosterStateRecorder;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Health\Enums\Status;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

final class RosterImportAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', 'America/New_York'));
        config(['daily_roster.mode' => 'publish']);

        // A private root: Storage::fake() empties one shared directory, which parallel test processes share.
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-roster-admin-'.getmypid().'-'.Str::random(12);
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        $this->beforeApplicationDestroyed(static fn () => (new Filesystem)->deleteDirectory($root));
    }

    public function test_only_system_administrators_reach_the_screen(): void
    {
        self::assertFalse(RosterImportResource::canViewAny());
        $this->actingAs($this->userWithout());
        self::assertFalse(RosterImportResource::canViewAny());
        $this->actingAs($this->administrator());
        self::assertTrue(RosterImportResource::canViewAny());
    }

    public function test_the_screen_never_offers_create_edit_or_delete(): void
    {
        $this->actingAs($this->administrator());
        $import = $this->heldImport();

        self::assertFalse(RosterImportResource::canCreate());
        self::assertFalse(RosterImportResource::canEdit($import));
        self::assertFalse(RosterImportResource::canDelete($import));
        self::assertFalse(RosterImportResource::canDeleteAny());
    }

    public function test_list_and_view_render_with_plain_reasons_and_no_member_data(): void
    {
        $this->actingAs($this->administrator());
        $import = $this->heldImport(['error_code' => 'layout_changed', 'warnings' => ['unknown_code:195']]);

        Livewire::test(ListRosterImports::class)->assertOk()->assertSee('Needs review')->assertSee('TeleStaff changed the report layout');
        Livewire::test(ViewRosterImport::class, ['record' => $import->getKey()])->assertOk()->assertSee('unknown_code:195');
    }

    public function test_activate_needs_manage_and_keeps_every_snapshot(): void
    {
        $older = DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]], ['generated_at' => '2026-10-30 13:00:00']);
        $newer = DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90002', 'name' => 'TESTER, BRAVO']]], ['generated_at' => '2026-10-30 14:00:00']);
        $import = RosterImport::query()->findOrFail($older->roster_import_id);

        app(RosterImportActions::class)->activate($import, $this->userWithout(), 'Wrong export');
        self::assertSame((int) $newer->id, (int) RosterActiveVersion::query()->whereDate('duty_date', '2026-10-30')->value('roster_snapshot_id'));

        $admin = $this->administrator();
        $this->actingAs($admin);
        app(RosterImportActions::class)->activate($import, $admin, 'Wrong export');

        $pointer = RosterActiveVersion::query()->whereDate('duty_date', '2026-10-30')->sole();
        self::assertSame((int) $older->id, (int) $pointer->roster_snapshot_id);
        self::assertNotNull($pointer->pinned_at);
        self::assertSame(2, \App\Models\DailyRoster\RosterSnapshot::query()->count());
        self::assertTrue(RosterEvent::query()->where('reason', 'Wrong export')->exists());
        self::assertTrue(app(RosterImportActions::class)->canRelease($import));

        app(RosterImportActions::class)->release($import, $admin, 'Corrected');
        self::assertNull(RosterActiveVersion::query()->whereDate('duty_date', '2026-10-30')->sole()->pinned_at);
    }

    public function test_health_check_reports_a_late_roster_and_stuck_import(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        self::assertSame(Status::warning()->value, (new RosterImportCheck)->run()->status->value);

        DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        self::assertSame(Status::ok()->value, (new RosterImportCheck)->run()->status->value);

        $this->heldImport(['state' => RosterImport::STATE_QUEUED, 'state_changed_at' => now()->subHour()]);
        self::assertSame(Status::failed()->value, (new RosterImportCheck)->run()->status->value);

        config(['daily_roster.mode' => 'shadow']);
        self::assertSame(Status::ok()->value, (new RosterImportCheck)->run()->status->value);
    }

    public function test_a_failed_export_stops_counting_once_a_later_good_one_publishes(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]], ['generated_at' => now()->subHour()]);
        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);

        $this->heldImport(['state' => RosterImport::STATE_PERMANENT_FAILED, 'received_at' => now()->subHours(2)]);
        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);

        $this->heldImport(['state' => RosterImport::STATE_PERMANENT_FAILED, 'received_at' => now()]);
        self::assertSame(RosterFreshness::NEEDS_REVIEW, app(RosterFreshnessResolver::class)->status()->state);
    }

    public function test_reconciler_alerts_on_held_imports_but_not_pinned_ones_or_in_shadow(): void
    {
        Notification::fake();
        $admin = $this->administrator();
        DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        $this->heldImport(['error_code' => 'pinned']);
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 0);

        config(['daily_roster.mode' => 'shadow']);
        $this->heldImport(['error_code' => 'layout_changed']);
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 0);

        config(['daily_roster.mode' => 'publish']);
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);
    }

    public function test_alert_goes_once_per_kind_and_date(): void
    {
        Notification::fake();
        $admin = $this->administrator();

        app(RosterAlerts::class)->send('delayed', '2026-10-30');
        app(RosterAlerts::class)->send('delayed', '2026-10-30');
        app(RosterAlerts::class)->send('delayed', '2026-10-31');

        // The push copy only reaches enrolled devices; the in-app copy always goes.
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 2);
    }

    public function test_a_rejected_import_has_its_own_alert_so_it_cannot_use_up_the_held_one(): void
    {
        Notification::fake();
        $admin = $this->administrator();
        DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        $this->heldImport(['state' => RosterImport::STATE_REJECTED, 'duty_date' => '2026-10-30']);

        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);

        $this->heldImport(['duty_date' => '2026-10-30', 'error_code' => 'layout_changed']);
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 2);
    }

    public function test_a_late_alert_that_did_not_get_out_is_tried_again_on_the_next_tick_with_one_event(): void
    {
        Notification::fake();
        Exceptions::fake();
        $admin = $this->administrator();
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);

        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);

        // Deduplicated while the key stands; a failed send removes the key.
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);

        Cache::forget('daily_roster_alert:delayed:2026-10-30');
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
        Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 2);

        self::assertSame(1, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->count());
        Exceptions::assertReportedCount(1);
    }

    public function test_no_late_alert_goes_before_anything_has_ever_been_published(): void
    {
        Notification::fake();
        $admin = $this->administrator();
        self::assertSame(RosterFreshness::NONE, app(RosterFreshnessResolver::class)->status()->state);

        $this->artisan('daily-roster:reconcile')->assertExitCode(0);

        Notification::assertNothingSentTo($admin);
        self::assertSame(0, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->count());
    }

    public function test_a_cache_outage_never_stops_the_caller(): void
    {
        Exceptions::fake();
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache_down'));
        Cache::shouldReceive('forget')->once()->andThrow(new RuntimeException('cache_down'));

        app(RosterAlerts::class)->send('delayed', '2026-10-30');

        Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === 'cache_down');
    }

    public function test_an_unknown_alert_kind_sends_nothing(): void
    {
        Notification::fake();
        $admin = $this->administrator();

        app(RosterAlerts::class)->send('something_else', '2026-10-30');

        Notification::assertNothingSentTo($admin);
    }

    public function test_confirm_genuine_and_retry_needs_a_reason_and_shows_only_when_it_applies(): void
    {
        Bus::fake();
        $this->actingAs($this->administrator());
        $held = $this->storedImport(['classification' => ['verdict' => 'needs_review', 'reasons' => ['dkim_not_aligned'], 'evidence' => ['envelope_from' => 'a@miamibeachfl.gov']]]);
        $passed = $this->storedImport(['state' => RosterImport::STATE_PERMANENT_FAILED, 'classification' => ['verdict' => 'match', 'reasons' => []]]);

        Livewire::test(ListRosterImports::class)
            ->assertTableActionVisible('retryAttested', $held)
            ->assertTableActionHidden('retry', $held)
            ->assertTableActionHidden('retryAttested', $passed)
            ->assertTableActionVisible('retry', $passed);

        Livewire::test(ViewRosterImport::class, ['record' => $held->getKey()])
            ->assertActionVisible('retryAttested')
            ->assertActionHidden('retry')
            ->assertSee('a@miamibeachfl.gov')
            ->callAction('retryAttested', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $held->fresh()->state);

        Livewire::test(ViewRosterImport::class, ['record' => $held->getKey()])
            ->callAction('retryAttested', data: ['reason' => 'Confirmed with the City'])
            ->assertHasNoActionErrors();
        $held = $held->fresh();
        self::assertSame(RosterImport::STATE_QUEUED, $held->state);
        self::assertSame('Confirmed with the City', $held->classification['attestation_reason']);
        Bus::assertDispatched(ProcessRosterImport::class);

        Livewire::test(ViewRosterImport::class, ['record' => $held->getKey()])->assertOk()->assertSee('Confirmed with the City');
    }

    public function test_a_member_who_can_only_view_is_offered_neither_action(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $viewer->givePermissionTo(Permission::findOrCreate('admin.system.view', 'web'));
        $this->actingAs($viewer);
        $held = $this->storedImport(['classification' => ['verdict' => 'rejected', 'reasons' => ['near_miss']], 'state' => RosterImport::STATE_REJECTED, 'cleanup_state' => RosterImport::CLEANUP_FAILED]);

        Livewire::test(ViewRosterImport::class, ['record' => $held->getKey()])
            ->assertActionHidden('retryAttested')
            ->assertActionHidden('retryCleanup');
    }

    public function test_retry_email_cleanup_clears_the_health_check_once_it_succeeds(): void
    {
        Bus::fake();
        config(['daily_roster.cleanup_enabled' => true]);
        $this->actingAs($this->administrator());
        DailyRosterSnapshotFactory::publish('2026-10-30', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        $failed = $this->storedImport(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_FAILED, 'cleanup_attempts' => 5, 'error_code' => 'cleanup_error']);

        $check = (new RosterImportCheck)->run();
        self::assertSame(Status::failed()->value, $check->status->value);
        self::assertSame(1, $check->meta['cleanup_failed']);

        Livewire::test(ListRosterImports::class)->assertTableActionVisible('retryCleanup', $failed);
        Livewire::test(ViewRosterImport::class, ['record' => $failed->getKey()])
            ->callAction('retryCleanup')
            ->assertHasNoActionErrors();

        Bus::assertDispatched(CleanupRosterImport::class);
        self::assertSame(RosterImport::CLEANUP_ROW_DELETED, $failed->fresh()->cleanup_state);
        $check = (new RosterImportCheck)->run();
        self::assertSame(0, $check->meta['cleanup_failed']);
        self::assertSame(Status::ok()->value, $check->status->value);
    }

    /** @param array<string, mixed> $attributes */
    private function storedImport(array $attributes = []): RosterImport
    {
        $messageId = (string) Str::uuid();
        $path = 'inbound-email-attachments/'.hash('sha256', $messageId).'/roster.xlsx';
        Storage::disk('local')->put($path, "PK\x03\x04synthetic");

        return $this->heldImport([
            'message_id_sha256' => hash('sha256', $messageId),
            'attachment_disk' => 'local',
            'attachment_path' => $path,
            'attachment_sha256' => hash('sha256', "PK\x03\x04synthetic"),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function heldImport(array $attributes = []): RosterImport
    {
        return RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_NEEDS_REVIEW,
            'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', (string) Str::uuid()),
            'sender' => 'donotreply@miamibeachfl.gov',
            'subject' => 'Roster Report',
            'filename' => 'Dailyroster.xlsx',
            'received_at' => now(),
            ...$attributes,
        ]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.system.view', 'admin.system.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');

        return $user;
    }

    private function userWithout(): User
    {
        return User::factory()->create();
    }
}
