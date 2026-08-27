<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Employee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class EmployeeForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagged_employee_cannot_invoke_a_dashboard_livewire_update(): void
    {
        $this->withoutVite();
        $employee = $this->employee(false);
        $dashboardUrl = route('filament.employee.pages.dashboard');
        $changePasswordUrl = route('filament.employee.pages.change-password-page');

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($employee, 'employee')->get($dashboardUrl)->assertOk(),
        );

        $employee->forceFill(['must_change_password' => true])->save();

        $response = $this->actingAs($employee->fresh(), 'employee')
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ]);

        $response->assertRedirect($changePasswordUrl);
        self::assertTrue($employee->fresh()->must_change_password);
        self::assertSame('employee', Filament::getPanel('employee')->getId());
    }

    public function test_flagged_employee_can_change_password_through_livewire_and_regain_dashboard_access(): void
    {
        $this->withoutVite();
        Http::fake([
            'https://api.pwnedpasswords.com/range/*' => Http::response('', 200),
        ]);
        $employee = $this->employee(true);
        $dashboardUrl = route('filament.employee.pages.dashboard');
        $changePasswordUrl = route('filament.employee.pages.change-password-page');

        $snapshot = $this->livewireSnapshotFrom(
            $this->actingAs($employee, 'employee')->get($changePasswordUrl)->assertOk(),
            'change-password-page',
        );

        $response = $this->actingAs($employee, 'employee')
            ->withHeader('X-Livewire', 'true')
            ->postJson('/livewire/update', [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'data.password' => 'A-longer-employee-password-2026',
                        'data.password_confirmation' => 'A-longer-employee-password-2026',
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'save',
                        'params' => [],
                    ]],
                ]],
            ]);

        $response->assertOk();
        self::assertFalse($employee->fresh()->must_change_password);
        self::assertTrue(Hash::check('A-longer-employee-password-2026', $employee->fresh()->password));
        $this->actingAs($employee->fresh(), 'employee')->get($dashboardUrl)->assertOk();
    }

    public function test_flagged_employee_can_log_out_without_changing_password(): void
    {
        $employee = $this->employee(true);

        $this->actingAs($employee, 'employee')
            ->post(Filament::getPanel('employee')->getLogoutUrl())
            ->assertRedirect();

        $this->assertGuest('employee');
    }

    private function employee(bool $mustChangePassword): Employee
    {
        return Employee::query()->create([
            'employee_id' => 'E-TEST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Employee Password Test',
            'rank' => 'Firefighter',
            'password' => Hash::make('current-employee-password'),
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function livewireSnapshotFrom(TestResponse $response, ?string $componentNameSuffix = null): string
    {
        $matched = preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);

        self::assertGreaterThan(0, $matched, 'Expected a real Livewire snapshot in the employee panel response.');

        $componentNames = [];

        foreach ($matches[1] as $encodedSnapshot) {
            $snapshot = html_entity_decode($encodedSnapshot, ENT_QUOTES);
            $componentName = data_get(json_decode($snapshot, true), 'memo.name');
            $componentNames[] = (string) $componentName;

            if ($componentNameSuffix === null || str_ends_with((string) $componentName, $componentNameSuffix)) {
                return $snapshot;
            }
        }

        self::fail(sprintf(
            'Expected a Livewire component ending in [%s]; found [%s].',
            $componentNameSuffix,
            implode(', ', array_unique($componentNames)),
        ));
    }
}
