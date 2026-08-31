<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\IdentityReconciliation\IdentityFixtures;
use Tests\TestCase;

final class IdentityReconciliationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_deterministic_and_preserves_authorization_metadata(): void
    {
        $this->seedIdentityFixture();
        $tables = ['users', 'employees', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'workgroups', 'workgroup_members'];
        $before = $this->tableCounts($tables);
        $sql = [];
        DB::listen(static function (QueryExecuted $query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $firstStatus = Artisan::call('identity:reconcile-preview', ['--format' => 'json']);
        $first = Artisan::output();
        $secondStatus = Artisan::call('identity:reconcile-preview', ['--format' => 'json']);
        $second = Artisan::output();

        $this->assertSame(Command::SUCCESS, $firstStatus);
        $this->assertSame(Command::SUCCESS, $secondStatus);
        $this->assertSame($first, $second);
        $this->assertSame($before, $this->tableCounts($tables));
        $this->assertNotEmpty($sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $statement);
        }

        /** @var array<string, mixed> $report */
        $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['controls']['production_mutations_possible']);
        $this->assertFalse($report['controls']['name_auto_match']);
        $this->assertSame(1, $report['summary']['total_users']);
        $this->assertSame(1, $report['summary']['total_employees']);
        $this->assertSame(['super_admin', 'training_admin'], $report['rows'][0]['preservation']['roles']);
        $this->assertSame('admin', $report['rows'][0]['preservation']['workgroups'][0]['role']);
        $this->assertTrue($report['rows'][0]['preservation']['training_access']);
        $this->assertTrue($report['rows'][0]['preservation']['super_admin']);
        $this->assertStringNotContainsString(IdentityFixtures::BCRYPT_ONE, $first);
        $this->assertStringNotContainsString('synthetic-remember-token', $first);
    }

    public function test_command_supports_csv_and_strict_owner_ledger_input_without_external_calls(): void
    {
        $this->seedIdentityFixture(userEmployeeId: null);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'identity-command-preview-'.bin2hex(random_bytes(8)).'.csv';
        $ledger = dirname(__DIR__, 2).'/Fixtures/IdentityReconciliation/owner-ledger.valid.json';

        try {
            $status = Artisan::call('identity:reconcile-preview', [
                '--format' => 'csv',
                '--output' => $path,
                '--approved-ledger' => $ledger,
            ]);

            $this->assertSame(Command::SUCCESS, $status);
            $this->assertFileExists($path);
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents);
            $this->assertStringContainsString('OWNER_APPROVED_LEDGER', $contents);
            $this->assertStringContainsString('EXACT_EMPLOYEE_ID_MATCH', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function test_default_console_output_is_a_human_readable_table(): void
    {
        $this->seedIdentityFixture();

        $this->artisan('identity:reconcile-preview')
            ->expectsOutputToContain('Identity reconciliation preview: READY_FOR_OWNER_REVIEW')
            ->expectsOutputToContain('EXACT_EMPLOYEE_ID_MATCH')
            ->expectsOutputToContain('Snapshot token:')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_malformed_ledger_fails_before_any_database_query_or_output_write(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'identity-command-invalid-'.bin2hex(random_bytes(8)).'.json';
        $ledger = dirname(__DIR__, 2).'/Fixtures/IdentityReconciliation/owner-ledger.malformed.json';

        $status = Artisan::call('identity:reconcile-preview', [
            '--format' => 'json',
            '--output' => $path,
            '--approved-ledger' => $ledger,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid owner ledger', Artisan::output());
        $this->assertSame([], $queries);
        $this->assertFileDoesNotExist($path);
    }

    private function seedIdentityFixture(?string $userEmployeeId = '10010'): void
    {
        DB::table('users')->insert([
            'id' => 10,
            'employee_id' => $userEmployeeId,
            'name' => 'Synthetic User',
            'email' => 'synthetic.user@example.test',
            'password' => IdentityFixtures::BCRYPT_ONE,
            'must_change_password' => false,
            'remember_token' => 'synthetic-remember-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'id' => 20,
            'employee_id' => '10010',
            'name' => 'Synthetic Employee',
            'rank' => 'Firefighter',
            'password' => IdentityFixtures::BCRYPT_ONE,
            'must_change_password' => false,
            'remember_token' => 'synthetic-employee-remember-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'super_admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'training_admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('permissions')->insert([
            'id' => 1,
            'name' => 'training.access',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 10],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 10],
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => 1,
            'model_type' => User::class,
            'model_id' => 10,
        ]);
        DB::table('workgroups')->insert([
            'id' => 7,
            'name' => 'Synthetic Workgroup',
            'description' => null,
            'is_active' => true,
            'created_by' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workgroup_members')->insert([
            'workgroup_id' => 7,
            'user_id' => 10,
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $tables
     * @return array<string, int>
     */
    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
