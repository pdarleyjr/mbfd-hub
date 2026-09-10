<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccountProfileResource\Pages\EditAccountProfile;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmployeeContextualControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['security.employee_bootstrap.secret' => 'Contextual-test-bootstrap-only']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create(['account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($actor);
    }

    public function test_each_employee_tab_contains_its_existing_protected_controls(): void
    {
        [$employee] = $this->member();
        $page = Livewire::test(EditEmployee::class, ['record' => $employee->id]);
        foreach ([
            'profile-identity' => ['Correct Employee ID', 'Employment status'],
            'profile-save' => ['Save profile changes'],
            'login-recovery' => ['Issue temporary password', 'Change city email', 'Disable account'],
            'hub-roles' => ['Edit Hub roles'],
            'hub-capabilities' => ['Edit direct Hub capabilities'],
            'ecosystem' => ['Edit application access', 'Edit application administrator roles'],
            'workgroups' => ['Manage workgroups'],
        ] as $context => $labels) {
            $this->assertContextContains($page->html(), $context, $labels);
        }
        self::assertSame([], $page->instance()->getCachedHeaderActions());
        self::assertSame(['cancel'], array_map(fn ($action) => $action->getName(), $page->instance()->getCachedFormActions()));
        $page->assertSee('Role-inherited capabilities must be changed through Hub roles, not direct grants.');

        foreach (['manageRoles', 'manageAdministrationCapabilities', 'manageApplicationAccess', 'manageApplicationAdministration', 'manageWorkgroups', 'resetPassword', 'correctEmployeeId'] as $action) {
            $page->mountAction($action)->assertActionMounted($action)
                ->assertSee('Your current administrator password')->assertSee('Reason for this change')
                ->call('unmountAction');
        }
    }

    public function test_wrong_password_in_contextual_role_action_cannot_change_roles(): void
    {
        [$employee, $target] = $this->member();
        Role::findOrCreate('admin', 'web');
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->mountAction('manageRoles')
            ->set('mountedActionsData.0.roles', ['admin'])
            ->set('mountedActionsData.0.current_password', 'incorrect-password')
            ->set('mountedActionsData.0.reason', 'Contextual control safety regression')
            ->call('callMountedAction')->assertHasActionErrors(['current_password']);
        self::assertSame(['member'], $target->fresh()->roles->pluck('name')->all());
    }

    public function test_read_only_and_self_profiles_do_not_expose_other_account_controls(): void
    {
        [$employee, $target] = $this->member();
        $viewer = User::factory()->create(['account_status' => 'active']);
        foreach (['admin.access', 'admin.personnel.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($viewer);
        Livewire::test(EditEmployee::class, ['record' => $employee->id])
            ->assertDontSee('Edit Hub roles')->assertDontSee('Edit application access')
            ->assertDontSee('Save profile changes')->assertSee('Read-only with your current access.')
            ->assertActionHidden('manageRoles')->assertActionHidden('save');
        $target->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $target->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($target);
        $page = Livewire::test(EditEmployee::class, ['record' => $employee->id]);
        $this->assertContextContains($page->html(), 'login-recovery', ['Change my password']);
        $page->assertDontSee('Edit Hub roles')->assertDontSee('Issue temporary password')
            ->assertSee('Save profile changes')->assertSee('Your own access cannot be changed here.');
    }

    public function test_awaiting_account_and_account_exceptions_keep_their_contextual_actions(): void
    {
        $employee = Employee::create(['employee_id' => '56001', 'name' => 'Awaiting account', 'roster_status' => 'active']);
        $page = Livewire::test(EditEmployee::class, ['record' => $employee->id]);
        $this->assertContextContains($page->html(), 'login-recovery', ['Create login account']);
        $this->assertContextContains($page->html(), 'hub-roles', ['Create a login account in Login & recovery before assigning roles, access or workgroups.']);
        $page->assertDontSee('Read-only with your current access.');
        $page->assertDontSee('Edit Hub roles')->assertDontSee('Manage workgroups');
        $target = User::factory()->create(['account_status' => 'active']);
        $page = Livewire::test(EditAccountProfile::class, ['record' => $target->id]);
        $this->assertContextContains($page->html(), 'profile-identity', ['Link verified employee', 'Approve nonemployee']);
        $this->assertContextContains($page->html(), 'login-recovery', ['Change recovery email']);
        $this->assertContextContains($page->html(), 'profile-save', ['Save profile changes']);
        $page->mountAction('linkEmployee')->assertActionMounted('linkEmployee')->assertSee('Exact employee record');
    }

    public function test_super_administrator_inherited_access_has_an_accurate_explanation(): void
    {
        [$employee, $target] = $this->member();
        $target->assignRole(Role::findOrCreate('super_admin', 'web'));
        $page = Livewire::test(EditEmployee::class, ['record' => $employee->id]);
        $this->assertContextContains($page->html(), 'ecosystem', ['Super Administrator privileges are inherited; manage Hub roles separately.']);
        $page->assertDontSee('Edit application access');
    }

    private function member(): array
    {
        $employee = Employee::create(['employee_id' => '56000', 'name' => 'Contextual Member', 'rank' => 'Captain', 'roster_status' => 'active']);
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => 'active']);
        $user->assignRole(Role::findOrCreate('member', 'web'));

        return [$employee, $user];
    }

    private function assertContextContains(string $html, string $context, array $labels): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@data-employee-controls="'.$context.'"]');
        self::assertSame(1, $nodes->length, 'One contextual action area expected: '.$context);
        $tab = match ($context) {
            'profile-identity', 'profile-save' => 'profile',
            'hub-roles', 'hub-capabilities' => 'administration',
            'ecosystem' => 'ecosystem-access',
            'workgroups' => 'workgroups-history',
            default => 'identity-security',
        };
        $panel = $xpath->query('ancestor::*[@role="tabpanel"]', $nodes->item(0));
        self::assertSame(1, $panel->length);
        self::assertSame('-'.$tab.'-tab', $panel->item(0)->attributes->getNamedItem('id')->nodeValue);
        foreach ($labels as $label) {
            self::assertStringContainsString($label, $nodes->item(0)->textContent);
        }
    }
}
