<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\AiAutomation\AiModelCall;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Notifications\AiResponseNotification;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\IntakeProcessor;
use App\Services\AiAutomation\PlaybookRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Inspections\Concerns\BuildsDailyScenarioWorld;
use Tests\TestCase;

/**
 * The clean-checkout receipt: a live Daily Checkout that reports nothing new gets one fixed line in its own thread
 * ("Inspection received and recorded. No new issues reported."). Template path only, no model call, no bell, and
 * never for backlog work, a switch that is OFF, or a repeat that was already answered.
 */
final class CleanReceiptTest extends TestCase
{
    use BuildsDailyScenarioWorld;
    use RefreshDatabase;

    private const RECEIPT = 'Inspection received and recorded. No new issues reported.';

    private const CONSUMABLE_ITEM = 'Yellow Safety Tape/Duct Tape';

    private User $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        // Replies add a 300 line on weekends; pin a Tuesday so wording never depends on the run day.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:00', 'America/New_York'));
        app(\App\Services\SupportTriage\EquipmentRuleService::class)->seedDefaults();
        Mail::fake();
        $this->buildWorld();
        $this->alpha = $this->newMember('Alpha Member');
    }

    private function switchOn(): void
    {
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
    }

    private function cleanCheckout(?Apparatus $apparatus = null): ApparatusInspection
    {
        $apparatus ??= $this->unit;
        $this->actAs($this->alpha);
        $payload = $this->payload($apparatus, (int) $apparatus->fresh()->current_miles + 20, (float) $apparatus->fresh()->current_engine_hours + 1.2);

        return ApparatusInspection::query()->findOrFail($this->submit($payload, $apparatus)->assertCreated()->json('id'));
    }

    private function missingCheckout(string $itemName): ApparatusInspection
    {
        $this->actAs($this->alpha);
        $payload = $this->payload($this->unit, (int) $this->unit->fresh()->current_miles + 20, (float) $this->unit->fresh()->current_engine_hours + 1.2);
        foreach ($payload['compartments'] as $c => $compartment) {
            foreach ($compartment['items'] as $i => $item) {
                if ($item['name'] === $itemName && ! array_key_exists('value', $item)) {
                    $this->markItem($payload, $c, $i, 'Missing', ['notes' => 'Not in the compartment.']);

                    return ApparatusInspection::query()->findOrFail($this->submit($payload, $this->unit)->assertCreated()->json('id'));
                }
            }
        }
        $this->fail("Checklist item '{$itemName}' not found.");
    }

    /** @return Collection<int, ApparatusIssueMessage> */
    private function assistantMessages(): Collection
    {
        return ApparatusIssueMessage::query()->where('author_context', 'assistant')->get();
    }

    private function aiBells(): int
    {
        return DB::table('notifications')->where('type', AiResponseNotification::class)->count();
    }

    public function test_a_live_clean_checkout_gets_the_one_line_receipt_with_no_model_call_and_no_bell(): void
    {
        $this->switchOn();

        $inspection = $this->cleanCheckout();

        $messages = $this->assistantMessages();
        $this->assertCount(1, $messages);
        $this->assertSame(self::RECEIPT, $messages[0]->body);
        $this->assertSame((int) $inspection->id, (int) $messages[0]->apparatus_inspection_id);
        $this->assertNull($messages[0]->apparatus_defect_id, "the checkout's own thread");
        $this->assertSame('member', $messages[0]->visibility);
        $this->assertNull($messages[0]->author_user_id);

        $row = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->sole();
        $this->assertSame(AiIntakeEvent::STATE_SENT, $row->state);
        $this->assertSame('clean_receipt', $row->template_key);
        $this->assertSame(self::RECEIPT, $row->reply_text);
        $this->assertNull($row->model_call_id);

        $this->assertSame(0, AiModelCall::query()->count(), 'template path only');
        $this->assertSame(0, $this->aiBells(), 'no AI Response notification');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->alpha->id)->count(), 'no bell of any kind for the member');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_the_receipt_is_the_editable_clean_receipt_template(): void
    {
        $playbooks = app(PlaybookRepository::class);
        $this->assertSame(self::RECEIPT, $playbooks->template('clean_receipt')['body']);

        $playbooks->ensureSeeded();
        $this->assertSame(self::RECEIPT, DB::table('ai_automation_playbooks')->where('key', 'clean_receipt')->value('body'));
    }

    public function test_with_the_switch_off_a_clean_checkout_gets_nothing(): void
    {
        $this->assertFalse(app(AiAutomationSwitch::class)->isOn());

        $this->cleanCheckout();

        $this->assertCount(0, $this->assistantMessages());
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_the_hard_disable_beats_the_switch_for_a_clean_checkout(): void
    {
        $this->switchOn();
        config(['ai_automation.hard_disabled' => true]);

        $this->cleanCheckout();

        $this->assertCount(0, $this->assistantMessages());
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_turning_the_switch_on_later_never_answers_an_earlier_clean_checkout(): void
    {
        $this->cleanCheckout();
        $this->switchOn();

        $this->assertCount(0, $this->assistantMessages());
        $this->assertSame(0, AiIntakeEvent::query()->count());
    }

    public function test_backlog_origin_never_produces_a_clean_receipt(): void
    {
        $inspection = $this->cleanCheckout();
        $this->switchOn();
        $intake = AiIntakeEvent::query()->create([
            'domain' => 'vehicle', 'source_type' => 'apparatus_inspection', 'source_id' => $inspection->id, 'event_key' => 'received',
            'origin' => AiIntakeEvent::ORIGIN_BACKLOG, 'epoch' => app(AiAutomationSwitch::class)->epoch(),
            'state' => AiIntakeEvent::STATE_QUEUED, 'needs_human' => true,
        ]);

        $state = app(IntakeProcessor::class)->process((int) $intake->id);

        $this->assertSame(AiIntakeEvent::STATE_SKIPPED, $state);
        $this->assertSame(['no_findings'], $intake->refresh()->reason_codes);
        $this->assertCount(0, $this->assistantMessages());
    }

    public function test_a_repeat_that_was_already_answered_stays_history_only(): void
    {
        $this->switchOn();

        $first = $this->missingCheckout(self::CONSUMABLE_ITEM);
        $this->assertCount(1, $this->assistantMessages(), 'the new finding got its normal reply');
        $this->assertNotSame('clean_receipt', AiIntakeEvent::query()->where('source_id', $first->id)->value('template_key'));

        $second = $this->missingCheckout(self::CONSUMABLE_ITEM);

        $row = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->where('source_id', $second->id)->sole();
        $this->assertSame(AiIntakeEvent::STATE_SKIPPED, $row->state);
        $this->assertSame(['repeat_only'], $row->reason_codes);
        $this->assertCount(1, $this->assistantMessages(), 'no second reply and no clean receipt');
    }

    public function test_a_checkout_with_a_new_finding_still_gets_the_finding_reply_and_its_bell(): void
    {
        $this->switchOn();

        $this->missingCheckout(self::CONSUMABLE_ITEM);

        $messages = $this->assistantMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('for the Yellow Safety Tape/Duct Tape first', $messages[0]->body);
        $this->assertNotSame(self::RECEIPT, $messages[0]->body);
        $row = AiIntakeEvent::query()->where('source_type', 'apparatus_inspection')->sole();
        $this->assertNotSame('clean_receipt', $row->template_key);
        $this->assertSame(1, $this->aiBells(), 'a real finding reply still rings the bell');
    }
}
