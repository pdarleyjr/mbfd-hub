<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\User;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** docs/member-replies.md section 2.2: uniform / PPE requests, including those an officer submitted for a member. */
final class PersonnelRequestParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $beneficiary;

    private Employee $officer;

    private Employee $stranger;

    private PersonnelRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake((string) config('filesystems.private'));
        $this->beneficiary = $this->employee('25001', 'Morgan Member');
        $this->officer = $this->employee('25002', 'Taylor Captain');
        $this->stranger = $this->employee('25003', 'Una Unrelated');
        $this->request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $this->beneficiary,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 2]],
            'request-25001',
        );
        // As if the officer submitted it on the member's behalf.
        $this->request->forceFill(['requester_employee_id' => $this->officer->id, 'requester_name' => 'Taylor Captain'])->save();
    }

    public function test_the_member_and_the_officer_who_submitted_it_both_see_staff_replies_and_can_reply(): void
    {
        $staff = $this->staffUser();
        app(PersonnelRequestWorkflowService::class)->addNote($this->request, $staff, 'Shirts are on backorder until Monday.', 'Vendor slip 8812');

        foreach ([$this->beneficiary, $this->officer] as $employee) {
            $page = $this->actingAs($employee, 'employee')->get("/employee/my-requests/{$this->request->public_id}")->assertOk();
            $page->assertSee('Shirts are on backorder until Monday.');
            $page->assertSee('Support Services');
            $page->assertDontSee('Vendor slip 8812');
        }

        $this->actingAs($this->officer, 'employee')
            ->post("/employee/personnel-requests/{$this->request->public_id}/respond", ['response' => 'Can medium work instead?', 'idempotency_key' => 'officer-1'])
            ->assertRedirect();
        $this->assertSame(1, $this->request->updates()->where('event', 'employee_responded')->where('changed_by_employee_id', $this->officer->id)->count());
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::PERSONNEL_REQUEST, $this->request->id));

        // The beneficiary sees the officer's message labelled by name, not as their own.
        $this->actingAs($this->beneficiary, 'employee')->get("/employee/my-requests/{$this->request->public_id}")
            ->assertOk()->assertSee('Can medium work instead?')->assertSee('Taylor Captain');

        // A staff note the member can see answers it; an internal-only note does not.
        app(PersonnelRequestWorkflowService::class)->addNote($this->request, $staff, null, 'Internal only');
        $this->assertTrue(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::PERSONNEL_REQUEST, $this->request->id));
        app(PersonnelRequestWorkflowService::class)->addNote($this->request, $staff, 'Medium is in stock, ready Tuesday.', null);
        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::PERSONNEL_REQUEST, $this->request->id));
    }

    public function test_the_officer_sees_it_in_my_requests_but_documents_stay_with_the_member(): void
    {
        $this->actingAs($this->officer, 'employee')->get('/employee/my-requests')->assertOk()->assertSee($this->request->request_number);

        $this->actingAs($this->officer, 'employee')
            ->post("/employee/personnel-requests/{$this->request->public_id}/attachments", [
                'document_type' => 'police_report',
                'attachment' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n% test"),
            ])
            ->assertForbidden();
    }

    public function test_a_member_who_is_not_part_of_the_request_cannot_read_or_reply(): void
    {
        $this->actingAs($this->stranger, 'employee')->get("/employee/my-requests/{$this->request->public_id}")->assertForbidden();
        $this->actingAs($this->stranger, 'employee')
            ->post("/employee/personnel-requests/{$this->request->public_id}/respond", ['response' => 'Hello'])
            ->assertForbidden();
        $this->assertSame(0, $this->request->updates()->where('event', 'employee_responded')->count());
        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::PERSONNEL_REQUEST, $this->request->id));
    }

    private function employee(string $employeeId, string $name): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => bcrypt(str()->random(40)),
            'must_change_password' => false,
        ]);
    }

    private function staffUser(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        Gate::before(fn (User $actor, string $ability): ?bool => $actor->is($user) && $ability === 'update' ? true : null);

        return $user;
    }
}
