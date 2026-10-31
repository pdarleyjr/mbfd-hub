<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\AccountStatus;
use App\Services\Mail\MailAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MailAccessTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    public function test_view_and_send_are_separate_capabilities_on_top_of_the_admin_panel_entitlement(): void
    {
        $access = app(MailAccess::class);
        $both = $this->mailAdmin();
        $viewer = $this->mailViewer();
        $sender = $this->mailUser(['admin.communications.send']);

        self::assertTrue($access->canView($both));
        self::assertTrue($access->canSend($both));
        self::assertTrue($access->canView($viewer));
        self::assertFalse($access->canSend($viewer));
        self::assertFalse($access->canView($sender));
        self::assertFalse($access->canSend($sender), 'sending includes reading: a send grant alone opens nothing');
    }

    public function test_a_communications_grant_without_the_admin_panel_opens_nothing(): void
    {
        $user = $this->mailUser(['admin.communications.view', 'admin.communications.send'], panel: false);

        self::assertFalse(app(MailAccess::class)->canView($user));
        self::assertFalse(app(MailAccess::class)->canSend($user));
    }

    public function test_a_super_administrator_has_both(): void
    {
        $user = $this->mailUser([]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        self::assertTrue(app(MailAccess::class)->canView($user->fresh()));
        self::assertTrue(app(MailAccess::class)->canSend($user->fresh()));
    }

    public function test_disabled_accounts_accounts_forced_to_change_password_and_guests_are_refused(): void
    {
        $access = app(MailAccess::class);
        $disabled = $this->mailAdmin();
        $disabled->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $mustChange = $this->mailUser(['admin.communications.view', 'admin.communications.send'], attributes: ['must_change_password' => true]);

        self::assertFalse($access->canView($disabled->fresh()));
        self::assertFalse($access->canSend($disabled->fresh()));
        self::assertFalse($access->canView($mustChange));
        self::assertFalse($access->canSend($mustChange));
        self::assertFalse($access->canView(null));
        self::assertFalse($access->canSend(null));
    }
}
