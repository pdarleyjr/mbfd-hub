<?php

declare(strict_types=1);

namespace Tests\Feature\HubSupport;

use App\Enums\AccountStatus;
use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
