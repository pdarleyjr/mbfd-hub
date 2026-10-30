<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterStateRecorderTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_a_transition_updates_the_row_and_writes_its_event(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED]);

        $result = app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_QUEUED, [
            'last_dispatched_at' => now(),
            'warnings' => ['code_row_4'],
            'duty_date' => '2026-10-30',
        ], 'because', ['count' => 2], 77);

        self::assertSame(RosterImport::STATE_QUEUED, $result->state);
        self::assertSame(RosterImport::STATE_QUEUED, $import->fresh()->state);
        self::assertSame(['code_row_4'], $import->fresh()->warnings);
        self::assertSame('2026-10-30', $import->fresh()->duty_date->format('Y-m-d'));
        self::assertNotNull($import->fresh()->last_dispatched_at);
        self::assertTrue($import->fresh()->state_changed_at->equalTo(now()));

        $event = RosterEvent::query()->sole();
        self::assertSame((int) $import->id, (int) $event->roster_import_id);
        self::assertSame(RosterStateRecorder::ACTION_TRANSITION, $event->action);
        self::assertSame(RosterImport::STATE_CLASSIFIED, $event->from_state);
        self::assertSame(RosterImport::STATE_QUEUED, $event->to_state);
        self::assertSame('because', $event->reason);
        self::assertSame(['count' => 2], $event->context);
        self::assertSame(77, (int) $event->actor_user_id);
        self::assertSame('2026-10-30', $event->duty_date->format('Y-m-d'));
    }

    public function test_an_undefined_transition_is_rejected_with_a_code_only_exception(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED]);

        try {
            app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_PUBLISHED);
            self::fail('An undefined transition must be rejected.');
        } catch (RosterPipelineException $exception) {
            self::assertSame(RosterPipelineException::TRANSITION_UNDEFINED, $exception->getMessage());
            self::assertFalse($exception->isConflict());
        }

        self::assertSame(RosterImport::STATE_VERIFIED, $import->fresh()->state);
        self::assertSame(0, RosterEvent::query()->count());
    }

    public function test_a_stale_model_loses_the_conditional_update_and_writes_no_event(): void
    {
        $import = RosterPipelineFixtures::import();
        $stale = $import->fresh();
        app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_DUPLICATE);
        $events = RosterEvent::query()->count();

        try {
            app(RosterStateRecorder::class)->transition($stale, RosterImport::STATE_DUPLICATE);
            self::fail('The second writer must lose.');
        } catch (RosterPipelineException $exception) {
            self::assertTrue($exception->isConflict());
        }

        self::assertSame($events, RosterEvent::query()->count());
    }

    public function test_a_failed_event_write_rolls_the_state_back(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED]);
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'insert into "roster_events"')) {
                throw new RuntimeException('audit write failed');
            }
        });

        try {
            app(RosterStateRecorder::class)->transition($import, RosterImport::STATE_QUEUED);
            self::fail('The audit failure should escape.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit write failed', $exception->getMessage());
        }

        self::assertSame(RosterImport::STATE_CLASSIFIED, RosterImport::query()->whereKey($import->id)->value('state'));
    }

    public function test_only_one_of_two_concurrent_claims_gets_the_row(): void
    {
        $import = RosterPipelineFixtures::import();
        $first = $import->fresh();
        $second = $import->fresh();
        $recorder = app(RosterStateRecorder::class);

        $claimed = $recorder->claim($first);
        $lost = $recorder->claim($second);

        self::assertNotNull($claimed);
        self::assertNull($lost);
        self::assertSame(RosterImport::STATE_PARSING, $import->fresh()->state);
        self::assertSame(1, (int) $import->fresh()->attempts);
        self::assertTrue($import->fresh()->lease_expires_at->equalTo(now()->addSeconds(300)));
        self::assertSame(1, RosterEvent::query()->where('to_state', RosterImport::STATE_PARSING)->count());
    }

    public function test_a_claim_on_a_state_that_cannot_be_claimed_returns_null(): void
    {
        foreach ([RosterImport::STATE_CLASSIFIED, RosterImport::STATE_PARSING, RosterImport::STATE_PUBLISHED, RosterImport::STATE_NEEDS_REVIEW] as $state) {
            self::assertNull(app(RosterStateRecorder::class)->claim(RosterPipelineFixtures::import(['state' => $state])));
        }
    }

    public function test_leaving_a_leased_state_clears_the_lease(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PARSING, 'lease_expires_at' => now()->addMinutes(5)]);
        $recorder = app(RosterStateRecorder::class);

        $validated = $recorder->transition($import, RosterImport::STATE_VALIDATED);
        self::assertNotNull($validated->lease_expires_at, 'A crash after validation must still be found.');

        self::assertNull($recorder->transition($validated, RosterImport::STATE_SHADOWED)->lease_expires_at);
    }
}
