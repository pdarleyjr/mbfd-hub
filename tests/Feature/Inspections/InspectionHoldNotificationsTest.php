<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Inspections\InspectionReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Inspections\Concerns\BuildsInspectionReviewFixtures;
use Tests\TestCase;

/** Processing and review decisions tell the right people (docs/daily-inspection-continuity.md section 4.5). */
final class InspectionHoldNotificationsTest extends TestCase
{
    use BuildsInspectionReviewFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
    }

    public function test_a_held_inspection_notifies_reviewers_once_and_a_routine_one_notifies_nobody(): void
    {
        $reviewer = $this->reviewer();

        $this->submit($this->meterPayload(1020, 401.2))->assertCreated()->assertJsonPath('processing_status', 'accepted');
        Notification::assertNothingSent();

        $this->submit($this->meterPayload(900, 402.0))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');

        Notification::assertSentToTimes($reviewer, InspectionEventNotification::class, 1);
        Notification::assertSentTo($reviewer, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::REVIEW_REQUIRED
            && str_ends_with($n->url, '#approval'));
    }

    public function test_the_escalation_notification_reaches_reviewers(): void
    {
        $reviewer = $this->reviewer();
        $this->submit($this->meterPayload(1020, 401.2, withFinding: true))->assertCreated();
        $inspection = ApparatusInspection::sole();
        $defect = ApparatusDefect::sole();
        Notification::fake();

        app(InspectionNotificationDispatcher::class)->issueEscalated($defect, $inspection);

        Notification::assertSentTo($reviewer, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::ISSUE_ESCALATED);
    }

    public function test_a_human_decision_tells_the_submitter_but_automatic_acceptance_does_not(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = ['explanation' => 'Please check the pump panel.'];
        $this->submit($payload)->assertCreated();
        $held = ApparatusInspection::sole();
        $this->submit($this->meterPayload(1030, 402.0))->assertCreated();
        Notification::assertNotSentTo($this->member, InspectionEventNotification::class);

        $other = $this->meterPayload(1040, 403.0);
        $other['review_flag'] = ['explanation' => 'And this one.'];
        $this->submit($other)->assertCreated();
        $second = ApparatusInspection::query()->latest('id')->firstOrFail();
        $reviewer = $this->reviewer();

        app(InspectionReviewService::class)->approve($held->id, $reviewer);
        app(InspectionReviewService::class)->reject($second->id, $reviewer, 'Not accurate.');

        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::INSPECTION_DECIDED
            && str_contains($n->title, 'approved'));
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::INSPECTION_DECIDED
            && str_contains($n->title, 'rejected'));
    }

    public function test_a_meter_decision_that_releases_the_inspection_tells_the_submitter_once(): void
    {
        $this->submit($this->meterPayload(900, 401.2))->assertCreated()->assertJsonPath('processing_status', 'held_for_review');
        $inspection = ApparatusInspection::sole();
        $reviewer = $this->reviewer();
        Notification::fake();

        $this->decideMeter($this->holdFor($inspection, 'miles'), $reviewer, ['action' => 'dismiss']);

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        Notification::assertSentToTimes($this->member, InspectionEventNotification::class, 1);
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::INSPECTION_DECIDED
            && str_contains($n->title, 'approved'));
    }

    public function test_a_decision_that_leaves_another_hold_open_does_not_notify_yet(): void
    {
        $payload = $this->meterPayload(900, 401.2);
        $payload['review_flag'] = ['explanation' => 'Also check the cab.'];
        $this->submit($payload)->assertCreated();
        $inspection = ApparatusInspection::sole();
        Notification::fake();

        $this->decideMeter($this->holdFor($inspection, 'miles'), $this->reviewer(), ['action' => 'dismiss']);

        Notification::assertNotSentTo($this->member, InspectionEventNotification::class);
    }

    public function test_a_clarification_goes_through_the_message_service_and_reaches_the_submitter(): void
    {
        $payload = $this->meterPayload(1020, 401.2);
        $payload['review_flag'] = ['explanation' => 'Check it.'];
        $this->submit($payload)->assertCreated();
        $inspection = ApparatusInspection::sole();
        Notification::fake();

        app(InspectionReviewService::class)->requestClarification($inspection->id, $this->reviewer(), 'Which panel?', '0f8a1c64-2d6b-4c0e-9d57-6c8d3d5a2b10');

        $this->assertSame('member', ApparatusIssueMessage::sole()->visibility);
        Notification::assertSentTo($this->member, InspectionEventNotification::class, fn (InspectionEventNotification $n): bool => $n->kind === InspectionNotificationKind::CLARIFICATION_REQUESTED);
    }
}
