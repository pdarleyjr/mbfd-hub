<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Livewire\Mail\MailboxBadge;
use App\Support\HubShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

/** The header envelope and the Home "Email" entry: shown to people who may read mail, to nobody else. */
final class UiEntryPointsTest extends TestCase
{
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
    }

    public function test_the_header_envelope_carries_the_unread_count_in_its_name_and_links_to_the_page(): void
    {
        $this->conversation('One');
        $this->conversation('Two');
        $this->conversation('Three');
        $user = $this->mailViewer();

        $html = $this->actingAs($user)->get('/admin/email')->assertOk()->getContent();

        self::assertStringContainsString('fi-topbar-mail-btn', $html);
        self::assertStringContainsString('aria-label="Email, 3 unread"', $html);
        self::assertMatchesRegularExpression('#<a[^>]+href="[^"]*/admin/email"[^>]*fi-topbar-mail-btn|<a[^>]+fi-topbar-mail-btn[^>]*href="[^"]*/admin/email"#s', $html);
        self::assertStringContainsString('wire:poll.60s.visible', $html);
        self::assertStringContainsString('fi-icon-btn-badge-ctn', $html);
    }

    public function test_the_envelope_name_has_no_count_when_nothing_is_unread(): void
    {
        $this->signIn($this->mailViewer());

        Livewire::test(MailboxBadge::class)->assertSeeHtml('aria-label="Email"')->assertDontSeeHtml('Email, ')->assertSeeHtml('data-unread="0"');
    }

    public function test_the_envelope_is_absent_for_people_who_cannot_read_mail(): void
    {
        // A page every administrator can open, so the header is rendered for someone with no mail access.
        $user = $this->mailUser([]);

        $html = $this->actingAs($user)->get('/admin/notification-settings')->assertOk()->getContent();

        self::assertStringNotContainsString('fi-topbar-mail-btn', (string) $html);
        self::assertStringNotContainsString('mail-topbar-slot', (string) $html);
    }

    public function test_the_envelope_component_refuses_a_member_whose_access_was_removed(): void
    {
        $user = $this->mailViewer();
        $this->signIn($user);
        $badge = Livewire::test(MailboxBadge::class);

        $user->permissions()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $badge->call('refreshCount')->assertForbidden();
    }

    public function test_home_lists_email_right_after_admin_for_every_level_that_may_read_mail(): void
    {
        foreach ([
            'email view' => $this->mailViewer(),
            'email manage' => $this->mailAdmin(),
            'legacy view' => $this->mailUser(['admin.communications.view']),
            'legacy send' => $this->legacyMailAdmin(),
        ] as $label => $user) {
            $keys = array_column(HubShellNavigation::forUser($user)['applications'], 'key');
            $at = array_search('admin', $keys, true);

            self::assertNotFalse($at, "{$label} reaches Admin");
            self::assertSame('email', $keys[$at + 1] ?? null, "{$label} sees Email directly after Admin");
            self::assertSame(1, count(array_keys($keys, 'email', true)), 'exactly one Email entry');
            $entry = collect(HubShellNavigation::forUser($user)['applications'])->firstWhere('key', 'email');
            self::assertSame(['key' => 'email', 'label' => 'Email', 'href' => '/admin/email'], $entry);
        }
    }

    public function test_home_hides_email_from_ordinary_members_and_from_admins_without_mail_access(): void
    {
        foreach ([
            'panel access only' => $this->mailUser([]),
            'send alone' => $this->mailUser(['admin.email.send']),
            'no panel entitlement' => $this->mailUser(['admin.email.view'], panel: false),
            'must change password' => $this->mailUser(['admin.email.view'], attributes: ['must_change_password' => true]),
        ] as $label => $user) {
            $keys = array_column(HubShellNavigation::forUser($user)['applications'], 'key');

            self::assertNotContains('email', $keys, $label);
        }
        self::assertSame([], array_column(HubShellNavigation::forUser(null)['applications'], 'key'));
    }

    public function test_the_phone_more_sheet_and_the_apps_menu_render_the_email_link_from_the_same_list(): void
    {
        $user = $this->mailViewer();
        $navigation = HubShellNavigation::forUser($user);

        $menu = view('components.hub.app-switcher', ['navigation' => $navigation])->render();

        self::assertStringContainsString('href="/admin/email"', $menu);
        self::assertStringContainsString('>Email<', $menu);
        // The More sheet only exists for members who also have the Employee panel; give it the extra entry it needs.
        $navigation['memberNavigation'][] = ['key' => 'employee', 'label' => 'Employee', 'href' => '/employee'];
        $memberNavigation = view('components.hub.member-navigation', ['navigation' => $navigation])->render();
        self::assertStringContainsString('href="/admin/email"', $memberNavigation);
    }
}
