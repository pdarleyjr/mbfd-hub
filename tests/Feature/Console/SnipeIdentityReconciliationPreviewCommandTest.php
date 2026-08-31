<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SnipeIdentityReconciliationPreviewCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/routes/console.php';
    }

    public function test_preview_reads_snipe_and_never_performs_a_write(): void
    {
        $this->seedIdentity();
        config()->set('services.snipeit.url', 'https://snipe.example.test/api/v1');
        config()->set('services.snipeit.token', 'synthetic-token');
        Http::fake([
            'https://snipe.example.test/api/v1/users*' => Http::response([
                'total' => 1,
                'rows' => [[
                    'id' => 42,
                    'employee_num' => '10010',
                    'username' => 'synthetic.user',
                    'email' => 'synthetic@example.test',
                    'name' => 'Synthetic Employee',
                ]],
            ]),
        ]);

        $status = Artisan::call('snipeit:reconcile-identities', ['--preview' => true, '--format' => 'json']);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('EXACT_EMPLOYEE_NUM', $output);
        $this->assertStringContainsString('PRESERVE_EXISTING_SNIPE_ID', $output);
        $this->assertStringNotContainsString('synthetic-token', $output);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
    }

    public function test_legacy_sync_is_fail_closed_and_makes_no_http_request(): void
    {
        Http::fake();

        $status = Artisan::call('snipeit:sync-users');

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('blocked', strtolower(Artisan::output()));
        Http::assertNothingSent();
    }

    private function seedIdentity(): void
    {
        now();
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 10,
            'employee_id' => '10010',
            'name' => 'Synthetic User',
            'email' => 'synthetic@example.test',
            'password' => '$2y$04$synthetictesthashnotused0000000000000000000000000000000000000',
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('employees')->insert([
            'id' => 20,
            'employee_id' => '10010',
            'name' => 'Synthetic Employee',
            'rank' => 'Firefighter',
            'password' => '$2y$04$synthetictesthashnotused0000000000000000000000000000000000000',
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
