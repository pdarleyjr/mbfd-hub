<?php

declare(strict_types=1);

namespace Tests\Feature\CommandBoard;

use App\Models\Employee;
use App\Services\Roster\EmployeeRankResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommandPersonnelSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cmd-service-token-for-tests-only';

    private const PATH = '/api/internal/command/personnel';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.command_board.service_token', self::TOKEN);
    }

    private function employee(string $id, string $name, string $rank = 'Firefighter', ?string $status = 'active'): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'name' => $name,
            'rank' => $rank,
            'password' => 'unused-test-password-value',
            'roster_status' => $status,
            'station' => 'Station 9',
            'phone' => '305-555-0100',
            'city_email' => "{$id}@example.test",
        ]);
    }

    /** @return array<string, string> */
    private function auth(string $token = self::TOKEN): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_it_requires_the_service_token(): void
    {
        $this->employee('70001', 'Zebra, Tester');

        $this->getJson(self::PATH.'?q=zebra')->assertStatus(401)->assertJson(['error' => 'invalid_service_credential']);
        $this->getJson(self::PATH.'?q=zebra', $this->auth('wrong-token'))->assertStatus(401);
        $this->getJson(self::PATH.'?q=zebra', ['Authorization' => 'Basic '.base64_encode('a:b')])->assertStatus(401);
        $this->getJson(self::PATH.'?q=zebra', $this->auth(substr(self::TOKEN, 0, -1)))->assertStatus(401);
        $this->getJson(self::PATH.'?q=zebra', $this->auth(self::TOKEN.'x'))->assertStatus(401);
    }

    public function test_it_fails_closed_when_the_link_is_not_configured(): void
    {
        foreach ([null, '', '   '] as $unset) {
            config()->set('services.command_board.service_token', $unset);

            $this->getJson(self::PATH.'?q=zebra', $this->auth(''))->assertStatus(503);
            $this->getJson(self::PATH.'?q=zebra', $this->auth('anything'))->assertStatus(503);
        }
    }

    public function test_it_returns_only_the_four_picker_fields(): void
    {
        $this->employee('70001', 'Zebra, Tester', 'Lieutenant');

        $response = $this->getJson(self::PATH.'?q=zebra', $this->auth());

        $response->assertOk()->assertExactJson(['results' => [
            ['employeeId' => '70001', 'name' => 'Zebra, Tester', 'rank' => 'Lieutenant', 'active' => true],
        ]]);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('555-0100', $response->getContent());
        self::assertStringNotContainsString('example.test', $response->getContent());
    }

    public function test_it_finds_by_employee_id_and_flags_inactive_members(): void
    {
        $this->employee('70002', 'Yankee, Tester', 'Captain', 'departed');
        $this->employee('70003', 'Xray, Tester', 'Captain', 'on_leave');

        $byId = $this->getJson(self::PATH.'?q=70002', $this->auth())->assertOk()->json('results');
        self::assertSame([['employeeId' => '70002', 'name' => 'Yankee, Tester', 'rank' => 'Captain', 'active' => false]], $byId);

        $leave = $this->getJson(self::PATH.'?q=xray', $this->auth())->json('results');
        self::assertFalse($leave[0]['active']);
    }

    public function test_it_limits_results_to_twenty(): void
    {
        foreach (range(1, 25) as $n) {
            $this->employee((string) (71000 + $n), sprintf('Walker%02d, Tester', $n));
        }

        $results = $this->getJson(self::PATH.'?q=walker', $this->auth())->assertOk()->json('results');

        self::assertCount(20, $results);
        self::assertSame('Walker01, Tester', $results[0]['name']);
    }

    public function test_short_missing_and_malformed_queries_return_no_results(): void
    {
        $this->employee('70001', 'Zebra, Tester');

        foreach (['', '?q=', '?q=z', '?q[]=zebra', '?q=%20%20'] as $query) {
            $this->getJson(self::PATH.$query, $this->auth())->assertOk()->assertExactJson(['results' => []]);
        }
    }

    public function test_it_treats_like_wildcards_literally(): void
    {
        $this->employee('70001', 'Zebra, Tester');

        $this->getJson(self::PATH.'?q=%25%25', $this->auth())->assertOk()->assertExactJson(['results' => []]);
    }

    public function test_it_is_read_only(): void
    {
        $this->postJson(self::PATH, ['q' => 'zebra'], $this->auth())->assertStatus(405);
        $this->deleteJson(self::PATH, [], $this->auth())->assertStatus(405);
    }

    public function test_it_is_throttled_including_failed_attempts(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::PATH.'?q=zebra', $this->auth('wrong-token'))->assertStatus(401);
        }

        $this->getJson(self::PATH.'?q=zebra', $this->auth('wrong-token'))->assertStatus(429);
        $this->getJson(self::PATH.'?q=zebra', $this->auth())->assertStatus(429);
    }

    public function test_the_rank_resolver_matches_the_exact_employee_id_only(): void
    {
        $this->employee('70001', 'Zebra, Tester', 'Lieutenant');
        $this->employee('700011', 'Zebra Two, Tester', 'Captain');
        $resolver = new EmployeeRankResolver;

        self::assertSame('Lieutenant', $resolver('70001'));
        self::assertSame('Captain', $resolver('700011'));
        self::assertNull($resolver('7000'));
        self::assertNull($resolver('99999'));
    }
}
