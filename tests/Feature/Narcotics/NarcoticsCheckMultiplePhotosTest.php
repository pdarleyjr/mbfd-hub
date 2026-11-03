<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\Narcotics\NarcoticsCheckEvent;
use App\Models\Narcotics\NarcoticsCheckPhoto;
use App\Models\User;
use App\Services\Narcotics\NarcoticsCheckService;
use App\Services\Narcotics\NarcoticsImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Narcotics\Concerns\BuildsNarcoticsWorld;
use Tests\TestCase;

/** A member sends several photos with one narcotics check (docs/narcotics-check.md section 4). */
final class NarcoticsCheckMultiplePhotosTest extends TestCase
{
    use BuildsNarcoticsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNarcoticsWorld();
    }

    /**
     * @param  list<string>  $images  bytes of each photo, in order
     * @param  array<string, mixed>  $fields
     */
    private function sendPhotos(User $user, array $images, array $fields = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user);
        $payload = $fields + ['client_submission_id' => (string) Str::uuid(), 'rescue_unit' => 'R2', 'seal_number' => 'SEAL-7'];
        $payload['photos'] = array_map(fn (string $bytes): \Illuminate\Http\UploadedFile => $this->upload($bytes), $images);

        return $this->post('/api/narcotics-checks', $payload, ['Accept' => 'application/json']);
    }

    /** Three photos that differ, so each address can be shown to serve its own picture. */
    private function threePhotos(): array
    {
        return [$this->jpegBytes(1200, 900, 1, 0xE02020), $this->jpegBytes(900, 1200, 1, 0x20E020), $this->jpegBytes(1000, 800, 1, 0x2020E0)];
    }

    public function test_three_photos_are_one_check_with_photo_one_on_the_row_and_the_others_numbered_after_it(): void
    {
        $response = $this->sendPhotos($this->member, $this->threePhotos())
            ->assertStatus(201)
            ->assertJsonPath('data.photo_count', 3)
            ->assertJsonCount(3, 'data.photos')
            ->assertJsonPath('data.photos.0.position', 1)
            ->assertJsonPath('data.photos.1.position', 2)
            ->assertJsonPath('data.photos.2.position', 3)
            ->assertJsonPath('data.photos.1.width', 900)
            ->assertJsonPath('data.photos.1.height', 1200)
            ->assertJsonPath('data.image.width', 1200);

        $check = NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame(1, NarcoticsCheck::query()->count());
        $this->assertSame([2, 3], NarcoticsCheckPhoto::query()->where('narcotics_check_id', $check->id)->orderBy('position')->pluck('position')->all());
        $this->assertCount(9, $this->storedFiles(), 'three sizes of each of three photos');
        $this->assertSame("/api/narcotics-checks/{$check->uuid}/photos/2/preview", $response->json('data.photos.1.preview_url'));
        // Photo 1's own addresses are unchanged, so screens that only know `image` keep working.
        $this->assertSame("/api/narcotics-checks/{$check->uuid}/image/thumb", $response->json('data.image.thumb_url'));
        $this->assertStringNotContainsString('narcotics-checks/20', (string) $response->getContent(), 'no file path reaches the browser');

        $event = NarcoticsCheckEvent::query()->where('narcotics_check_id', $check->id)->sole();
        $this->assertSame(3, $event->payload['photo_count']);
        $this->assertCount(3, $event->payload['photos']);
        $disk = Storage::disk(NarcoticsImageStore::disk());
        $extra = NarcoticsCheckPhoto::query()->where('narcotics_check_id', $check->id)->where('position', 3)->sole();
        $this->assertSame(hash('sha256', $disk->get($extra->image_path)), $extra->image_sha256);
        $this->assertSame($extra->image_sha256, $event->payload['photos'][2]['sha256']);
    }

    public function test_each_photo_is_served_only_to_the_member_and_captain_5(): void
    {
        $check = NarcoticsCheck::query()->where('uuid', $this->sendPhotos($this->member, $this->threePhotos())->assertStatus(201)->json('data.id'))->firstOrFail();
        $disk = Storage::disk(NarcoticsImageStore::disk());
        $second = NarcoticsCheckPhoto::query()->where('narcotics_check_id', $check->id)->where('position', 2)->sole();

        $this->actingAs($this->member);
        $response = $this->get("/api/narcotics-checks/{$check->uuid}/photos/2/full")->assertOk();
        $this->assertSame($disk->get($second->image_path), $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($disk->get($check->image_path), $this->get("/api/narcotics-checks/{$check->uuid}/photos/1/full")->assertOk()->getContent());
        $this->get("/api/narcotics-checks/{$check->uuid}/photos/3/thumb")->assertOk();
        $this->get("/api/narcotics-checks/{$check->uuid}/photos/4/thumb")->assertNotFound();

        $this->actingAs($this->captain5);
        $this->get("/api/narcotics-checks/{$check->uuid}/photos/3/preview")->assertOk();

        foreach ([$this->otherMember, $this->officer] as $outsider) {
            $this->actingAs($outsider);
            $this->get("/api/narcotics-checks/{$check->uuid}/photos/2/full")->assertNotFound();
        }
    }

    public function test_a_bad_photo_anywhere_refuses_the_whole_check_by_number_and_leaves_nothing_behind(): void
    {
        $id = (string) Str::uuid();
        $images = $this->threePhotos();
        $images[1] = 'not a photo at all';

        $this->sendPhotos($this->member, $images, ['client_submission_id' => $id])
            ->assertStatus(422)
            ->assertJsonPath('error.message', fn (string $message): bool => str_starts_with($message, 'Photo 2: '));
        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame(0, NarcoticsCheckPhoto::query()->count());
        $this->assertSame([], $this->storedFiles(), 'photo 1 was already stored and must be removed again');

        // The same submission id goes through once the photo is replaced.
        $this->sendPhotos($this->member, $this->threePhotos(), ['client_submission_id' => $id])->assertStatus(201);
    }

    public function test_more_photos_than_allowed_are_refused_before_anything_is_stored(): void
    {
        config()->set('narcotics_checks.max_photos', 2);

        $this->sendPhotos($this->member, $this->threePhotos())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'photo_too_many')
            ->assertJsonPath('error.message', 'A narcotics check can have at most 2 photos. Remove some and send again.');
        $this->assertSame(0, NarcoticsCheck::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_form_says_how_many_photos_a_check_may_carry(): void
    {
        $this->actingAs($this->member);
        $this->getJson('/api/narcotics-checks/form')->assertOk()->assertJsonPath('data.max_photos', NarcoticsCheckService::maxPhotos());
        $this->assertSame(10, NarcoticsCheckService::maxPhotos());
    }

    public function test_a_single_photo_sent_the_old_way_still_works_and_lists_one_photo(): void
    {
        $check = $this->submitOk($this->member);
        $this->actingAs($this->member);

        $this->getJson("/api/narcotics-checks/mine/{$check->uuid}")
            ->assertOk()
            ->assertJsonPath('data.photo_count', 1)
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.thumb_url', "/api/narcotics-checks/{$check->uuid}/photos/1/thumb");
        $this->get("/api/narcotics-checks/{$check->uuid}/image/full")->assertOk();
        $this->assertSame(0, NarcoticsCheckPhoto::query()->count());
    }

    public function test_a_retry_of_the_same_photos_is_one_check_and_one_set_of_files(): void
    {
        $id = (string) Str::uuid();
        $first = $this->sendPhotos($this->member, $this->threePhotos(), ['client_submission_id' => $id])->assertStatus(201);
        $this->sendPhotos($this->member, $this->threePhotos(), ['client_submission_id' => $id])
            ->assertOk()
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.photo_count', 3);

        $this->assertCount(9, $this->storedFiles());
        $this->assertSame(2, NarcoticsCheckPhoto::query()->count());
    }

    public function test_captain_5_lists_and_opens_every_photo(): void
    {
        $check = NarcoticsCheck::query()->where('uuid', $this->sendPhotos($this->member, $this->threePhotos())->assertStatus(201)->json('data.id'))->firstOrFail();
        $this->actingAs($this->captain5);

        $this->getJson('/api/narcotics-checks/staff/checks')->assertOk()->assertJsonPath('data.0.photo_count', 3);
        $this->getJson("/api/narcotics-checks/staff/checks/{$check->uuid}")->assertOk()->assertJsonCount(3, 'data.photos');
    }

    public function test_retention_removes_every_photo_file_and_the_addresses_go_with_them(): void
    {
        $check = NarcoticsCheck::query()->where('uuid', $this->sendPhotos($this->member, $this->threePhotos())->assertStatus(201)->json('data.id'))->firstOrFail();
        $this->approve($this->captain5, $check)->assertOk();
        $this->travel(40)->days();

        $this->assertSame(1, app(NarcoticsCheckService::class)->purgeExpiredImages(30, true));

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(2, NarcoticsCheckPhoto::query()->count(), 'the photo records stay; only their files go');
        $this->actingAs($this->member);
        $this->getJson("/api/narcotics-checks/mine/{$check->uuid}")
            ->assertOk()
            ->assertJsonPath('data.photos.2.available', false)
            ->assertJsonPath('data.photos.2.full_url', null);
        $this->get("/api/narcotics-checks/{$check->uuid}/photos/2/full")->assertNotFound();
    }
}
