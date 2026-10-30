<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\InboundEmail;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\Support\TeleStaffRosterWorkbookFactory;
use Tests\TestCase;

/** The real parser, the real queue path (sync) and the real cleanup, on a synthetic workbook. */
final class RosterPipelineEndToEndTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline(fakeQueue: false);
    }

    protected function tearDown(): void
    {
        TeleStaffRosterWorkbookFactory::cleanup();
        parent::tearDown();
    }

    public function test_a_real_workbook_goes_from_ledger_to_published_verified_and_cleaned(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        [$email, $import] = RosterPipelineFixtures::linkedImport(['bytes' => $this->workbook()]);

        $this->runProcess($import);
        $import = $import->fresh();

        self::assertSame(RosterImport::STATE_CLEANED, $import->state, (string) $import->error_code);
        self::assertSame(RosterImport::OUTCOME_PUBLISHED, $import->outcome);
        self::assertSame(RosterImport::CLEANUP_DONE, $import->cleanup_state);
        self::assertNotNull($import->verified_at);
        self::assertNotNull($import->cleaned_at);
        self::assertTrue($import->source_generated_at->equalTo('2026-10-30 13:14:15'));

        // The email and file are gone. The ledger, the roster and the audit trail are not.
        self::assertNull(InboundEmail::query()->find($email->id));
        Storage::disk('local')->assertMissing($import->attachment_path);
        self::assertSame(
            ['claimed', 'parsed', 'pointer_moved', 'read_back_matched'],
            RosterEvent::query()->where('roster_import_id', $import->id)->whereNotNull('reason')->orderBy('id')->limit(4)->pluck('reason')->all(),
        );

        $view = app(CurrentRosterReader::class)->current();
        self::assertNotNull($view);
        self::assertSame((int) $import->roster_snapshot_id, (int) $view->snapshot->id);
        self::assertCount(3, $view->sections);
        $engine = $view->sections[2]->assignments;
        self::assertSame('Lieutenant', $engine[1]->seat_rank);
        self::assertNull($engine[1]->rank_label);
        self::assertTrue($engine[1]->is_continuation);
        self::assertSame('vacancy_suppressed', $engine[2]->kind);
        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);
    }

    public function test_the_same_real_workbook_under_a_new_message_id_only_cleans_up(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $bytes = $this->workbook();
        [, $first] = RosterPipelineFixtures::linkedImport(['bytes' => $bytes]);
        $this->runProcess($first);
        [$secondEmail, $second] = RosterPipelineFixtures::linkedImport(['bytes' => $bytes]);

        $this->runProcess($second);

        self::assertSame(RosterImport::STATE_CLEANED, $second->fresh()->state);
        self::assertSame(RosterImport::OUTCOME_DUPLICATE, $second->fresh()->outcome);
        self::assertNull(InboundEmail::query()->find($secondEmail->id));
        self::assertSame(1, RosterSnapshot::query()->count());
    }

    public function test_a_broken_real_workbook_keeps_its_email_and_the_previous_roster(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        [, $good] = RosterPipelineFixtures::linkedImport(['bytes' => $this->workbook()]);
        $this->runProcess($good);
        [$email, $broken] = RosterPipelineFixtures::linkedImport(['bytes' => 'PK this is no workbook']);

        $this->runProcess($broken);
        $broken = $broken->fresh();

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $broken->state);
        self::assertSame('not_a_zip', $broken->error_code);
        self::assertNotNull(InboundEmail::query()->find($email->id));
        Storage::disk('local')->assertExists($broken->attachment_path);
        self::assertSame((int) $good->fresh()->roster_snapshot_id, (int) app(CurrentRosterReader::class)->current()->snapshot->id);
    }

    public function test_shadow_mode_on_a_real_workbook_publishes_and_deletes_nothing(): void
    {
        config(['daily_roster.mode' => 'shadow', 'daily_roster.cleanup_enabled' => true]);
        [$email, $import] = RosterPipelineFixtures::linkedImport(['bytes' => $this->workbook()]);

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_SHADOWED, $import->fresh()->state, (string) $import->fresh()->error_code);
        self::assertNull(app(CurrentRosterReader::class)->current());
        self::assertNotNull(InboundEmail::query()->find($email->id));
        Storage::disk('local')->assertExists($import->attachment_path);
    }

    public function test_cleanup_resumes_through_the_reconciler_once_it_is_turned_on(): void
    {
        [$email, $import] = RosterPipelineFixtures::linkedImport(['bytes' => $this->workbook()]);
        $this->runProcess($import);
        self::assertSame(RosterImport::STATE_VERIFIED, $import->fresh()->state);
        self::assertNotNull(InboundEmail::query()->find($email->id));

        config(['daily_roster.cleanup_enabled' => true]);
        $this->travel(3)->minutes();
        Artisan::call('daily-roster:reconcile');

        self::assertSame(RosterImport::STATE_CLEANED, $import->fresh()->state);
        self::assertNull(InboundEmail::query()->find($email->id));
    }

    public function test_the_operator_path_takes_an_email_that_predates_the_worker_change_all_the_way_through(): void
    {
        [$email, $orphan] = RosterPipelineFixtures::linkedImport(['bytes' => $this->workbook()]);
        // The email is on disk with no ledger row, which is the state of mail from before the Worker change.
        $orphan->delete();

        $exit = Artisan::call('daily-roster:import-inbound', ['inboundEmailId' => $email->id, '--operator-attested' => true]);

        self::assertSame(0, $exit);
        $import = RosterImport::query()->sole();
        self::assertSame(RosterImport::STATE_VERIFIED, $import->state, (string) $import->error_code);
        self::assertSame(['verdict' => 'match', 'reasons' => ['operator_attested']], $import->classification);
        self::assertNotNull(app(CurrentRosterReader::class)->current());
        Exceptions::assertNothingReported();
    }

    private function workbook(): string
    {
        $path = TeleStaffRosterWorkbookFactory::build([
            'date' => self::DATE,
            'generatedAt' => '10/30/2026 09:14:15',
            'sections' => TeleStaffRosterWorkbookFactory::minimalSections([
                TeleStaffRosterWorkbookFactory::employee('Lieutenant', '90003', 'VACA'),
                TeleStaffRosterWorkbookFactory::employee(null, '90004', '+OT'),
                TeleStaffRosterWorkbookFactory::vacancy('Firefighter'),
            ]),
        ]);

        return (string) file_get_contents($path);
    }
}
