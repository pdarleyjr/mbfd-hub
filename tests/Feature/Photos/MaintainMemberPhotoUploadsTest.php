<?php

declare(strict_types=1);

namespace Tests\Feature\Photos;

use App\Console\Commands\MaintainMemberPhotoUploads;
use App\Jobs\Photos\ProcessInspectionPhotoUpload;
use App\Jobs\Photos\ProcessTakeHomeInspectionPhoto;
use App\Models\InspectionPhotoUpload;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\User;
use App\Services\Fleet\TakeHomeInspectionPhotoStore;
use App\Services\Inspections\InspectionPhotoUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * photos:maintain: stalled conversions are retried a bounded number of times and spent
 * originals are deleted, without ever touching a converted photo.
 */
final class MaintainMemberPhotoUploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $this->member = User::factory()->create();
    }

    public function test_a_conversion_stalled_for_days_is_requeued_and_counted(): void
    {
        $upload = $this->dailyUpload(InspectionPhotoUpload::STATUS_PROCESSING, now()->subDays(3));

        $this->artisan('photos:maintain')->assertSuccessful();

        Queue::assertPushed(ProcessInspectionPhotoUpload::class, fn (ProcessInspectionPhotoUpload $job): bool => $job->uuid === $upload->uuid);
        $fresh = $upload->fresh();
        $this->assertSame(1, $fresh?->conversion_attempts);
        $this->assertSame(InspectionPhotoUpload::STATUS_PROCESSING, $fresh?->status);
    }

    public function test_a_recent_conversion_is_left_alone(): void
    {
        $this->dailyUpload(InspectionPhotoUpload::STATUS_PROCESSING, now()->subMinutes(5));

        $this->artisan('photos:maintain')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_conversion_out_of_attempts_is_given_up_on_and_logged(): void
    {
        Log::spy();
        $upload = $this->dailyUpload(InspectionPhotoUpload::STATUS_PROCESSING, now()->subHour(), MaintainMemberPhotoUploads::MAX_CONVERSION_ATTEMPTS);

        $this->artisan('photos:maintain')->assertSuccessful();

        Queue::assertNothingPushed();
        $fresh = $upload->fresh();
        $this->assertSame(InspectionPhotoUpload::STATUS_FAILED, $fresh?->status);
        $this->assertNotNull($fresh?->failure);
        // The original is kept for the retention window (a member retry or a manual look).
        Storage::disk('local')->assertExists((string) $upload->source_path);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'given up') && $context['uuid'] === $upload->uuid)->once();
    }

    public function test_the_original_of_a_failed_conversion_is_deleted_after_the_retention_window(): void
    {
        $old = $this->dailyUpload(InspectionPhotoUpload::STATUS_FAILED, now()->subDays(MaintainMemberPhotoUploads::FAILED_ORIGINAL_RETENTION_DAYS + 1));
        $recent = $this->dailyUpload(InspectionPhotoUpload::STATUS_FAILED, now()->subDays(2));

        $this->artisan('photos:maintain')->assertSuccessful();

        Storage::disk('local')->assertMissing((string) $old->source_path);
        $this->assertNull($old->fresh()?->source_path);
        Storage::disk('local')->assertExists((string) $recent->source_path);
        $this->assertNotNull($recent->fresh()?->source_path);
    }

    public function test_a_converted_rows_leftover_original_goes_and_its_photo_stays(): void
    {
        $upload = $this->dailyUpload(InspectionPhotoUpload::STATUS_STORED, now()->subMinutes(30));
        Storage::disk('local')->put($upload->path, 'converted jpeg');

        $this->artisan('photos:maintain')->assertSuccessful();

        Storage::disk('local')->assertMissing((string) $upload->source_path);
        Storage::disk('local')->assertExists($upload->path);
        $this->assertNull($upload->fresh()?->source_path);
        $this->assertSame(InspectionPhotoUpload::STATUS_STORED, $upload->fresh()?->status);
    }

    public function test_an_original_no_row_holds_is_swept_after_a_day(): void
    {
        $orphan = InspectionPhotoUploads::INCOMING_DIRECTORY.'/'.Str::uuid().'.heic';
        $fresh = InspectionPhotoUploads::INCOMING_DIRECTORY.'/'.Str::uuid().'.heic';
        $takeHomeOrphan = TakeHomeInspectionPhotoStore::INCOMING_DIRECTORY.'/'.Str::uuid().'.png';
        foreach ([$orphan, $fresh, $takeHomeOrphan] as $file) {
            Storage::disk('local')->put($file, 'original');
        }
        $this->age($orphan, now()->subDays(2)->getTimestamp());
        $this->age($takeHomeOrphan, now()->subDays(2)->getTimestamp());
        $held = $this->dailyUpload(InspectionPhotoUpload::STATUS_PROCESSING, now()->subMinutes(1));
        $this->age((string) $held->source_path, now()->subDays(2)->getTimestamp());

        $this->artisan('photos:maintain')->assertSuccessful();

        Storage::disk('local')->assertMissing($orphan);
        Storage::disk('local')->assertMissing($takeHomeOrphan);
        Storage::disk('local')->assertExists($fresh);
        Storage::disk('local')->assertExists((string) $held->source_path);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $upload = $this->dailyUpload(InspectionPhotoUpload::STATUS_FAILED, now()->subDays(30));

        $this->artisan('photos:maintain', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('local')->assertExists((string) $upload->source_path);
        $this->assertNotNull($upload->fresh()?->source_path);
    }

    public function test_a_take_home_conversion_is_retried_then_given_up_once_then_its_original_deleted(): void
    {
        Log::spy();
        $photo = $this->takeHomePhoto(now()->subDays(2), MaintainMemberPhotoUploads::MAX_CONVERSION_ATTEMPTS - 1);

        $this->artisan('photos:maintain')->assertSuccessful();
        Queue::assertPushed(ProcessTakeHomeInspectionPhoto::class, 1);
        $this->assertSame(MaintainMemberPhotoUploads::MAX_CONVERSION_ATTEMPTS, $photo->fresh()?->conversion_attempts);

        $this->travel(20)->minutes();
        $this->artisan('photos:maintain')->assertSuccessful();
        $this->travel(20)->minutes();
        $this->artisan('photos:maintain')->assertSuccessful();

        Queue::assertPushed(ProcessTakeHomeInspectionPhoto::class, 1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'take-home') && $context['uuid'] === $photo->uuid)->once();
        Storage::disk('local')->assertExists((string) $photo->source_path);

        $this->travel(MaintainMemberPhotoUploads::FAILED_ORIGINAL_RETENTION_DAYS + 1)->days();
        $this->artisan('photos:maintain')->assertSuccessful();

        Storage::disk('local')->assertMissing((string) $photo->source_path);
        $this->assertNull($photo->fresh()?->source_path);
    }

    private function dailyUpload(string $status, \DateTimeInterface $updatedAt, int $attempts = 0): InspectionPhotoUpload
    {
        $uuid = (string) Str::uuid();
        $source = InspectionPhotoUploads::INCOMING_DIRECTORY.'/'.$uuid.'.png';
        Storage::disk('local')->put($source, 'original');
        $upload = InspectionPhotoUpload::query()->create([
            'uuid' => $uuid,
            'user_id' => $this->member->id,
            'status' => $status,
            'disk' => InspectionPhotoUploads::disk(),
            'path' => InspectionPhotoUploads::pathFor($uuid),
            'source_path' => $source,
            'bytes' => 8,
            'conversion_attempts' => $attempts,
        ]);
        InspectionPhotoUpload::query()->whereKey($upload->id)->update(['updated_at' => $updatedAt]);

        return $upload;
    }

    private function takeHomePhoto(\DateTimeInterface $updatedAt, int $attempts): TakeHomeVehicleInspectionPhoto
    {
        $uuid = (string) Str::uuid();
        $source = TakeHomeInspectionPhotoStore::INCOMING_DIRECTORY.'/'.$uuid.'.heic';
        Storage::disk('local')->put($source, 'original');
        $photo = TakeHomeVehicleInspectionPhoto::query()->create([
            'uuid' => $uuid,
            'uploaded_by_user_id' => $this->member->id,
            'angle' => 'front',
            'path' => 'take-home-inspections/photos/2026/11/'.$uuid.'.jpg',
            'source_path' => $source,
            'width' => 0,
            'height' => 0,
            'bytes' => 8,
            'sha256' => str_repeat('a', 64),
            'conversion_attempts' => $attempts,
        ]);
        TakeHomeVehicleInspectionPhoto::query()->whereKey($photo->id)->update(['updated_at' => $updatedAt]);

        return $photo;
    }

    private function age(string $file, int $timestamp): void
    {
        touch(Storage::disk('local')->path($file), $timestamp);
        clearstatcache();
    }
}
