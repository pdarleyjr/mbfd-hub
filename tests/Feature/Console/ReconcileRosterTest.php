<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReconcileRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.employee_bootstrap.secret' => 'test-owner-approved-bootstrap']);
    }

    public function test_apply_creates_only_missing_ids_and_preserves_existing_profile_fields(): void
    {
        $current = Employee::query()->create([
            'employee_id' => '12345',
            'name' => 'Existing Display Name',
            'rank' => 'Existing Rank',
            'roster_status' => 'departed',
            'password' => 'existing-current-password',
        ]);
        $historical = Employee::query()->create([
            'employee_id' => '99999',
            'name' => 'Historical Person',
            'roster_status' => 'active',
            'password' => 'existing-historical-password',
        ]);
        $path = $this->rosterFile();

        try {
            $this->artisan('mbfd:roster-reconcile', ['file' => $path])
                ->assertSuccessful()
                ->expectsOutputToContain('DRY RUN ONLY');
            $this->assertSame('departed', $current->refresh()->roster_status);

            $this->artisan('mbfd:roster-reconcile', ['file' => $path, '--apply' => true])
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $current->refresh();
        $this->assertSame('Existing Display Name', $current->name);
        $this->assertSame('Existing Rank', $current->rank);
        $this->assertSame('active', $current->roster_status);
        $this->assertSame('departed', $historical->refresh()->roster_status);
        $this->assertDatabaseHas('employees', [
            'employee_id' => '54321',
            'name' => 'New Person',
            'rank' => 'Firefighter',
            'roster_status' => 'active',
        ]);
        $this->assertSame(3, Employee::query()->count());
    }

    private function rosterFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-roster-');
        file_put_contents($path, <<<'HTML'
        <table><tr><td><table>
        <tr><th>Name</th><th>Emp ID</th><th>Unit</th><th>Position</th></tr>
        <tr><td>PERSON, CURRENT</td><td>12345</td><td>Engine 1</td><td>Lieutenant</td></tr>
        <tr><td>PERSON, NEW</td><td>54321</td><td>Engine 2</td><td>Firefighter</td></tr>
        </table></td></tr></table>
        HTML);

        return $path;
    }
}
