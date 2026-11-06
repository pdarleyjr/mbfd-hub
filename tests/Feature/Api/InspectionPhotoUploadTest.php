<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Jobs\Photos\ProcessInspectionPhotoUpload;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectObservation;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionConcern;
use App\Models\InspectionPhotoUpload;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inspections\InspectionPhotoUploads;
use App\Services\Photos\PhotoNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Daily Checkout photos sent one request at a time and named by id in the checkout.
 */
final class InspectionPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMISSION = '32323232-3232-4232-8232-323232323232';

    private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const URL = '/api/public/inspection-photos';

    private User $member;

    private Apparatus $boat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->member = $this->actingAsCanonicalFixture('E01-PHOTOS', 'Photo Reporter');
        $this->boat = $this->makeFireBoat6();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->member);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_clean_phone_jpeg_is_stored_once_at_its_fixed_path_without_queued_work(): void
    {
        Queue::fake();
        $id = (string) Str::uuid();
        $file = UploadedFile::fake()->image('photo.jpg', 2048, 1536);
        $sent = (string) file_get_contents($file->getRealPath());

        $this->upload($id, $file)->assertCreated()->assertJsonPath('photo_id', $id)->assertJsonPath('status', 'stored');

        $row = InspectionPhotoUpload::query()->sole();
        $this->assertSame(InspectionPhotoUploads::pathFor($id), $row->path);
        $this->assertStringStartsWith('defects/uploads/', $row->path);
        $this->assertSame($sent, Storage::disk('public')->get($row->path));
        $this->assertSame(hash('sha256', $sent), $row->sha256);
        $this->assertNull($row->apparatus_inspection_id);
        Queue::assertNothingPushed();
    }

    public function test_a_retry_with_the_same_photo_id_never_stores_a_second_copy(): void
    {
        $id = (string) Str::uuid();

        $this->upload($id)->assertCreated();
        $this->upload($id)->assertOk()->assertJsonPath('photo_id', $id);

        $this->assertDatabaseCount('inspection_photo_uploads', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('defects'));
    }

    public function test_another_member_cannot_reuse_a_photo_id(): void
    {
        $id = (string) Str::uuid();
        $this->upload($id)->assertCreated();

        $other = $this->actingAsCanonicalFixture('E02-PHOTOS', 'Other Member');
        $this->actingAsCanonicalUser($other);
        $this->upload($id)->assertStatus(409);

        $this->assertSame($this->member->id, InspectionPhotoUpload::query()->sole()->user_id);
    }

    public function test_a_file_over_the_limit_or_not_a_photo_is_refused(): void
    {
        $this->upload((string) Str::uuid(), UploadedFile::fake()->create('huge.jpg', InspectionPhotoUploads::MAX_UPLOAD_KB + 1, 'image/jpeg'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->upload((string) Str::uuid(), UploadedFile::fake()->createWithContent('note.jpg', 'not a photo at all'))
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->upload('not-a-uuid')->assertUnprocessable()->assertJsonValidationErrors('client_photo_id');

        $this->assertDatabaseCount('inspection_photo_uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_iphone_heic_original_is_accepted_and_converted_off_the_request(): void
    {
        Queue::fake();
        $id = (string) Str::uuid();
        $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64);

        $this->upload($id, UploadedFile::fake()->createWithContent('IMG_0001.HEIC', $heic))
            ->assertCreated()->assertJsonPath('status', 'processing');

        $row = InspectionPhotoUpload::query()->sole();
        $this->assertStringEndsWith('.heic', (string) $row->source_path);
        Storage::disk('local')->assertExists((string) $row->source_path);
        Storage::disk('public')->assertMissing($row->path);
        Queue::assertPushed(ProcessInspectionPhotoUpload::class, fn (ProcessInspectionPhotoUpload $job): bool => $job->uuid === $id);
    }

    public function test_the_queued_job_writes_a_bounded_jpeg_and_removes_the_original(): void
    {
        Queue::fake();
        $id = (string) Str::uuid();
        $this->upload($id, UploadedFile::fake()->image('scan.png', 3000, 2000))->assertCreated()->assertJsonPath('status', 'processing');

        (new ProcessInspectionPhotoUpload($id))->handle(app(PhotoNormalizer::class));

        $row = InspectionPhotoUpload::query()->sole();
        $this->assertSame(InspectionPhotoUpload::STATUS_STORED, $row->status);
        $this->assertNull($row->source_path);
        $this->assertSame([2048, 1365], [$row->width, $row->height]);
        $bytes = (string) Storage::disk('public')->get($row->path);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $bytes);
        $this->assertSame(hash('sha256', $bytes), $row->sha256);
        $this->assertSame([], Storage::disk('local')->allFiles('inspection-photo-uploads'));
    }

    public function test_a_checkout_names_photos_by_id_and_a_finding_shares_its_items_photo(): void
    {
        $itemPhoto = (string) Str::uuid();
        $concernPhoto = (string) Str::uuid();
        $this->upload($itemPhoto)->assertCreated();
        $this->upload($concernPhoto, UploadedFile::fake()->image('b.jpg', 640, 480))->assertCreated();

        $payload = $this->payload();
        $this->damage($payload, $itemPhoto, withDefectPhoto: false);
        $payload['concerns'] = [$this->concern('note', 'Photo of the console', $concernPhoto)];

        $this->submit($payload)->assertCreated();

        $path = InspectionPhotoUploads::pathFor($itemPhoto);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame($path, $inspection->results[0]['items'][0]['photo_path']);
        $this->assertArrayNotHasKey('photo_id', $inspection->results[0]['items'][0]);
        $this->assertSame($path, ApparatusDefect::query()->sole()->photo_path);
        $this->assertSame($path, ApparatusDefectObservation::query()->sole()->photo_path);
        $this->assertSame(InspectionPhotoUploads::pathFor($concernPhoto), ApparatusInspectionConcern::query()->sole()->photo_path);
        $this->assertSame(2, InspectionPhotoUpload::query()->where('apparatus_inspection_id', $inspection->id)->whereNotNull('attached_at')->count());
        $this->assertCount(2, Storage::disk('public')->allFiles('defects'));
        $this->get('/api/public/inspection-concerns/'.ApparatusInspectionConcern::query()->sole()->id.'/photo')->assertOk();
    }

    public function test_a_checkout_that_arrives_before_its_photo_shows_the_photo_once_it_is_delivered(): void
    {
        $photoId = (string) Str::uuid();
        $payload = $this->payload();
        $this->damage($payload, $photoId, withDefectPhoto: true);

        $this->submit($payload)->assertCreated();

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame(InspectionPhotoUploads::pathFor($photoId), $defect->photo_path);
        $this->assertNull($defect->availablePhotoPath());
        $placeholder = InspectionPhotoUpload::query()->sole();
        $this->assertSame(InspectionPhotoUpload::STATUS_AWAITING_UPLOAD, $placeholder->status);
        $this->assertSame(ApparatusInspection::query()->sole()->id, $placeholder->apparatus_inspection_id);

        $this->upload($photoId)->assertCreated()->assertJsonPath('status', 'stored');

        $this->assertSame($defect->photo_path, $defect->fresh()->availablePhotoPath());
        $this->assertDatabaseCount('inspection_photo_uploads', 1);
        $this->get("/api/public/apparatus-issues/{$defect->id}/photo")->assertOk();
    }

    public function test_a_checkout_that_lands_between_the_uploads_read_and_write_still_gets_the_converted_photo(): void
    {
        Queue::fake();
        $photoId = (string) Str::uuid();
        $payload = $this->payload();
        $this->damage($payload, $photoId, withDefectPhoto: true);
        $this->submit($payload)->assertCreated();
        $race = $this->checkoutWinsTheRaceFor($photoId);

        $this->upload($photoId, UploadedFile::fake()->image('scan.png', 900, 600))
            ->assertCreated()->assertJsonPath('status', InspectionPhotoUpload::STATUS_PROCESSING);

        $this->assertTrue($race->raced);
        $row = InspectionPhotoUpload::query()->sole();
        $this->assertSame(InspectionPhotoUpload::STATUS_PROCESSING, $row->status);
        $this->assertSame(ApparatusInspection::query()->sole()->id, $row->apparatus_inspection_id);
        Storage::disk('local')->assertExists((string) $row->source_path);
        Queue::assertPushed(ProcessInspectionPhotoUpload::class, fn (ProcessInspectionPhotoUpload $job): bool => $job->uuid === $photoId);

        (new ProcessInspectionPhotoUpload($photoId))->handle(app(PhotoNormalizer::class));

        $defect = ApparatusDefect::query()->sole();
        $this->assertSame(InspectionPhotoUpload::STATUS_STORED, $row->fresh()?->status);
        $this->assertSame($defect->photo_path, $defect->availablePhotoPath());
        $this->get("/api/public/apparatus-issues/{$defect->id}/photo")->assertOk();
    }

    public function test_a_clean_jpeg_racing_a_checkout_fills_the_placeholder(): void
    {
        $photoId = (string) Str::uuid();
        $payload = $this->payload();
        $this->damage($payload, $photoId, withDefectPhoto: true);
        $this->submit($payload)->assertCreated();
        $race = $this->checkoutWinsTheRaceFor($photoId);

        $this->upload($photoId)->assertCreated()->assertJsonPath('status', InspectionPhotoUpload::STATUS_STORED);

        $this->assertTrue($race->raced);
        $row = InspectionPhotoUpload::query()->sole();
        $this->assertSame(InspectionPhotoUpload::STATUS_STORED, $row->status);
        $this->assertNotNull($row->sha256);
        $this->assertSame(ApparatusInspection::query()->sole()->id, $row->apparatus_inspection_id);
        $this->assertSame(ApparatusDefect::query()->sole()->photo_path, ApparatusDefect::query()->sole()->availablePhotoPath());
    }

    public function test_a_retry_of_a_photo_that_failed_conversion_is_accepted_again(): void
    {
        Queue::fake();
        $photoId = (string) Str::uuid();
        $this->upload($photoId, UploadedFile::fake()->image('scan.png', 400, 300))->assertCreated();
        InspectionPhotoUpload::query()->where('uuid', $photoId)->update(['status' => InspectionPhotoUpload::STATUS_FAILED, 'source_path' => 'inspection-photo-uploads/incoming/'.$photoId.'.webp']);
        Storage::disk('local')->put('inspection-photo-uploads/incoming/'.$photoId.'.webp', 'stale');

        $this->upload($photoId)->assertCreated()->assertJsonPath('status', InspectionPhotoUpload::STATUS_STORED);

        Storage::disk('local')->assertMissing('inspection-photo-uploads/incoming/'.$photoId.'.webp');
        $this->assertDatabaseCount('inspection_photo_uploads', 1);
    }

    public function test_the_same_photo_sent_twice_for_one_checkout_is_kept_once(): void
    {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $file = UploadedFile::fake()->image('same.jpg', 800, 600);
        $bytes = (string) file_get_contents($file->getRealPath());
        $this->upload($first, UploadedFile::fake()->createWithContent('a.jpg', $bytes))->assertCreated();
        $this->upload($second, UploadedFile::fake()->createWithContent('b.jpg', $bytes))->assertCreated();

        $payload = $this->payload();
        $payload['compartments'][0]['items'][0]['photo_id'] = $first;
        $payload['compartments'][0]['items'][1]['photo_id'] = $second;
        $this->submit($payload)->assertCreated();

        $items = ApparatusInspection::query()->sole()->results[0]['items'];
        $this->assertSame(InspectionPhotoUploads::pathFor($first), $items[0]['photo_path']);
        $this->assertSame(InspectionPhotoUploads::pathFor($first), $items[1]['photo_path']);
        $this->assertNull(InspectionPhotoUpload::query()->where('uuid', $second)->value('apparatus_inspection_id'));

        // The unused copy is removed once it has been unclaimed long enough.
        InspectionPhotoUpload::query()->where('uuid', $second)->update(['created_at' => now()->subDays(InspectionPhotoUploads::UNATTACHED_RETENTION_DAYS + 1)]);
        $this->artisan('photos:maintain')->assertSuccessful();
        $this->assertDatabaseMissing('inspection_photo_uploads', ['uuid' => $second]);
        Storage::disk('public')->assertMissing(InspectionPhotoUploads::pathFor($second));
        Storage::disk('public')->assertExists(InspectionPhotoUploads::pathFor($first));
    }

    public function test_another_members_photo_id_costs_the_photo_never_the_checkout(): void
    {
        $theirs = (string) Str::uuid();
        $other = $this->actingAsCanonicalFixture('E03-PHOTOS', 'Someone Else');
        $this->actingAsCanonicalUser($other);
        $this->upload($theirs)->assertCreated();
        $this->actingAsCanonicalUser($this->member);

        $payload = $this->payload();
        $payload['concerns'] = [$this->concern('issue', 'Cracked housing', $theirs)];
        $this->submit($payload)->assertCreated();

        $this->assertNull(ApparatusInspectionConcern::query()->sole()->photo_path);
        $this->assertNull(InspectionPhotoUpload::query()->sole()->apparatus_inspection_id);
    }

    public function test_a_retried_checkout_with_photo_ids_is_idempotent(): void
    {
        $photoId = (string) Str::uuid();
        $this->upload($photoId)->assertCreated();
        $payload = $this->payload();
        $this->damage($payload, $photoId, withDefectPhoto: true);

        $this->submit($payload)->assertCreated();
        $this->submit($payload)->assertOk();

        $this->assertDatabaseCount('apparatus_inspections', 1);
        $this->assertDatabaseCount('inspection_photo_uploads', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('defects'));
    }

    public function test_an_older_queued_checkout_with_inline_photos_still_stores_them_once(): void
    {
        $payload = $this->payload();
        $this->damage($payload, null, withDefectPhoto: false);
        $payload['compartments'][0]['items'][0]['photo'] = self::PIXEL;

        $this->submit($payload)->assertCreated();

        $itemPath = ApparatusInspection::query()->sole()->results[0]['items'][0]['photo_path'];
        $this->assertSame($itemPath, ApparatusDefect::query()->sole()->photo_path);
        $this->assertCount(1, Storage::disk('public')->allFiles('defects'));
    }

    public function test_maintenance_requeues_stalled_conversions_and_keeps_attached_photos(): void
    {
        Queue::fake();
        $stalled = (string) Str::uuid();
        $this->upload($stalled, UploadedFile::fake()->image('scan.png', 400, 300))->assertCreated();
        InspectionPhotoUpload::query()->where('uuid', $stalled)->update(['updated_at' => now()->subHour()]);
        $attached = (string) Str::uuid();
        $payload = $this->payload();
        $payload['compartments'][0]['items'][0]['photo_id'] = $attached;
        $this->submit($payload)->assertCreated();
        InspectionPhotoUpload::query()->where('uuid', $attached)->update(['created_at' => now()->subDays(60)]);

        $this->artisan('photos:maintain')->assertSuccessful();

        Queue::assertPushed(ProcessInspectionPhotoUpload::class, fn (ProcessInspectionPhotoUpload $job): bool => $job->uuid === $stalled);
        $this->assertDatabaseHas('inspection_photo_uploads', ['uuid' => $attached]);
    }

    /**
     * Take the checkout's placeholder away, then put it back at the exact moment the upload
     * has read "no row yet" and is about to write: the checkout won the race.
     */
    private function checkoutWinsTheRaceFor(string $photoId): \stdClass
    {
        $placeholder = (array) DB::table('inspection_photo_uploads')->where('uuid', $photoId)->first();
        DB::table('inspection_photo_uploads')->where('uuid', $photoId)->delete();
        $race = new \stdClass;
        $race->raced = false;
        DB::listen(function (QueryExecuted $query) use ($race, $placeholder): void {
            if ($race->raced || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'inspection_photo_uploads')) {
                return;
            }
            $race->raced = true;
            DB::table('inspection_photo_uploads')->insert($placeholder);
        });

        return $race;
    }

    private function upload(string $id, ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        return $this->post(self::URL, [
            'client_photo_id' => $id,
            'photo' => $file ?? UploadedFile::fake()->image('photo.jpg', 1024, 768),
        ], ['Accept' => 'application/json']);
    }

    /** Mark the first item Damaged with its finding, the photo named on the item (and optionally on the finding). */
    private function damage(array &$payload, ?string $photoId, bool $withDefectPhoto): void
    {
        $compartment = $payload['compartments'][0];
        $item = $compartment['items'][0];
        $payload['compartments'][0]['items'][0]['status'] = 'Damaged';
        $payload['compartments'][0]['items'][0]['notes'] = 'Cracked lens';
        if ($photoId !== null) {
            $payload['compartments'][0]['items'][0]['photo_id'] = $photoId;
        }
        $payload['defects'] = [array_filter([
            'compartment' => $compartment['name'],
            'item' => $item['name'],
            'status' => 'Damaged',
            'notes' => 'Cracked lens',
            'compartment_id' => $compartment['id'],
            'item_id' => $item['id'],
            'photo_id' => $withDefectPhoto ? $photoId : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /** @return array<string, mixed> */
    private function concern(string $kind, string $description, string $photoId): array
    {
        return [
            'client_id' => (string) Str::uuid(),
            'kind' => $kind,
            'description' => $description,
            'location' => 'Console',
            'photo_id' => $photoId,
            'existing_issue_id' => null,
        ];
    }

    private function submit(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/public/apparatuses/{$this->boat->id}/inspections", $payload);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $response = $this->postJson("/api/public/apparatuses/{$this->boat->id}/inspection-sessions")->assertCreated();
        $browserCookie = collect($response->headers->getCookies())
            ->first(static fn (\Symfony\Component\HttpFoundation\Cookie $cookie): bool => $cookie->getName() === 'daily_checkout_inspection_browser');
        if ($browserCookie instanceof \Symfony\Component\HttpFoundation\Cookie) {
            $this->withCredentials()->withUnencryptedCookie($browserCookie->getName(), $browserCookie->getValue());
        }
        $contract = $response->json('inspection_session');
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->boat);
        $this->assertIsArray($resolution['checklist']);

        return [
            'processing_version' => 1,
            'meter_baseline_token' => $this->getJson('/api/public/apparatuses')->json('0.meter_baseline_token'),
            'client_submission_id' => self::SUBMISSION,
            'checklist_version' => $contract['checklist_hash'],
            'operator_name' => 'Firefighter Example',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => 'FB6',
            'engine_hours' => null,
            'miles' => null,
            'compartments' => array_map(static fn (array $compartment): array => [
                'id' => $compartment['id'],
                'name' => $compartment['name'],
                'items' => array_map(static fn (array $item): array => [
                    'id' => $item['id'], 'name' => $item['name'], 'status' => 'Present', 'observed' => true, 'notes' => null,
                ], $compartment['items']),
            ], $resolution['checklist']['compartments']),
            'defects' => [],
            'field_values' => array_map(static fn (array $field): array => [
                'id' => $field['id'],
                'value' => match (true) {
                    $field['inputType'] === 'date' => $contract['duty_date'],
                    $field['id'] === 'fb6-shift' => 'A',
                    $field['inputType'] === 'checkbox' => false,
                    $field['inputType'] === 'number', $field['inputType'] === 'percentage' => null,
                    default => '',
                },
            ], $resolution['checklist']['fields']),
            'scheduled_tasks' => array_map(static fn (array $task): array => ['id' => $task['id'], 'status' => 'Completed', 'notes' => null, 'observed' => true], $contract['due_tasks']),
            'inspection_session_id' => $contract['id'],
            'inspection_session_token' => $contract['token'],
            'inspection_session_replay_key' => $contract['replay_key'],
            'officer_signature' => self::PIXEL,
        ];
    }

    private function makeFireBoat6(): Apparatus
    {
        $station = Station::query()->firstOrCreate(
            ['station_number' => 6],
            ['name' => 'Station 6', 'address' => '123 Marine Drive', 'is_active' => true],
        );

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'FB6',
            'name' => 'Fire Boat 6',
            'type' => 'Fire Boat',
            'class_description' => 'Marine',
            'vehicle_number' => null,
            'designation' => 'FB6',
            'slug' => 'fire-boat-6',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);
    }
}
