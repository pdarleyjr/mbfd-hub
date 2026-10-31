<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Filament\Admin\Pages\EmailCenter;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\InboundEmailResource;
use App\Filament\Resources\OutboundEmailResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

final class UiPageAccessTest extends TestCase
{
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/admin/email')->assertRedirect();
    }

    public function test_the_page_opens_for_every_level_that_may_read_mail(): void
    {
        $thread = $this->conversation('Quarterly hose test', 'Results attached.');

        foreach ([
            'view' => $this->mailViewer(),
            'send' => $this->mailUser(['admin.email.view', 'admin.email.send']),
            'manage' => $this->mailAdmin(),
            'legacy view' => $this->mailUser(['admin.communications.view']),
            'legacy send' => $this->legacyMailAdmin(),
        ] as $level => $user) {
            $this->actingAs($user)->get('/admin/email')->assertOk()->assertSee('Quarterly hose test', false);
            self::assertTrue(EmailCenter::canAccess(), "{$level} may open the page");
        }
        self::assertNotNull($thread);
    }

    public function test_everyone_else_is_refused_even_with_a_stale_or_partial_grant(): void
    {
        foreach ([
            'no permission' => $this->mailUser([]),
            'send alone' => $this->mailUser(['admin.email.send']),
            'manage alone' => $this->mailUser(['admin.email.manage']),
            'no panel entitlement' => $this->mailUser(['admin.email.view'], panel: false),
        ] as $label => $user) {
            $this->actingAs($user)->get('/admin/email')->assertForbidden();
            self::assertFalse(EmailCenter::canAccess(), $label);
        }
    }

    public function test_an_account_that_must_change_its_password_opens_nothing(): void
    {
        // The HTTP layer sends such an account to the password page before any page renders; the access rule says no too.
        $this->signIn($this->mailUser(['admin.email.view'], attributes: ['must_change_password' => true]));

        self::assertFalse(EmailCenter::canAccess());
    }

    public function test_a_page_action_is_refused_when_access_is_revoked_between_requests(): void
    {
        $user = $this->mailAdmin();
        $this->signIn($user);
        $page = Livewire::test(EmailCenter::class);

        $user->permissions()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $page->call('openFolder', 'sent')->assertForbidden();
    }

    public function test_the_sidebar_has_one_email_entry_and_none_of_the_old_three(): void
    {
        $this->signIn($this->mailAdmin());

        $labels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        self::assertSame(1, count(array_keys($labels, 'Email', true)), 'exactly one Email entry');
        foreach (['Inbound Email', 'Outbound Email', 'Compose Email'] as $old) {
            self::assertNotContains($old, $labels);
        }
        self::assertFalse(InboundEmailResource::shouldRegisterNavigation());
        self::assertFalse(OutboundEmailResource::shouldRegisterNavigation());
        self::assertFalse(ComposeEmail::shouldRegisterNavigation());
        self::assertTrue(EmailCenter::shouldRegisterNavigation());
    }

    public function test_the_email_entry_is_hidden_from_members_who_cannot_read_mail(): void
    {
        $this->signIn($this->mailUser([]));

        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->all();

        self::assertNotContains('Email', $labels);
    }

    public function test_the_unread_badge_counts_this_members_unread_conversations_only(): void
    {
        $this->conversation('One');
        $this->conversation('Two');
        $user = $this->mailAdmin();
        $this->signIn($user);

        self::assertSame('2', EmailCenter::getNavigationBadge());
        self::assertSame('danger', EmailCenter::getNavigationBadgeColor());
    }

    public function test_the_folder_nav_offers_compose_and_trash_only_to_those_who_may_use_them(): void
    {
        $this->conversation();

        $this->actingAs($this->mailViewer())->get('/admin/email')->assertOk()
            ->assertDontSee('wire:click="startNewMessage"', false)
            ->assertDontSee('data-mail-folder="trash"', false)
            ->assertSee('data-mail-folder="health"', false)
            ->assertSee('data-mail-folder="automated"', false);

        $this->actingAs($this->mailUser(['admin.email.view', 'admin.email.send']))->get('/admin/email')->assertOk()
            ->assertSee('wire:click="startNewMessage"', false)
            ->assertDontSee('data-mail-folder="trash"', false);

        $this->actingAs($this->mailAdmin())->get('/admin/email')->assertOk()
            ->assertSee('wire:click="startNewMessage"', false)
            ->assertSee('data-mail-folder="trash"', false);
    }

    public function test_a_viewer_who_opens_the_trash_by_url_lands_in_the_inbox(): void
    {
        $this->signIn($this->mailViewer());

        Livewire::test(EmailCenter::class, ['folder' => 'trash'])->assertSet('folder', 'inbox');
    }
}
