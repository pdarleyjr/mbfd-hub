<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Station;
use App\Models\StationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/public/stations/{station}/requests stays backward compatible (50 per
 * page by default) while letting a client read the complete history with
 * per_page up to 200 and the pagination meta.
 */
final class StationRequestHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        foreach (range(1, 120) as $index) {
            StationRequest::query()->create([
                'station_id' => $this->station->id,
                'requester_name_snapshot' => 'Fixture Member',
                'request_type' => 'repair_service',
                'title' => "Request {$index}",
                'description' => 'Fixture',
                'priority' => 'medium',
                'status' => 'completed',
            ]);
        }
        $this->actingAsCanonicalFixture();
    }

    public function test_default_page_size_is_unchanged_and_reports_that_more_history_exists(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 120)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_complete_history_is_available_with_the_larger_bounded_page_size(): void
    {
        $this->getJson($this->url('&per_page=200'))
            ->assertOk()
            ->assertJsonCount(120, 'data')
            ->assertJsonPath('meta.total', 120)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);

        $this->getJson($this->url('&per_page=201'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_walking_pages_returns_every_request_exactly_once(): void
    {
        $ids = [];
        $page = 1;
        do {
            $response = $this->getJson($this->url("&per_page=50&page={$page}"))->assertOk();
            $ids = [...$ids, ...array_column($response->json('data'), 'id')];
            $lastPage = (int) $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        self::assertCount(120, $ids);
        self::assertCount(120, array_unique($ids));
    }

    private function url(string $query = ''): string
    {
        return "/api/public/stations/{$this->station->id}/requests?scope=all{$query}";
    }
}
