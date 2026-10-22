<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Inspections\IssueMessageService;
use App\Support\Fleet\InspectionReviewers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class IssueMessageAuthorContextTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;

    private IssueMessageService $messages;

    private Apparatus $rig;

    private User $owner;

    private User $admin;

    private ApparatusInspection $inspection;

    private ApparatusDefect $defect;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->messages = app(IssueMessageService::class);
        $this->rig = $this->rig();
        $this->owner = $this->member('Olive Owner');
        $this->admin = $this->reviewer();
        $this->inspection = $this->inspectionBy($this->rig, $this->owner);
        $this->defect = $this->openDefect($this->rig, $this->inspection, $this->owner);
    }

    public function test_the_author_context_is_snapshotted_when_the_message_is_written(): void
    {
        $fromAdmin = $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'We have it.']);
        $fromMember = $this->messages->post($this->owner, ['defect_id' => $this->defect->id, 'body' => 'Thanks.']);

        $this->assertSame('logistics', $fromAdmin->author_context);
        $this->assertTrue($fromAdmin->isFromLogistics());
        $this->assertSame('member', $fromMember->author_context);
        $this->assertFalse($fromMember->isFromLogistics());
    }

    public function test_revoking_reviewer_status_later_does_not_relabel_earlier_messages(): void
    {
        $message = $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Battery is on the shelf.']);

        $this->admin->removeRole('logistics_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(InspectionReviewers::canReview($this->admin->fresh()));

        $thread = $this->messages->threadFor($this->owner, $this->defect);
        $this->assertSame(['logistics'], $thread->pluck('author_context')->all());
        $this->assertSame($message->id, $thread->first()->id);

        // A promoted member's old replies stay "member".
        $reply = $this->messages->post($this->owner, ['defect_id' => $this->defect->id, 'body' => 'I will pick it up.']);
        $this->owner->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame('member', $this->messages->threadFor($this->owner->fresh(), $this->defect)->firstWhere('id', $reply->id)->author_context);
    }

    public function test_the_member_inspection_threads_carry_the_context_too(): void
    {
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Ordered.']);
        $this->messages->post($this->owner, ['defect_id' => $this->defect->id, 'body' => 'Thanks.']);

        $threads = $this->messages->memberThreadsForInspection($this->owner, $this->inspection, [$this->defect->id]);

        $this->assertSame(['logistics', 'member'], $threads[$this->defect->id]->pluck('author_context')->all());
    }

    public function test_a_correction_keeps_the_corrected_messages_context(): void
    {
        $memberNote = $this->messages->post($this->owner, ['inspection_id' => $this->inspection->id, 'body' => 'Wrong unit.']);
        $logisticsNote = $this->messages->post($this->admin, ['inspection_id' => $this->inspection->id, 'visibility' => 'member', 'body' => 'Typo here.']);

        // A reviewer correcting a member's message does not turn it into a Logistics message.
        $this->assertSame('member', $this->messages->correct($this->admin, $memberNote->id, 'Engine 2, not Engine 3.')->author_context);
        $this->assertSame('member', $this->messages->correct($this->owner, $memberNote->id, 'Engine 2.')->author_context);
        $this->assertSame('logistics', $this->messages->correct($this->admin, $logisticsNote->id, 'Typo fixed.')->author_context);
    }

    public function test_a_corrective_action_message_is_a_logistics_message(): void
    {
        app(DefectCorrectiveActionService::class)->acknowledge($this->defect->id, $this->admin, ['member_message' => 'Logistics has this.']);

        $this->assertSame('logistics', ApparatusIssueMessage::query()->sole()->author_context);
    }

    public function test_the_latest_update_on_open_findings_uses_only_logistics_messages_even_after_a_role_change(): void
    {
        $checklist = ['compartments' => [['id' => 'cab', 'name' => 'Cab', 'items' => [['id' => 'cab-portable-radio-battery', 'name' => 'Portable radio battery']]]]];
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Replacement is on the shelf.']);
        $this->messages->post($this->owner, ['defect_id' => $this->defect->id, 'body' => 'MEMBER-REPLY-NOT-FOR-THE-CREW']);
        $this->messages->post($this->admin, ['defect_id' => $this->defect->id, 'visibility' => 'internal', 'body' => 'INTERNAL-NOTE']);

        // The author later loses reviewer status; the stored context still says logistics.
        $this->admin->removeRole('logistics_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $findings = collect(app(DefectContinuityService::class)->openFindings($this->rig, $checklist))->keyBy('id');

        $this->assertSame('Replacement is on the shelf.', $findings[$this->defect->id]['latest_member_update']['text']);
        $this->assertStringNotContainsString('MEMBER-REPLY', json_encode($findings->all()));
        $this->assertStringNotContainsString('INTERNAL-NOTE', json_encode($findings->all()));
    }

    public function test_the_migration_backfills_logistics_for_current_reviewers_only(): void
    {
        $fromAdmin = ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $this->defect->id, 'author_user_id' => $this->admin->id, 'visibility' => 'member', 'body' => 'Old reviewer message.',
        ]);
        $fromMember = ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $this->defect->id, 'author_user_id' => $this->owner->id, 'visibility' => 'member', 'body' => 'Old member message.',
        ]);
        $system = ApparatusIssueMessage::query()->create([
            'apparatus_defect_id' => $this->defect->id, 'visibility' => 'member', 'body' => 'Authorless message.',
        ]);
        $this->assertSame('member', $fromAdmin->fresh()->author_context); // column default before the backfill

        $migration = require database_path('migrations/2026_10_22_100400_add_author_context_to_apparatus_issue_messages.php');
        $migration->down();
        $migration->up();

        $contexts = DB::table('apparatus_issue_messages')->pluck('author_context', 'id');
        $this->assertSame('logistics', $contexts[$fromAdmin->id]);
        $this->assertSame('member', $contexts[$fromMember->id]);
        $this->assertSame('member', $contexts[$system->id]);
    }
}
