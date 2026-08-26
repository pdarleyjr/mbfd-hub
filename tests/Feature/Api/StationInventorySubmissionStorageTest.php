<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Station;
use App\Models\StationInventorySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StationInventorySubmissionStorageTest extends TestCase
{
    use RefreshDatabase;

    private function privateDisk(): string
    {
        return config('filesystems.private', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->privateDisk());
    }

    public function test_two_same_station_submissions_receive_distinct_private_pdf_paths(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'address' => '123 Main St',
            'is_active' => true,
            'inventory_pin' => '1234',
        ]);

        $payload = [
            'station_id' => $station->id,
            'employee_name' => 'Inventory Tester',
            'shift' => 'A',
            'items' => [[
                'category_id' => 'garbage_paper',
                'item_id' => 'paper_towels',
                'quantity' => 1,
            ]],
        ];

        $this->postJson('/api/station-inventory-submissions', $payload)->assertCreated();
        $this->postJson('/api/station-inventory-submissions', $payload)->assertCreated();

        $paths = StationInventorySubmission::query()
            ->orderBy('id')
            ->pluck('pdf_path')
            ->all();

        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);

        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(
                '/^inventory-submissions\\/inventory-'.$station->id.'-[0-9A-HJKMNP-TV-Z]{26}\\.pdf$/',
                $path,
            );
            Storage::disk($this->privateDisk())->assertExists($path);
        }
    }
}
