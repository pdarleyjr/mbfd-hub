<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\StretcherIssueReport;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\TestCase;

/**
 * Release review findings for the member-facing surface of docs/unified-activity-and-ownership.md: staff routing stays
 * out of the crew's story, a stale "fixed" marker never blocks the member, a reused stretcher report id is a retry and
 * never a silently dropped different report, and the panel never offers a move the owner rules refuse.
 */
final class MemberSurfaceReleaseReviewTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const ENGINE2_LIFEPAK = 'comp_r1-item-1';

    private const DEWALT = 'compartment_b-item-6';

    private $captain;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buildCaptain5World('2026-10-20 10:00:00');
        $this->captain = $this->officer('Captain', 'Captain Alpha');
        $this->seat($this->captain, 'Captain 5', 'A Shift');
    }

    public function test_a_reused_stretcher_report_id_with_a_different_report_is_a_conflict_not_a_silent_replay(): void
    {
        Storage::fake('public');
        $this->actAs($this->newMember('Crew'));
        $id = (string) Str::uuid();
        $url = '/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues';

        $this->postJson($url, ['client_submission_id' => $id, 'problem' => 'battery', 'description' => 'Dead battery', 'unusable' => false])->assertCreated();
        $this->postJson($url, ['client_submission_id' => $id, 'problem' => 'battery', 'description' => 'Dead battery', 'unusable' => false])->assertOk();
        $this->postJson($url, ['client_submission_id' => $id, 'problem' => 'charger', 'description' => 'Charger sparks', 'unusable' => true])->assertStatus(409);

        self::assertSame(1, StretcherIssueReport::query()->count());
    }

    public function test_ownership_moves_between_captain5_and_logistics_stay_out_of_the_member_issue_history(): void
    {
        [$lifepak] = $this->reportMissing($this->unit, $this->newMember('Engine Crew'), [self::ENGINE2_LIFEPAK]);
        $this->actAs($this->captain);
        $this->forgetCaptain5Memo();
        $this->postJson('/api/captain5/issues/'.$lifepak->id.'/actions', [
            'action' => 'send_to_logistics', 'expected_state' => DefectCorrectiveActionService::stateToken($lifepak), 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        self::assertSame(1, ApparatusDefectEvent::query()->where('event_type', 'owner_transferred')->count());

        $this->actAs($this->newMember('Another Member'));
        $timeline = $this->getJson('/api/public/apparatus-issues/'.$lifepak->id.'/history')->assertOk()->json('timeline');
        self::assertNotContains('owner_transferred', array_column($timeline, 'type'));

        $finding = collect($this->getJson('/api/public/apparatuses/'.$this->unit->id.'/findings')->json('open'))->firstWhere('id', $lifepak->id);
        self::assertNotSame('Moved to another queue', $finding['latest_action']['label'] ?? null);
    }

    public function test_the_issue_panel_does_not_offer_take_for_logistics_on_a_stretcher_report(): void
    {
        $this->actAs($this->newMember('Crew'));
        $id = (int) $this->postJson('/api/public/apparatuses/'.$this->unit->id.'/stretcher-issues', [
            'client_submission_id' => (string) Str::uuid(), 'problem' => 'armrest', 'description' => 'Armrest broken', 'unusable' => false,
        ])->assertCreated()->json('data.issue_id');

        $detail = app(IssueSourceRegistry::class)->get('apparatus_defect')->detail($this->superAdmin(), (string) $id);
        $keys = array_map(static fn ($action): string => (string) ($action->key ?? ''), $detail->actions ?? []);

        self::assertNotContains('take_for_logistics', $keys);
        self::assertSame(ApparatusDefect::OWNER_CAPTAIN5, ApparatusDefect::query()->findOrFail($id)->responsible_owner);
    }

    public function test_a_later_checkout_that_still_finds_the_item_missing_clears_the_pending_fixed_marker(): void
    {
        [$dewalt] = $this->reportMissing($this->rescue, $this->newMember('Rescue Crew'), [self::DEWALT]);
        ApparatusDefect::query()->whereKey($dewalt->id)->update(['issue_type' => 'damaged']);
        $finder = $this->newMember('Finder');
        $this->actAs($finder);
        $this->postJson('/api/public/apparatus-issues/'.$dewalt->id.'/member-correction', ['client_action_id' => (string) Str::uuid(), 'outcome' => 'repaired'])
            ->assertOk()->assertJsonPath('data.result', 'awaiting_verification');

        $pending = collect($this->getJson('/api/public/apparatuses/'.$this->rescue->id.'/findings')->json('open'))->firstWhere('id', $dewalt->id);
        self::assertNotNull($pending['member_correction']['pending']);
        self::assertFalse($pending['member_correction']['eligible']);

        $this->travel(1)->days();
        $this->reportMissing($this->rescue, $this->newMember('Next Shift'), [self::DEWALT]);
        $this->actAs($finder);
        $open = collect($this->getJson('/api/public/apparatuses/'.$this->rescue->id.'/findings')->json('open'))->firstWhere('id', $dewalt->id);

        self::assertNull($open['member_correction']['pending']);
        self::assertTrue($open['member_correction']['eligible']);
    }
}
