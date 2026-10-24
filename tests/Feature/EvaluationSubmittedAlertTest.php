<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Notifications\NewSubmissionNotification;
use App\Services\Workgroup\EvaluationService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "An evaluation has been submitted" is true when the status becomes `submitted`, not when a member
 * merely opens the form (which creates a draft). The recorder below captures what the alert hands to the
 * notification system, independent of who is later eligible to receive it.
 */
final class EvaluationSubmittedAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $listener;

    private WorkgroupMember $workgroupMember;

    private CandidateProduct $product;

    /** @var object{sent: list<array{to: list<int>, notification: NewSubmissionNotification}>} */
    private object $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new class implements Dispatcher
        {
            /** @var list<array{to: list<int>, notification: NewSubmissionNotification}> */
            public array $sent = [];

            public function send($notifiables, $notification): void
            {
                $list = is_iterable($notifiables) ? collect($notifiables)->all() : [$notifiables];
                $this->sent[] = ['to' => array_map(static fn (User $user): int => (int) $user->getKey(), $list), 'notification' => $notification];
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                $this->send($notifiables, $notification);
            }
        };
        Notification::swap($this->recorder);

        $member = User::factory()->create();
        $this->listener = User::factory()->create(['account_status' => 'active']);
        // Eligibility, not a saved row, decides who is told: the listener may view evaluation submissions.
        foreach (['admin.access', 'admin.workgroups.view'] as $permission) {
            $this->listener->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $workgroup = Workgroup::create(['name' => 'Alert Workgroup', 'created_by' => $member->id]);
        $this->workgroupMember = WorkgroupMember::create(['workgroup_id' => $workgroup->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id, 'name' => 'Alert Session',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'active',
        ]);
        $category = EvaluationCategory::firstOrCreate(['name' => 'Alert Category'], ['is_active' => true, 'is_rankable' => true]);
        $this->product = CandidateProduct::create(['workgroup_session_id' => $session->id, 'category_id' => $category->id, 'name' => 'Pry Bar']);
    }

    private function draft(): EvaluationSubmission
    {
        return EvaluationSubmission::create([
            'workgroup_member_id' => $this->workgroupMember->id,
            'candidate_product_id' => $this->product->id,
            'status' => 'draft',
        ]);
    }

    public function test_opening_a_draft_does_not_alert_anyone(): void
    {
        $draft = $this->draft();
        $draft->update(['overall_score' => null, 'status' => 'draft']);

        $this->assertSame([], $this->recorder->sent);
    }

    public function test_the_alert_fires_once_when_the_status_becomes_submitted_with_the_same_text(): void
    {
        $draft = $this->draft();
        $this->assertSame([], $this->recorder->sent);

        $draft->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->assertCount(1, $this->recorder->sent);
        $this->assertSame([$this->listener->id], $this->recorder->sent[0]['to']);
        $data = $this->recorder->sent[0]['notification']->toDatabase($this->listener);
        $this->assertSame('New Evaluation Submitted', $data['title']);
        $this->assertSame('An evaluation for Pry Bar has been submitted.', $data['body']);

        // Editing a submitted evaluation later does not alert again.
        $draft->refresh()->update(['submitted_at' => now()->addMinute()]);
        $this->assertCount(1, $this->recorder->sent);
    }

    public function test_the_service_path_alerts_on_submit_only(): void
    {
        $draft = $this->draft();
        $this->assertSame([], $this->recorder->sent);

        app(EvaluationService::class)->submitEvaluation($draft);

        $this->assertCount(1, $this->recorder->sent);
    }

    public function test_a_submission_created_already_submitted_still_alerts(): void
    {
        EvaluationSubmission::create([
            'workgroup_member_id' => $this->workgroupMember->id,
            'candidate_product_id' => $this->product->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->assertCount(1, $this->recorder->sent);
    }
}
