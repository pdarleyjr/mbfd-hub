<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\SnipeItService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Equipment Intake reads Snipe-IT locations on every Livewire render; the
 * list must come from a short cache instead of a blocking API call each time.
 */
final class SnipeItLocationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['snipeit.url' => 'https://snipe.example.test/api/v1', 'snipeit.token' => 'test-token', 'cache.default' => 'array']);
    }

    private function fakeSnipeIt(): void
    {
        Http::fake([
            'snipe.example.test/api/v1/locations?*' => Http::response(['rows' => [['id' => 7, 'name' => 'Station 7']]]),
            'snipe.example.test/api/v1/locations' => Http::response(['status' => 'success', 'payload' => ['id' => 8, 'name' => 'New']]),
        ]);
    }

    public function test_locations_are_fetched_once_and_reused(): void
    {
        $this->fakeSnipeIt();
        $service = app(SnipeItService::class);

        $this->assertSame([7 => 'Station 7'], $service->getLocations());
        $this->assertSame([7 => 'Station 7'], app(SnipeItService::class)->getLocations());

        Http::assertSentCount(1);
    }

    public function test_creating_a_location_refreshes_the_cached_list(): void
    {
        $this->fakeSnipeIt();
        $service = app(SnipeItService::class);
        $service->getLocations();
        $service->createLocation('New');
        $service->getLocations();

        $locationReads = collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0] instanceof Request && $pair[0]->method() === 'GET');
        $this->assertCount(2, $locationReads);
    }

    public function test_a_failed_read_is_not_cached(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['rows' => [['id' => 9, 'name' => 'Station 9']]])]);
        $service = app(SnipeItService::class);

        $this->assertArrayNotHasKey(9, $service->getLocations());
        $this->assertSame([9 => 'Station 9'], $service->getLocations());
    }
}
