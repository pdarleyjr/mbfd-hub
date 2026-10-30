<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\User;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublisher;
use App\Services\DailyRoster\RosterRollbackService;
use App\Services\DailyRoster\RosterStateRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterRollbackServiceTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    private RosterImport $first;

    private RosterImport $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
        $this->first = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $this->second = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'B']));
    }

    public function test_activation_re_points_pins_and_audits_without_deleting_or_lowering_the_effective_time(): void
    {
        $admin = $this->admin();

        app(RosterRollbackService::class)->activate($admin, self::DATE, (int) $this->first->roster_snapshot_id, 'Wrong export sent');

        $pointer = $this->pointerFor(self::DATE);
        self::assertSame((int) $this->first->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertNotNull($pointer->pinned_at);
        self::assertSame((int) $admin->id, (int) $pointer->pinned_by_user_id);
        self::assertTrue($pointer->effective_generated_at->equalTo('2026-10-30 13:20:00'));
        self::assertSame(2, RosterSnapshot::query()->count());
        self::assertSame((int) $this->first->roster_snapshot_id, (int) app(CurrentRosterReader::class)->current()->snapshot->id);

        $event = RosterEvent::query()->where('action', RosterStateRecorder::ACTION_ROLLBACK_ACTIVATED)->sole();
        self::assertSame('Wrong export sent', $event->reason);
        self::assertSame((int) $admin->id, (int) $event->actor_user_id);
        self::assertSame(self::DATE, $event->duty_date->format('Y-m-d'));
        self::assertSame((int) $this->second->roster_snapshot_id, (int) $event->context['from_snapshot_id']);
        self::assertSame((int) $this->first->roster_snapshot_id, (int) $event->context['to_snapshot_id']);
    }

    public function test_a_pinned_date_holds_new_exports(): void
    {
        app(RosterRollbackService::class)->activate($this->admin(), self::DATE, (int) $this->first->roster_snapshot_id, 'Wrong export sent');

        $newer = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:25:00', 'variant' => 'C']));

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $newer->state);
        self::assertSame(RosterPublisher::HOLD_PINNED, $newer->error_code);
        self::assertSame((int) $this->first->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
    }

    public function test_release_clears_the_pin_without_applying_anything_held(): void
    {
        $admin = $this->admin();
        $service = app(RosterRollbackService::class);
        $service->activate($admin, self::DATE, (int) $this->first->roster_snapshot_id, 'Wrong export sent');
        $held = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:25:00', 'variant' => 'C']));

        $service->release($admin, self::DATE, 'Resolved with the department');

        $pointer = $this->pointerFor(self::DATE);
        self::assertNull($pointer->pinned_at);
        self::assertNull($pointer->pinned_by_user_id);
        self::assertSame((int) $this->first->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $held->fresh()->state);
        self::assertSame('Resolved with the department', RosterEvent::query()->where('action', RosterStateRecorder::ACTION_ROLLBACK_RELEASED)->sole()->reason);
    }

    public function test_after_release_a_newer_export_publishes_and_an_older_one_still_cannot_win(): void
    {
        $admin = $this->admin();
        $service = app(RosterRollbackService::class);
        $service->activate($admin, self::DATE, (int) $this->first->roster_snapshot_id, 'Wrong export sent');
        $service->release($admin, self::DATE, 'Resolved');

        $older = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:15:00', 'variant' => 'OLD']));
        self::assertSame(RosterImport::STATE_SUPERSEDED, $older->state, 'The rollback did not lower the effective time.');

        $newer = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:25:00', 'variant' => 'NEW']));
        self::assertSame(RosterImport::STATE_PUBLISHED, $newer->state);
        self::assertSame((int) $newer->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
    }

    public function test_releasing_an_unpinned_date_does_nothing(): void
    {
        app(RosterRollbackService::class)->release($this->admin(), self::DATE, 'Nothing to do');

        self::assertSame(0, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_ROLLBACK_RELEASED)->count());
    }

    public function test_it_requires_the_system_manage_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('admin.system.view', 'web'));
        $service = app(RosterRollbackService::class);

        try {
            $service->activate($viewer, self::DATE, (int) $this->first->roster_snapshot_id, 'Attempt');
            self::fail('A viewer must not be able to roll back.');
        } catch (AuthorizationException) {
        }
        try {
            $service->release($viewer, self::DATE, 'Attempt');
            self::fail('A viewer must not be able to release.');
        } catch (AuthorizationException) {
        }

        self::assertSame((int) $this->second->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        self::assertNull($this->pointerFor(self::DATE)->pinned_at);
    }

    public function test_it_refuses_a_snapshot_from_another_date(): void
    {
        $other = $this->published(RosterPipelineFixtures::report('2026-10-29'));

        $this->expectException(RosterPipelineException::class);
        $this->expectExceptionMessage('rollback_snapshot_not_for_date');

        app(RosterRollbackService::class)->activate($this->admin(), self::DATE, (int) $other->roster_snapshot_id, 'Wrong date');
    }

    public function test_it_requires_a_reason(): void
    {
        $this->expectException(RosterPipelineException::class);
        $this->expectExceptionMessage('rollback_reason_required');

        app(RosterRollbackService::class)->activate($this->admin(), self::DATE, (int) $this->first->roster_snapshot_id, '   ');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('admin.system.manage', 'web'));

        return $admin->fresh();
    }

    private function published(TeleStaffRosterReport $report): RosterImport
    {
        $import = RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_VALIDATED,
            'duty_date' => $report->dutyDate,
            'source_generated_at' => $report->generatedAt,
        ]);
        app(RosterPublisher::class)->publish($import, $report);

        return $import->fresh();
    }
}
