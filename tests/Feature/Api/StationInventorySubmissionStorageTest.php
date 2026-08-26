<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Station;
use App\Models\StationInventorySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function test_failed_submission_record_creation_removes_its_new_private_pdf(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'address' => '123 Main St',
            'is_active' => true,
            'inventory_pin' => '1234',
        ]);

        StationInventorySubmission::creating(static function (): void {
            throw new RuntimeException('forced persistence failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/station-inventory-submissions', [
                'station_id' => $station->id,
                'employee_name' => 'Inventory Tester',
                'shift' => 'A',
                'items' => [[
                    'category_id' => 'garbage_paper',
                    'item_id' => 'paper_towels',
                    'quantity' => 1,
                ]],
            ]);

            $this->fail('The forced database failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced persistence failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('station_inventory_submissions', 0);
        Storage::disk($this->privateDisk())->assertDirectoryEmpty('inventory-submissions');
    }
}
