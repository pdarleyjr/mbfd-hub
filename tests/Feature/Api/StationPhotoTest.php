<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Filament\Resources\StationResource\Pages\EditStation;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/**
 * Optional station photo: an additive nullable column, an Admin upload limited
 * to images, and a same-origin image_url on the member station endpoints.
 */
final class StationPhotoTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Station::IMAGE_DISK);
        $this->setUpStationAdmin();
    }

    public function test_migration_adds_a_nullable_image_path_column(): void
    {
        self::assertTrue(Schema::hasColumn('stations', 'image_path'));

        $station = $this->station(1);

        self::assertNull($station->fresh()->image_path);
        self::assertNull($station->fresh()->image_url);
    }

    public function test_image_url_is_a_root_relative_public_path(): void
    {
        $station = $this->station(1, ['image_path' => 'stations/photos/front.jpg']);

        self::assertSame('/storage/stations/photos/front.jpg', $station->image_url);
    }

    public function test_traversal_paths_never_produce_a_url(): void
    {
        $station = $this->station(1, ['image_path' => '../.env']);

        self::assertNull($station->image_url);
    }

    public function test_member_station_endpoints_expose_image_url(): void
    {
        $withPhoto = $this->station(1, ['image_path' => 'stations/photos/one.webp']);
        $withoutPhoto = $this->station(2);
        $this->actingAsCanonicalFixture();

        $stations = collect($this->getJson('/api/public/stations')->assertOk()->json('stations'))->keyBy('id');
        self::assertSame('/storage/stations/photos/one.webp', $stations[$withPhoto->id]['image_url']);
        self::assertArrayHasKey('image_url', $stations[$withoutPhoto->id]);
        self::assertNull($stations[$withoutPhoto->id]['image_url']);

        $this->getJson("/api/public/stations/{$withPhoto->id}")
            ->assertOk()
            ->assertJsonPath('image_url', '/storage/stations/photos/one.webp');
        $this->getJson("/api/public/stations/{$withoutPhoto->id}")
            ->assertOk()
            ->assertJsonPath('image_url', null);
    }

    public function test_replacing_the_photo_removes_the_previous_file(): void
    {
        Storage::disk(Station::IMAGE_DISK)->put('stations/photos/old.jpg', 'old');
        Storage::disk(Station::IMAGE_DISK)->put('stations/photos/new.jpg', 'new');
        $station = $this->station(1, ['image_path' => 'stations/photos/old.jpg']);

        $station->update(['image_path' => 'stations/photos/new.jpg']);

        Storage::disk(Station::IMAGE_DISK)->assertMissing('stations/photos/old.jpg');
        Storage::disk(Station::IMAGE_DISK)->assertExists('stations/photos/new.jpg');
    }

    public function test_admin_can_upload_a_station_photo(): void
    {
        $this->actingAsSuperAdmin();
        $station = $this->station(3);

        Livewire::test(EditStation::class, ['record' => $station->getRouteKey()])
            ->assertFormFieldExists('image_path')
            ->fillForm(['image_path' => [UploadedFile::fake()->image('front.jpg', 1200, 800)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $station->fresh()->image_path;
        self::assertIsString($path);
        self::assertStringStartsWith(Station::IMAGE_DIRECTORY.'/', $path);
        Storage::disk(Station::IMAGE_DISK)->assertExists($path);
    }

    public function test_non_image_uploads_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $station = $this->station(3);

        Livewire::test(EditStation::class, ['record' => $station->getRouteKey()])
            ->fillForm(['image_path' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])
            ->call('save')
            ->assertHasFormErrors(['image_path']);

        self::assertNull($station->fresh()->image_path);
    }

    public function test_view_only_admin_cannot_open_the_edit_page(): void
    {
        $station = $this->station(3);
        $this->actingAsAdminWith(['admin.stations.view']);

        $this->get(EditStation::getUrl(['record' => $station]))->assertForbidden();
    }
}
