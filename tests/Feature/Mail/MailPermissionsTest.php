<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\Mail\MailThread;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailActionRefused;
use App\Services\Mail\MailboxActions;
use App\Services\Mail\MailboxFilter;
use App\Services\Mail\MailboxQuery;
use App\Services\Mail\MailDraftService;
use App\Services\Mail\MailRecipientDirectory;
use App\Services\Security\ApplicationAccessService;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

/**
 * Email is a first-class capability: admin.email.view / send / manage, with the older
 * admin.communications.view / send honoured as a fallback so that nobody who could read or send mail loses
 * that at deploy (the deploy gate refuses any role or permission change).
 */
final class MailPermissionsTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    private const NAMES = ['admin.email.view', 'admin.email.send', 'admin.email.manage'];

    private const PASSWORD = 'Mail-capability-test-password!';

    /** @return array<string, array{list<string>, bool, bool, bool}> grants, view, send, manage */
    public static function levels(): array
    {
        return [
            'panel entitlement only' => [[], false, false, false],
            'email.view' => [['admin.email.view'], true, false, false],
            'legacy communications.view' => [['admin.communications.view'], true, false, false],
            'email.view + email.send' => [['admin.email.view', 'admin.email.send'], true, true, false],
            'legacy view + legacy send' => [['admin.communications.view', 'admin.communications.send'], true, true, false],
            'email.view + legacy send' => [['admin.email.view', 'admin.communications.send'], true, true, false],
            'legacy view + email.send' => [['admin.communications.view', 'admin.email.send'], true, true, false],
            'email.send alone' => [['admin.email.send'], false, false, false],
            'legacy send alone' => [['admin.communications.send'], false, false, false],
            'email.manage alone' => [['admin.email.manage'], false, false, false],
            'email.view + email.manage' => [['admin.email.view', 'admin.email.manage'], true, false, true],
            'all three' => [self::NAMES, true, true, true],
            'legacy pair + manage' => [['admin.communications.view', 'admin.communications.send', 'admin.email.manage'], true, true, true],
        ];
    }

    /** @param list<string> $grants */
    #[DataProvider('levels')]
    public function test_the_rule_table(array $grants, bool $view, bool $send, bool $manage): void
    {
        $access = app(MailAccess::class);
        $user = $this->mailUser($grants);
        $noPanel = $this->mailUser($grants, panel: false);

        self::assertSame($view, $access->canView($user), 'canView');
        self::assertSame($send, $access->canSend($user), 'canSend');
        self::assertSame($manage, $access->canManage($user), 'canManage');
        self::assertFalse($access->canView($noPanel), 'without panel entitlement nothing is open');
        self::assertFalse($access->canSend($noPanel));
        self::assertFalse($access->canManage($noPanel));
    }

    public function test_super_administrators_and_guests(): void
    {
        $access = app(MailAccess::class);
        $super = $this->mailUser([]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $super = $super->fresh();

        self::assertTrue($access->canView($super));
        self::assertTrue($access->canSend($super));
        self::assertTrue($access->canManage($super));
        self::assertFalse($access->canView(null));
        self::assertFalse($access->canSend(null));
        self::assertFalse($access->canManage(null));
    }

    public function test_a_permission_held_through_a_role_counts_as_it_did_before(): void
    {
        $role = Role::findOrCreate('mail_reader', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.communications.view', 'web'));
        $user = $this->mailUser([]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        self::assertTrue(app(MailAccess::class)->canView($user->fresh()));
        self::assertFalse(app(MailAccess::class)->canSend($user->fresh()));
    }

    public function test_the_permissions_are_not_there_for_a_member_whose_account_is_unusable(): void
    {
        $disabled = $this->mailAdmin();
        $disabled->forceFill(['account_status' => 'disabled'])->save();
        $mustChange = $this->mailUser(self::NAMES, attributes: ['must_change_password' => true]);

        foreach ([$disabled->fresh(), $mustChange] as $user) {
            self::assertFalse(app(MailAccess::class)->canView($user));
            self::assertFalse(app(MailAccess::class)->canManage($user));
        }
    }

    // --- enforcement in the services, not just the UI -----------------------------------------

    public function test_trash_and_restore_need_manage_while_other_shared_actions_need_send(): void
    {
        $sender = $this->mailUser(['admin.email.view', 'admin.email.send']);
        $legacy = $this->legacyMailAdmin();
        $manager = $this->mailAdmin();
        $actions = app(MailboxActions::class);
        $thread = $this->threadOf($this->inbound());

        foreach ([$sender, $legacy] as $user) {
            foreach ([fn () => $actions->trash($user, $thread), fn () => $actions->restore($user, $thread)] as $attempt) {
                try {
                    $attempt();
                    self::fail('trash/restore worked without admin.email.manage');
                } catch (MailActionRefused $refused) {
                    self::assertSame(403, $refused->getStatusCode());
                }
            }
            // …but everything send-level works, including for the legacy pair.
            $actions->archive($user, $thread);
            $actions->unarchive($user, $thread);
            $actions->setPriority($user, $thread, 'high');
            $actions->snooze($user, $thread, now()->addDay());
            $actions->snooze($user, $thread, null);
            $actions->addLabel($user, $thread, 'Budget');
            $actions->addNote($user, $thread, 'A note');
            $actions->assign($user, $thread, $user);
        }
        self::assertNull($thread->fresh()->trashed_at);

        $actions->trash($manager, $thread);
        self::assertNotNull($thread->trashed_at);
        $actions->restore($manager, $thread);
        self::assertNull($thread->trashed_at);
    }

    public function test_a_manager_without_send_cannot_use_send_level_actions(): void
    {
        $viewManager = $this->mailUser(['admin.email.view', 'admin.email.manage']);
        $actions = app(MailboxActions::class);
        $thread = $this->threadOf($this->inbound());

        foreach ([fn () => $actions->archive($viewManager, $thread), fn () => $actions->addNote($viewManager, $thread, 'x'), fn () => $actions->assign($viewManager, $thread, null)] as $attempt) {
            try {
                $attempt();
                self::fail('a send-level action worked without send access');
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode());
            }
        }
        $actions->trash($viewManager, $thread); // manage is its own level
        self::assertNotNull($thread->trashed_at);
    }

    public function test_view_is_enough_for_reading_read_state_and_stars_and_nothing_else(): void
    {
        $viewer = $this->mailUser(['admin.email.view']);
        $actions = app(MailboxActions::class);
        $query = app(MailboxQuery::class);
        $thread = $this->threadOf($this->inbound());

        self::assertCount(1, $query->threads($viewer, MailboxFilter::folder('inbox'))->items());
        self::assertSame(1, $query->unreadCount($viewer));
        $query->thread($viewer, $thread->id);
        $actions->markRead($viewer, $thread);
        $actions->markUnread($viewer, $thread);
        $actions->star($viewer, $thread);
        $actions->unstar($viewer, $thread);
        $detail = $query->thread($viewer, $thread->id);
        self::assertFalse($detail->canSend);
        self::assertFalse($detail->canManage);
        $this->expectException(MailActionRefused::class);
        app(MailDraftService::class)->open($viewer, 'new');
    }

    public function test_the_detail_reports_the_viewers_levels(): void
    {
        $thread = $this->threadOf($this->inbound());
        $query = app(MailboxQuery::class);

        $manager = $query->thread($this->mailAdmin(), $thread->id);
        $sender = $query->thread($this->mailUser(['admin.email.view', 'admin.email.send']), $thread->id);

        self::assertSame([true, true], [$manager->canSend, $manager->canManage]);
        self::assertSame([true, false], [$sender->canSend, $sender->canManage]);
    }

    public function test_a_member_with_the_panel_but_no_email_permission_is_refused_everywhere(): void
    {
        $nobody = $this->mailUser([]);
        $thread = $this->threadOf($this->inbound());
        $query = app(MailboxQuery::class);
        $actions = app(MailboxActions::class);

        foreach ([
            fn () => $query->threads($nobody, MailboxFilter::folder('inbox')),
            fn () => $query->thread($nobody, $thread->id),
            fn () => $query->counts($nobody),
            fn () => $actions->markRead($nobody, $thread),
            fn () => $actions->star($nobody, $thread),
            fn () => $actions->archive($nobody, $thread),
            fn () => $actions->trash($nobody, $thread),
            fn () => app(MailDraftService::class)->open($nobody, 'new'),
            fn () => app(MailRecipientDirectory::class)->suggest($nobody, 'chief'),
        ] as $attempt) {
            $this->expectsForbidden($attempt);
        }
    }

    public function test_an_email_permission_without_the_panel_is_refused_everywhere(): void
    {
        $noPanel = $this->mailUser(self::NAMES, panel: false);
        $thread = $this->threadOf($this->inbound());

        $this->expectsForbidden(fn () => app(MailboxQuery::class)->threads($noPanel, MailboxFilter::folder('inbox')));
        $this->expectsForbidden(fn () => app(MailboxActions::class)->trash($noPanel, $thread));
        $this->expectsForbidden(fn () => app(MailDraftService::class)->open($noPanel, 'new'));
        $this->expectsForbidden(fn () => app(MailRecipientDirectory::class)->suggest($noPanel, 'chief'));
    }

    private function expectsForbidden(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('expected a refusal');
        } catch (MailActionRefused $refused) {
            self::assertSame(403, $refused->getStatusCode());
        }
    }

    public function test_automated_threads_stay_read_only_even_for_super_administrators_with_every_permission(): void
    {
        $super = $this->mailAdmin();
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $automated = $this->threadOf($this->outbound(['source_type' => 'password_reset']));

        foreach (['trash', 'archive', 'markRead', 'star'] as $method) {
            try {
                app(MailboxActions::class)->{$method}($super->fresh(), $automated);
                self::fail("{$method} worked on an automated thread");
            } catch (MailActionRefused $refused) {
                self::assertSame(403, $refused->getStatusCode());
            }
        }
    }

    // --- routes ---------------------------------------------------------------------------------

    public function test_the_message_routes_follow_the_same_view_rule_including_the_legacy_fallback(): void
    {
        $email = $this->inbound(['sanitized_html_body' => '<p>Hello</p>']);
        $url = "/admin/email/messages/inbound/{$email->id}/body";

        $this->actingAs($this->mailUser(['admin.email.view']))->get($url)->assertOk();
        $this->actingAs($this->mailUser(['admin.communications.view']))->get($url)->assertOk();
        $this->actingAs($this->mailUser(['admin.email.send']))->get($url)->assertForbidden();
        $this->actingAs($this->mailUser(['admin.email.manage']))->get($url)->assertForbidden();
        $this->actingAs($this->mailUser([]))->get($url)->assertForbidden();
        $this->actingAs($this->mailUser(['admin.email.view'], panel: false))->get($url)->assertForbidden();
    }

    // --- provisioning ----------------------------------------------------------------------------

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_31_100400_create_email_permissions.php');
    }

    public function test_the_migration_creates_the_three_permissions_and_grants_them_to_nobody(): void
    {
        $legacy = $this->legacyMailAdmin();
        $panelOnly = $this->mailUser([]);
        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $before = DB::table('model_has_permissions')->count() + DB::table('role_has_permissions')->count();

        $this->migration()->up();
        $this->migration()->up(); // idempotent

        foreach (self::NAMES as $name) {
            self::assertSame(1, Permission::query()->where('name', $name)->where('guard_name', 'web')->count(), $name);
            self::assertSame(0, Permission::query()->where('name', $name)->where('guard_name', '!=', 'web')->count());
            self::assertFalse($legacy->fresh()->hasDirectWebPermission($name));
            self::assertFalse($panelOnly->fresh()->hasDirectWebPermission($name));
        }
        $ids = Permission::query()->whereIn('name', self::NAMES)->pluck('id');
        self::assertSame(0, DB::table('model_has_permissions')->whereIn('permission_id', $ids)->count() + DB::table('role_has_permissions')->whereIn('permission_id', $ids)->count());
        self::assertSame($before, DB::table('model_has_permissions')->count() + DB::table('role_has_permissions')->count(), 'no account or role gains anything');
    }

    public function test_the_legacy_reader_keeps_reading_with_no_grant_made_at_deploy(): void
    {
        $reader = $this->legacyMailAdmin();
        $ids = Permission::query()->whereIn('name', self::NAMES)->pluck('id');
        self::assertSame(0, DB::table('model_has_permissions')->whereIn('permission_id', $ids)->count());

        self::assertTrue(app(MailAccess::class)->canView($reader));
        self::assertTrue(app(MailAccess::class)->canSend($reader));
        self::assertFalse(app(MailAccess::class)->canManage($reader), 'trash and restore are the only thing a grant adds');
    }

    public function test_the_migration_refuses_to_roll_back_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->migration()->down();
            self::fail('down() ran in production');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('never removed outside local or testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        self::assertSame(3, Permission::query()->whereIn('name', self::NAMES)->count());
    }

    public function test_the_capability_editor_lists_the_options_with_their_labels(): void
    {
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();

        self::assertSame("Email \u{2014} read the shared mailbox", $options['admin.email.view']);
        self::assertSame("Email \u{2014} send and reply", $options['admin.email.send']);
        self::assertSame("Email \u{2014} trash and restore", $options['admin.email.manage']);
        self::assertSame("Communications \u{2014} view", $options['admin.communications.view'], 'the legacy options keep their meaning');
        self::assertSame("Communications \u{2014} send email", $options['admin.communications.send']);
    }

    public function test_saving_through_the_editors_own_code_path_grants_and_revokes_each_level(): void
    {
        $actor = User::factory()->create(['password' => self::PASSWORD, 'account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $target = User::factory()->create(['account_status' => 'active']);
        $target->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $service = app(ApplicationAccessService::class);
        $access = app(MailAccess::class);

        $service->syncAdministrationCapabilities($actor, $target, ['admin.email.view'], self::PASSWORD, 'Read the shared mailbox');
        self::assertSame([true, false, false], $this->levelsOf($target));

        $service->syncAdministrationCapabilities($actor, $target, ['admin.email.view', 'admin.email.send'], self::PASSWORD, 'Reply duties');
        self::assertSame([true, true, false], $this->levelsOf($target));

        $service->syncAdministrationCapabilities($actor, $target, self::NAMES, self::PASSWORD, 'Mailbox steward');
        self::assertSame([true, true, true], $this->levelsOf($target));
        $granted = SecurityActionEvent::query()->where('action', 'change_admin_capabilities')->orderByDesc('id')->first();
        self::assertSame('allowed', $granted->result);
        self::assertContains('admin.email.manage', json_decode($granted->getRawOriginal('metadata'), true, flags: JSON_THROW_ON_ERROR)['granted']);

        $service->syncAdministrationCapabilities($actor, $target, [], self::PASSWORD, 'Duties ended');
        self::assertSame([false, false, false], $this->levelsOf($target));
        self::assertTrue($target->fresh()->hasCurrentAdminPanelEntitlement(), 'the editor never touched panel access');
        self::assertNotNull($access);
    }

    /** @return array{bool, bool, bool} */
    private function levelsOf(User $user): array
    {
        $fresh = $user->fresh();
        $access = app(MailAccess::class);

        return [$access->canView($fresh), $access->canSend($fresh), $access->canManage($fresh)];
    }

    public function test_the_older_communications_permissions_keep_their_own_meaning_elsewhere(): void
    {
        $emailOnly = $this->mailAdmin();
        $legacy = $this->legacyMailAdmin();

        // CommunicationsUsage, the Sent/Inbound resources and 300 Command Communications still ask for these.
        self::assertFalse($emailOnly->can('admin.communications.view'));
        self::assertFalse($emailOnly->can('admin.communications.send'));
        self::assertTrue($legacy->can('admin.communications.view'));
        self::assertTrue($legacy->can('admin.communications.send'));
        self::assertTrue(MailThread::query()->count() >= 0);
    }
}
