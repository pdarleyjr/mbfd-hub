<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\NewSubmissionNotification;
use App\Notifications\TrainingTodoAssignedNotification;
use App\Services\Notifications\NotificationLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/** A tap on an alert never leaves the site and never lands on a page the recipient cannot open. */
final class NotificationClickThroughTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        config(['app.url' => 'https://hub.example.test']);
    }

    /** @return array<string, array{string, ?string}> */
    public static function urls(): array
    {
        return [
            'site-relative path' => ['/admin/station-requests/4', '/admin/station-requests/4'],
            'query and fragment' => ['/employee/my-requests/9?tab=items#item-3', '/employee/my-requests/9?tab=items#item-3'],
            'absolute same-origin' => ['https://hub.example.test/updates/12', '/updates/12'],
            'protocol-relative' => ['//evil.test/phish', null],
            'other origin' => ['https://evil.test/admin', null],
            'lookalike origin' => ['https://hub.example.test.evil.test/admin', null],
            'embedded credentials' => ['https://hub.example.test@evil.test/', null],
            'javascript scheme' => ['javascript:alert(1)', null],
            'data scheme' => ['data:text/html,<script>alert(1)</script>', null],
            'backslash trick' => ['/\\evil.test', null],
            'control characters' => ["/admin\r\nSet-Cookie: x=1", null],
            'empty' => ['', null],
        ];
    }

    /** @dataProvider urls */
    public function test_only_same_origin_destinations_are_accepted(string $url, ?string $path): void
    {
        $this->assertSame($path, NotificationLink::sameOriginPath($url));
    }

    public function test_a_reviewer_who_can_enter_the_admin_panel_keeps_the_admin_destination(): void
    {
        $reviewer = $this->withPermissions($this->rosterMember(), 'admin.access');

        $this->assertSame('/admin/station-requests/4', NotificationLink::resolve($reviewer, '/admin/station-requests/4'));
    }

    public function test_a_member_who_lost_admin_access_is_sent_to_their_landing_page_instead(): void
    {
        $revoked = $this->withPermissions($this->rosterMember(), 'admin.access');
        $revoked->revokePermissionTo('admin.access');
        $revoked = $revoked->fresh();

        $this->assertSame('/employee', NotificationLink::resolve($revoked, '/admin/station-requests/4'));
    }

    public function test_a_member_without_a_personnel_record_lands_on_their_account_page(): void
    {
        $this->assertSame('/account', NotificationLink::resolve($this->member(), '/admin/station-requests/4'));
        $this->assertSame('/account', NotificationLink::resolve($this->member(), '/training/training-todos/1'));
    }

    public function test_a_foreign_or_malformed_destination_becomes_the_landing_page(): void
    {
        $member = $this->rosterMember();

        $this->assertSame('/employee', NotificationLink::resolve($member, 'https://evil.test/admin'));
        $this->assertSame('/employee', NotificationLink::resolve($member, '//evil.test'));
        $this->assertSame('/employee', NotificationLink::resolve($member, 'javascript:alert(1)'));
    }

    public function test_pages_outside_any_panel_stay_as_sent(): void
    {
        $member = $this->member();

        $this->assertSame('/updates/12', NotificationLink::resolve($member, '/updates/12'));
        $this->assertSame('https://hub.example.test/updates/12', NotificationLink::resolve($member, 'https://hub.example.test/updates/12'));
    }

    public function test_the_push_payload_uses_the_resolved_destination(): void
    {
        $withAdmin = $this->withPermissions($this->rosterMember(), 'admin.access', 'admin.stations.view');
        $withoutAdmin = $this->withPermissions($this->rosterMember(), 'admin.stations.view');
        $alert = new NewSubmissionNotification('station_request', 'New Station Request', 'SR-1', '/admin/station-requests/1');

        $this->assertSame('/admin/station-requests/1', $alert->toWebPush($withAdmin, null)->toArray()['data']['url']);
        $this->assertSame('/employee', $alert->toWebPush($withoutAdmin, null)->toArray()['data']['url']);
        $this->assertSame('/employee', $alert->toDatabase($withoutAdmin)['actions'][0]['url']);
    }

    public function test_a_crafted_foreign_url_in_a_notification_never_reaches_the_device(): void
    {
        $member = $this->withRole($this->rosterMember(), 'training_viewer');
        $alert = new TrainingTodoAssignedNotification(3, 'Roster', 'high', 'https://evil.test/login');

        $this->assertSame('/employee', $alert->toWebPush($member, null)->toArray()['data']['url']);
        $this->assertSame('/employee', $alert->toDatabase($member)['actions'][0]['url']);
    }

    public function test_following_the_resolved_destination_works_for_the_recipient_and_is_denied_to_a_non_recipient(): void
    {
        $nonRecipient = $this->member();
        $resolved = NotificationLink::resolve($nonRecipient, '/admin/station-requests/1');

        $this->actingAs($nonRecipient)->get($resolved)->assertOk();

        $status = $this->actingAs($nonRecipient)->get('/admin/station-requests/1')->getStatusCode();
        $this->assertContains($status, [302, 403, 404], 'the admin panel refuses a member without access');
    }
}
