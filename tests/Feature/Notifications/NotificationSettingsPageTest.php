<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Filament\Pages\NotificationSettings;
use App\Models\User;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

final class NotificationSettingsPageTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    private const EVERYONE = ['member_request_updates', 'department_updates'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = app(NotificationCategoryRegistry::class)->forUser($user)->keys()->all();
        sort($keys);

        return $keys;
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    public function test_a_plain_firefighter_sees_only_the_alerts_every_member_gets(): void
    {
        $firefighter = $this->rosterMember('Firefighter');

        $this->assertSame($this->sorted(self::EVERYONE), $this->visibleKeys($firefighter));

        $this->actingAs($firefighter);
        Livewire::test(NotificationSettings::class)
            ->assertSuccessful()
            ->assertFormFieldExists('member_request_updates.database')
            ->assertFormFieldExists('member_request_updates.webpush')
            ->assertFormFieldExists('member_request_updates.email')
            ->assertFormFieldExists('department_updates.database')
            ->assertFormFieldExists('department_updates.webpush')
            ->assertFormFieldDoesNotExist('department_updates.email')
            ->assertFormFieldDoesNotExist('vehicle_inspections.database')
            ->assertFormFieldDoesNotExist('station_requests.database')
            ->assertFormFieldDoesNotExist('system_alerts.database');
    }

    public function test_a_captain_sees_captain_5_alerts_but_the_unwired_300_channel_stays_hidden(): void
    {
        $captain = $this->rosterMember('Captain');

        $this->assertTrue(app(Command300Access::class)->allows($captain), 'a Captain is a 300 Command member');
        $registry = app(NotificationCategoryRegistry::class);
        // 300 Command has no notification sender yet, so it stays reserved and hidden.
        $this->assertFalse($registry->get('command_communications')->senderWired);
        $this->assertTrue($registry->get('captain5_ems')->senderWired);
        $this->assertSame('Captain 5 EMS alerts', $registry->get('captain5_ems')->label);
        // An officer rank qualifies for Captain 5; approving acting requests is a separate, narrower rule.
        $this->assertSame($this->sorted([...self::EVERYONE, 'captain5_ems']), $this->visibleKeys($captain));
    }

    public function test_captain_5_alerts_appear_and_disappear_with_captain_5_access(): void
    {
        $member = $this->rosterMember('Firefighter');
        $this->assertNotContains('captain5_ems', $this->visibleKeys($member));

        $promoted = tap($member->employeeProfile)->update(['rank' => 'Lieutenant']);
        app(Captain5Access::class)->flush();
        $this->assertContains('captain5_ems', $this->visibleKeys($member->fresh()));

        $promoted->update(['rank' => 'Firefighter']);
        app(Captain5Access::class)->flush();
        $this->assertNotContains('captain5_ems', $this->visibleKeys($member->fresh()));
    }

    public function test_only_people_who_configure_captain_5_see_acting_requests(): void
    {
        foreach (['admin.access', \App\Services\Security\Captain5Access::PERMISSION_ADMIN] as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $this->assertNotContains('captain5_assignment_requests', $this->visibleKeys($this->rosterMember('Captain')));
        $this->assertNotContains('captain5_assignment_requests', $this->visibleKeys($this->rosterMember('Division Chief')), 'rank alone does not approve');
        $approver = $this->withPermissions($this->rosterMember('Division Chief'), 'admin.access', \App\Services\Security\Captain5Access::PERMISSION_ADMIN);
        $this->assertContains('captain5_assignment_requests', $this->visibleKeys($approver));
    }

    public function test_a_fleet_reviewer_sees_checkout_and_swap_alerts_but_not_unrelated_admin_alerts(): void
    {
        $reviewer = $this->withPermissions($this->rosterMember('Captain'), 'admin.access', 'admin.fleet.manage');

        $keys = $this->visibleKeys($reviewer);

        foreach (['vehicle_inspections', 'vehicle_swap_reports', 'vehicle_identification_reports', 'vehicle_maintenance_alerts', ...self::EVERYONE] as $key) {
            $this->assertContains($key, $keys);
        }
        foreach (['uniform_requests', 'system_alerts', 'hub_support_tickets', 'station_requests', 'training_assignments'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_a_logistics_admin_sees_support_services_and_fleet_alerts(): void
    {
        $logistics = $this->withRole($this->rosterMember(), 'logistics_admin');

        $keys = $this->visibleKeys($logistics);

        foreach (['uniform_requests', 'personnel_equipment_requests', 'apparatus_service_tickets', 'vehicle_inspections', ...self::EVERYONE] as $key) {
            $this->assertContains($key, $keys);
        }
        foreach (['system_alerts', 'hub_support_tickets', 'vehicle_swap_reports', 'vehicle_identification_reports'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_an_administrator_with_the_admin_role_sees_system_alerts(): void
    {
        $admin = $this->withRole($this->member(), 'admin');

        $this->assertContains('system_alerts', $this->visibleKeys($admin));
    }

    public function test_a_super_admin_sees_every_category_that_has_a_sender(): void
    {
        $super = $this->withRole($this->rosterMember(), 'super_admin');

        $wired = collect(app(NotificationCategoryRegistry::class)->all())
            ->filter(fn ($category) => $category->senderWired)->keys()->all();
        sort($wired);

        $this->assertSame($wired, $this->visibleKeys($super));
        $this->assertNotContains('fire_equipment_requests', $this->visibleKeys($super), 'the dead category was removed');
    }

    public function test_a_disabled_member_cannot_open_the_page_and_has_no_categories(): void
    {
        $disabled = $this->withRole($this->member(['account_status' => AccountStatus::Disabled]), 'super_admin');

        $this->assertSame([], $this->visibleKeys($disabled));
        // actingAs() re-activates its user in this harness; be() keeps the disabled state.
        $this->be($disabled);
        $this->assertFalse(NotificationSettings::canAccess());
        Livewire::test(NotificationSettings::class)->assertForbidden();
    }

    public function test_categories_appear_and_disappear_as_roles_change_without_stored_eligibility(): void
    {
        $member = $this->rosterMember();
        $this->assertNotContains('uniform_requests', $this->visibleKeys($member));

        $member = $this->withRole($member, 'logistics_admin');
        $this->assertContains('uniform_requests', $this->visibleKeys($member));

        $member->removeRole('logistics_admin');
        $this->assertNotContains('uniform_requests', $this->visibleKeys($member->fresh()));
        $this->assertSame(0, DB::table('user_notification_subscriptions')->count(), 'viewing eligibility never writes a row');
    }

    public function test_a_save_stores_the_members_own_choices_and_only_where_they_differ_from_the_default(): void
    {
        $member = $this->rosterMember('Firefighter', 'member@miamibeachfl.gov');
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->assertFormSet([
                'member_request_updates' => ['database' => true, 'webpush' => false, 'email' => false],
                'department_updates' => ['database' => true, 'webpush' => true],
            ])
            ->fillForm([
                'member_request_updates' => ['webpush' => false, 'email' => true],
                'department_updates' => ['database' => true, 'webpush' => false],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $mail = $member->notificationSubscriptions()->where('event_key', 'member_request_updates')->sole();
        $this->assertTrue($mail->database_enabled, 'locked in-app stays on');
        $this->assertTrue($mail->email_enabled);
        $this->assertFalse($mail->webpush_enabled);

        $updates = $member->notificationSubscriptions()->where('event_key', 'department_updates')->sole();
        $this->assertTrue($updates->database_enabled);
        $this->assertFalse($updates->webpush_enabled);
        $this->assertFalse($updates->email_enabled, 'a channel the category does not support is never stored as on');
    }

    public function test_an_unchanged_save_writes_no_rows(): void
    {
        $member = $this->rosterMember();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)->call('save');

        $this->assertSame(0, $member->notificationSubscriptions()->count());
    }

    public function test_an_ordinary_save_confirms_without_a_warning(): void
    {
        $member = $this->rosterMember();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->set('data.department_updates.webpush', false)
            ->call('save')
            ->assertNotified(
                \Filament\Notifications\Notification::make()
                    ->title('Notification preferences saved')
                    ->success()
                    ->body('Your alert choices are saved.'),
            );
    }

    public function test_a_crafted_category_still_draws_the_warning(): void
    {
        $member = $this->rosterMember();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->set('data.station_requests', ['database' => true])
            ->call('save')
            ->assertNotified(
                \Filament\Notifications\Notification::make()
                    ->title('Notification preferences saved')
                    ->warning()
                    ->body('Some settings are not available to your account and were not saved.'),
            );
    }

    public function test_a_crafted_save_for_a_category_the_member_is_not_eligible_for_is_ignored(): void
    {
        $member = $this->rosterMember();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->set('data.station_requests', ['database' => true, 'webpush' => true, 'email' => true])
            ->set('data.fire_equipment_requests', ['database' => true])
            ->set('data.member_request_updates.sms', true)
            ->call('save');

        $this->assertDatabaseMissing('user_notification_subscriptions', ['user_id' => $member->id, 'event_key' => 'station_requests']);
        $this->assertDatabaseMissing('user_notification_subscriptions', ['user_id' => $member->id, 'event_key' => 'fire_equipment_requests']);
    }

    public function test_a_crafted_channel_the_category_does_not_support_is_never_stored(): void
    {
        $member = $this->rosterMember();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->set('data.department_updates.email', true)
            ->set('data.department_updates.webpush', false)
            ->call('save');

        $row = $member->notificationSubscriptions()->where('event_key', 'department_updates')->sole();
        $this->assertFalse($row->email_enabled);
        $this->assertFalse($row->webpush_enabled);
    }

    public function test_a_member_who_lost_a_role_cannot_save_a_choice_for_the_category_it_granted(): void
    {
        $logistics = $this->withRole($this->rosterMember(), 'logistics_admin');
        $this->actingAs($logistics);
        $page = Livewire::test(NotificationSettings::class)->assertFormFieldExists('uniform_requests.database');

        $logistics->removeRole('logistics_admin');
        $this->actingAs($logistics->fresh());
        $page->set('data.uniform_requests', ['database' => true, 'webpush' => true, 'email' => true])->call('save');

        $this->assertDatabaseMissing('user_notification_subscriptions', ['user_id' => $logistics->id, 'event_key' => 'uniform_requests']);
    }

    public function test_opening_the_page_writes_nothing(): void
    {
        $manager = $this->withRole($this->rosterMember(), 'logistics_admin');
        $this->actingAs($manager);
        DB::enableQueryLog();

        Livewire::test(NotificationSettings::class)->assertSuccessful();

        $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1);
        $this->assertCount(0, $writes, 'GET writes: '.$writes->pluck('query')->implode(' | '));
    }

    public function test_the_page_stays_within_a_query_budget_for_the_widest_audience(): void
    {
        $super = $this->withRole($this->rosterMember(), 'super_admin');
        $this->addPushDevice($super);
        $this->actingAs($super);
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(NotificationSettings::class)->assertSuccessful();

        $this->assertLessThanOrEqual(75, count(DB::getQueryLog()), 'settings page queries: '.count(DB::getQueryLog()));
    }

    public function test_a_member_can_remove_only_their_own_device(): void
    {
        $member = $this->rosterMember();
        $other = $this->rosterMember();
        $this->addPushDevice($member, 'phone');
        $this->addPushDevice($other, 'phone');
        $mine = $member->pushSubscriptions()->sole();
        $theirs = $other->pushSubscriptions()->sole();
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->call('removeDevice', $theirs->id)
            ->assertSuccessful();

        $this->assertSame(1, $other->pushSubscriptions()->count(), 'someone else\'s device is untouched');

        Livewire::test(NotificationSettings::class)->call('removeDevice', $mine->id);
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    public function test_the_employee_panel_registers_the_page_and_links_to_it(): void
    {
        $panel = Filament::getPanel('employee');

        $this->assertContains(NotificationSettings::class, $panel->getPages());
        $this->assertSame(url('/employee/notification-settings'), NotificationSettings::getUrl(panel: 'employee'));
        $this->assertTrue(NotificationSettings::shouldRegisterNavigation(), 'listed in the Employee sidebar');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertFalse(NotificationSettings::shouldRegisterNavigation(), 'Admin keeps its user-menu entry only');
    }

    public function test_the_account_page_links_to_notification_settings(): void
    {
        $member = $this->rosterMember();

        $response = $this->actingAs($member)->get('/account');

        $response->assertOk()->assertSee('/employee/notification-settings', false);
    }
}
