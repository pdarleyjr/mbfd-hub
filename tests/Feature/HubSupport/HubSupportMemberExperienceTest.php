<?php

declare(strict_types=1);

namespace Tests\Feature\HubSupport;

use App\Enums\AccountStatus;
use App\Enums\HubSupportTicketStatus;
use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class HubSupportMemberExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_page_has_one_required_question_and_no_ticket_system_fields(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);

        $response = $this->actingAsCanonicalUser($user)->get('/support/issues/create');

        $response->assertOk()
            ->assertSee('Report an Issue')
            ->assertSee('What went wrong?')
            ->assertSee('Add screenshot or file')
            ->assertDontSee('name="title"', false)
            ->assertDontSee('name="category"', false)
            ->assertDontSee('name="impact"', false)
            ->assertDontSee('name="severity"', false)
            ->assertDontSee('name="diagnostics"', false);

        $response->assertSee("We'll automatically include the page and available app information that may help us find the problem.", false)
            ->assertDontSee('safe technical details that may help us find the problem.');
    }

    public function test_password_challenge_never_renders_the_report_widget(): void
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        $this->actingAsCanonicalUser($user)->get('/admin/set-password')
            ->assertOk()
            ->assertDontSee('data-hub-issue-widget', false);
    }

    public function test_member_can_submit_with_only_description_and_receives_simple_confirmation(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);

        $response = $this->actingAsCanonicalUser($user)->postJson('/support/issues', [
            'client_submission_id' => 'c019fd69-9de0-4587-93e5-f21b15b28d98',
            'description' => 'I tapped Submit but nothing happened.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Thanks — we got it.')
            ->assertJsonPath('report.status', 'Received');
        self::assertSame($user->id, HubSupportTicket::query()->sole()->reported_by_user_id);
    }

    public function test_member_sees_only_their_own_reports_and_never_internal_notes_or_diagnostics(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $other = User::factory()->create(['account_status' => AccountStatus::Active]);
        $own = HubSupportTicket::factory()->for($member, 'reporter')->create([
            'generated_title' => 'PDF upload does not finish',
            'diagnostics' => ['events' => [['message' => 'private diagnostic']]],
        ]);
        $own->updates()->create([
            'status' => 'in_progress',
            'internal_note' => 'Never show this note',
            'changed_by_user_id' => $other->id,
        ]);
        HubSupportTicket::factory()->for($other, 'reporter')->create([
            'generated_title' => 'Other member report',
        ]);

        $response = $this->actingAsCanonicalUser($member)->get('/support/issues');

        $response->assertOk()
            ->assertSee('My Reports')
            ->assertSee('PDF upload does not finish')
            ->assertDontSee('Other member report')
            ->assertDontSee('Never show this note')
            ->assertDontSee('private diagnostic');
    }

    public function test_member_sees_the_approved_resolution_without_internal_notes(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        $report = HubSupportTicket::factory()->for($member, 'reporter')->create([
            'status' => HubSupportTicketStatus::Resolved,
            'resolution_summary' => 'We restored the form service and verified the submission path.',
        ]);
        $report->updates()->create([
            'status' => HubSupportTicketStatus::Resolved,
            'public_response' => 'Internal-only message should not override the approved resolution.',
            'internal_note' => 'Never show this resolution work note.',
            'changed_by_user_id' => $manager->id,
        ]);

        $this->actingAsCanonicalUser($member)->get(route('hub-support.show', $report))
            ->assertOk()
            ->assertSee('Fixed')
            ->assertSee('What we found')
            ->assertSee('We restored the form service and verified the submission path.')
            ->assertDontSee('Never show this resolution work note.');
    }

    public function test_member_sees_the_approved_resolution_after_the_report_is_closed(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        $report = HubSupportTicket::factory()->for($member, 'reporter')->create([
            'status' => HubSupportTicketStatus::Closed,
            'resolution_summary' => 'We restored the form service and verified the submission path.',
            'diagnostics' => ['events' => [['message' => 'private diagnostic']]],
        ]);
        $report->updates()->create([
            'status' => HubSupportTicketStatus::Closed,
            'internal_note' => 'Never show this closed work note.',
            'metadata' => ['previous_resolution_summary' => 'Admin-only metadata is not member content.'],
            'changed_by_user_id' => $manager->id,
        ]);

        $this->actingAsCanonicalUser($member)->get(route('hub-support.show', $report))
            ->assertOk()
            ->assertSee('Closed')
            ->assertSee('What we found')
            ->assertSee('We restored the form service and verified the submission path.')
            ->assertDontSee('Never show this closed work note.')
            ->assertDontSee('Admin-only metadata is not member content.')
            ->assertDontSee('private diagnostic');
    }

    public function test_fallback_shows_individual_attachment_validation_errors(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->actingAsCanonicalUser($user)
            ->from('/support/issues/create')
            ->post('/support/issues', [
                'client_submission_id' => 'f5f8889a-b1eb-4af5-8969-015f9a8ba72e',
                'description' => 'The attached file does not work.',
                'attachments' => [UploadedFile::fake()->create('unsupported.txt', 1, 'text/plain')],
            ])
            ->assertRedirect('/support/issues/create')
            ->assertSessionHasErrors('attachments.0');

        $this->get('/support/issues/create')
            ->assertOk()
            ->assertSee('file of type', false);
    }
}
