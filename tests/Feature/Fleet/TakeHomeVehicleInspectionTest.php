<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TakeHomeVehicleInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/take-home-vehicle-inspections';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(self::BASE)->assertRedirect();
        $this->get(self::BASE.'/new')->assertRedirect();
        $this->getJson(self::BASE.'/lookup/vehicles?q=1')->assertUnauthorized();
    }

    public function test_any_member_without_roles_can_open_the_pages(): void
    {
        $user = $this->member();

        $this->actingAs($user)->get(self::BASE)
            ->assertOk()
            ->assertSee('Take Home Vehicle Inspections')
            ->assertSee('New inspection')
            ->assertSee('You have not submitted a take-home inspection yet.');

        $this->actingAs($user)->get(self::BASE.'/new')
            ->assertOk()
            ->assertSee('Inspection type: Take Home Vehicle Inspection')
            ->assertSee('Exterior lights')
            ->assertSee('instrument cluster');
    }

    public function test_home_has_no_inspection_card_for_a_member_with_a_profile(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get('/')->assertOk()
            ->assertDontSee('Take Home Vehicle Inspection')
            ->assertDontSee('Complete a take-home vehicle inspection.')
            ->assertDontSee('href="'.route('take-home-inspections.index').'"', false);
    }

    public function test_home_keeps_a_path_to_the_inspection_for_a_member_without_a_profile(): void
    {
        $response = $this->actingAs($this->member())->get('/')
            ->assertOk()
            ->assertDontSee('Take Home Vehicle Inspection')
            ->assertSee('Take Home Inspection')
            ->assertSee('Complete a take-home vehicle inspection.')
            ->assertSee(route('take-home-inspections.index'), false);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $destination = route('take-home-inspections.index');
        self::assertSame(1, $xpath->query('//a[@data-quick-access-card and @href="'.$destination.'"]')->length);
        self::assertSame(0, $xpath->query('//footer//a[@href="'.$destination.'"]')->length);
        self::assertSame(1, $xpath->query('//footer//a[@href="'.url('/security-standards').'"]')->length);
    }

    public function test_vehicle_search_resolves_an_exact_number_and_partial_matches(): void
    {
        $user = $this->member();
        $target = $this->vehicle(['veh_number' => '22514', 'model' => 'Escape']);
        $this->vehicle(['veh_number' => '22515']);
        $this->vehicle(['veh_number' => '1027', 'asset_category' => 'trailer', 'make' => 'Wells Cargo']);

        $exact = $this->actingAs($user)->getJson(self::BASE.'/lookup/vehicles?q=22514')->assertOk();
        $this->assertSame([$target->id], array_column($exact->json('exact'), 'id'));

        $partial = $this->actingAs($user)->getJson(self::BASE.'/lookup/vehicles?q=2251')->assertOk();
        $this->assertCount(2, $partial->json('results'));
        $this->assertSame([], $partial->json('exact'));

        $trailer = $this->actingAs($user)->getJson(self::BASE.'/lookup/vehicles?q=wells')->assertOk();
        $this->assertTrue($trailer->json('results.0.nonroad'));
        $this->assertSame('Trailer', $trailer->json('results.0.category_label'));

        $detail = $this->actingAs($user)->getJson(self::BASE.'/lookup/vehicles/'.$target->id)->assertOk();
        $this->assertSame('Escape', $detail->json('vehicle.fields.model'));
    }

    public function test_employee_search_returns_only_name_and_employee_id(): void
    {
        $this->employee('Jordan Rivera', '40123');
        $this->employee('Jordan Former', '40999', 'separated');

        $response = $this->actingAs($this->member())->getJson(self::BASE.'/lookup/employees?q=jordan')->assertOk();

        $this->assertCount(1, $response->json('results'));
        $this->assertSame(['id', 'name', 'employee_id'], array_keys($response->json('results.0')));
        $this->assertSame('40123', $response->json('results.0.employee_id'));
    }

    public function test_member_can_add_a_missing_vehicle_once_with_duplicate_guards(): void
    {
        $user = $this->member();
        $this->vehicle(['veh_number' => '1665', 'serial_number' => '1FAHP2D85DG117005']);

        $created = $this->actingAs($user)->postJson(self::BASE.'/lookup/vehicles', [
            'veh_number' => '30111', 'make' => 'Ford', 'model' => 'F150', 'year' => '2025',
        ])->assertCreated();
        $vehicle = UnitMasterVehicle::query()->findOrFail($created->json('vehicle.id'));
        $this->assertTrue($vehicle->needs_fleet_review);
        $this->assertDatabaseHas('unit_master_vehicle_changes', [
            'unit_master_vehicle_id' => $vehicle->id, 'field' => 'record', 'source' => UnitMasterVehicleChange::SOURCE_TAKE_HOME_NEW, 'user_id' => $user->id,
        ]);

        $this->actingAs($user)->postJson(self::BASE.'/lookup/vehicles', ['veh_number' => '30111', 'make' => 'Ford', 'model' => 'F150'])
            ->assertStatus(409);
        $this->actingAs($user)->postJson(self::BASE.'/lookup/vehicles', ['veh_number' => '30112', 'make' => 'Ford', 'model' => 'Taurus', 'serial_number' => '1fahp2d85dg117005'])
            ->assertStatus(409)
            ->assertJsonPath('vehicle.veh_number', '1665');
        $this->assertSame(2, UnitMasterVehicle::query()->count());
    }

    public function test_photo_upload_is_re_encoded_private_and_only_visible_to_the_uploader(): void
    {
        $user = $this->member();
        $response = $this->actingAs($user)->post(self::BASE.'/photos', [
            'photo' => UploadedFile::fake()->image('front.jpg', 1600, 1200),
            'angle' => 'front',
        ], ['Accept' => 'application/json'])->assertCreated();

        $photo = TakeHomeVehicleInspectionPhoto::query()->where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertNull($photo->take_home_vehicle_inspection_id);
        $this->assertStringStartsWith('take-home-inspections/photos/', $photo->path);
        $bytes = Storage::disk('local')->get($photo->path);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $bytes);
        $this->assertStringNotContainsString('Exif', substr($bytes, 0, 64));

        $this->actingAs($user)->get(self::BASE.'/photos/'.$photo->uuid)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($this->member())->get(self::BASE.'/photos/'.$photo->uuid)->assertNotFound();
    }

    public function test_non_image_uploads_are_rejected(): void
    {
        $this->actingAs($this->member())->post(self::BASE.'/photos', [
            'photo' => UploadedFile::fake()->createWithContent('front.jpg', '<?php echo "not an image";'),
            'angle' => 'front',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_full_submission_links_records_syncs_safe_fields_and_flags_conflicts(): void
    {
        $user = $this->member();
        $driver = $this->employee('Taylor Driver', '51234');
        $this->vehicle(['veh_number' => '22515', 'serial_number' => 'OTHERVIN0000000001']);
        $vehicle = $this->vehicle(['veh_number' => '22514', 'make' => 'Ford', 'model' => 'Escape', 'color' => null, 'serial_number' => null]);

        $payload = $this->payload($user, $vehicle, [
            'driver_employee_id' => $driver->id,
            'vehicle' => [
                'make' => 'Ford', 'model' => 'Escape SE PHEV', 'year' => '2022', 'color' => 'White',
                'tag_number' => 'XJ7268', 'serial_number' => 'OTHERVIN0000000001', 'dept_code' => '1230',
            ],
        ]);
        $payload['checks']['tires_wheels'] = ['status' => 'issue', 'issue_description' => "Front left tire low.\nNeeds air.", 'notes' => null];
        $payload['checks']['interior'] = ['status' => 'not_checked', 'issue_description' => null, 'notes' => null];
        $payload['issue_photos'] = ['tires_wheels' => [$this->uploadPhoto($user, 'issue', 'tires_wheels')]];

        $response = $this->actingAs($user)->postJson(self::BASE, $payload)->assertCreated();
        $inspection = TakeHomeVehicleInspection::query()->where('reference', $response->json('reference'))->firstOrFail();

        $this->assertMatchesRegularExpression('/^THI-\d{6}-[A-Z0-9]{6}$/', $inspection->reference);
        $this->assertSame($user->id, $inspection->inspector_user_id);
        $this->assertSame($driver->id, $inspection->driver_employee_id);
        $this->assertTrue($inspection->driver_verified);
        $this->assertSame('51234', $inspection->driver_employee_number);
        $this->assertTrue($inspection->has_issues);
        $this->assertTrue($inspection->has_not_checked);
        $this->assertSame("Front left tire low.\nNeeds air.", collect($inspection->checks)->firstWhere('key', 'tires_wheels')['issue_description']);
        $this->assertSame('not_checked', collect($inspection->checks)->firstWhere('key', 'interior')['status']);
        $this->assertCount(7, $inspection->photos);
        $this->assertSame('Escape SE PHEV', $inspection->vehicle_snapshot['model']);
        $this->assertTrue(Storage::disk('local')->exists($inspection->signature_path));

        $vehicle->refresh();
        // Empty master fields are filled; existing values are never overwritten by a member.
        $this->assertSame('White', $vehicle->color);
        $this->assertSame('2022', $vehicle->year);
        $this->assertSame('Escape', $vehicle->model);
        $this->assertNull($vehicle->serial_number);
        $this->assertSame($driver->id, $vehicle->current_driver_employee_id);
        $this->assertDatabaseHas('unit_master_vehicle_changes', [
            'unit_master_vehicle_id' => $vehicle->id, 'field' => 'color', 'new_value' => 'White', 'status' => 'applied', 'take_home_vehicle_inspection_id' => $inspection->id,
        ]);
        $this->assertDatabaseHas('unit_master_vehicle_changes', [
            'unit_master_vehicle_id' => $vehicle->id, 'field' => 'model', 'old_value' => 'Escape', 'new_value' => 'Escape SE PHEV', 'status' => 'proposed',
        ]);
        $vinProposal = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $vehicle->id)->where('field', 'serial_number')->firstOrFail();
        $this->assertSame('proposed', $vinProposal->status);
        $this->assertStringContainsString('#22515', (string) $vinProposal->note);

        $this->assertSame($inspection->id, $vehicle->latestTakeHomeInspection?->id);

        $this->actingAs($user)->get(self::BASE)->assertOk()->assertSee('Inspection submitted · '.$inspection->reference);
    }

    public function test_resubmitting_the_same_inspection_never_creates_a_duplicate(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $payload = $this->payload($user, $vehicle);

        $first = $this->actingAs($user)->postJson(self::BASE, $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson(self::BASE, $payload)->assertOk();

        $this->assertSame($first->json('reference'), $second->json('reference'));
        $this->assertSame(1, TakeHomeVehicleInspection::query()->count());
    }

    public function test_only_the_vehicle_acknowledgment_and_signature_are_required(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);

        $response = $this->actingAs($user)->postJson(self::BASE, [
            'client_submission_id' => (string) Str::uuid(),
            'vehicle_id' => $vehicle->id,
            'acknowledged' => true,
            'signature' => $this->signature(true),
        ])->assertCreated();

        $inspection = TakeHomeVehicleInspection::query()->where('reference', $response->json('reference'))->firstOrFail();
        $this->assertNull($inspection->driver_name);
        $this->assertNull($inspection->odometer);
        $this->assertNull($inspection->keys_present);
        $this->assertNull($inspection->acceptable_for_use);
        $this->assertCount(0, $inspection->photos);
        $this->assertSame([null], array_values(array_unique(array_column($inspection->checks, 'status'), SORT_REGULAR)));
        // Unanswered is never reported as an all-clear.
        $this->assertFalse($inspection->has_issues);
        $this->assertTrue($inspection->has_not_checked);
        $this->assertTrue($inspection->needsAttention());

        $this->actingAs($user)->get(self::BASE.'/'.$inspection->reference)->assertOk()
            ->assertSee('Not answered')
            ->assertSee('Not recorded')
            ->assertSee('Not provided')
            ->assertSee('Acceptability for use was not answered.');
        $pdf = $this->actingAs($user)->get(self::BASE.'/'.$inspection->reference.'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_only_missing_vehicle_acknowledgment_or_signature_block_submission(): void
    {
        $user = $this->member();
        $this->vehicle(['veh_number' => '22514']);

        $response = $this->actingAs($user)->postJson(self::BASE, [
            'client_submission_id' => (string) Str::uuid(),
            'acknowledged' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id', 'acknowledged', 'signature']);

        $this->assertSame(['acknowledged', 'signature', 'vehicle_id'], collect($response->json('errors'))->keys()->sort()->values()->all());
        $this->assertSame(0, TakeHomeVehicleInspection::query()->count());
    }

    public function test_an_issue_without_a_description_and_partial_photos_are_accepted(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $payload = $this->payload($user, $vehicle);
        unset($payload['photos']['dashboard'], $payload['photos']['interior']);
        $payload['checks']['exterior_body'] = ['status' => 'issue', 'issue_description' => '', 'notes' => null];
        unset($payload['checks']['windows_mirrors']);
        $payload['keys_present'] = null;

        $response = $this->actingAs($user)->postJson(self::BASE, $payload)->assertCreated();

        $inspection = TakeHomeVehicleInspection::query()->where('reference', $response->json('reference'))->firstOrFail();
        $this->assertCount(4, $inspection->photos);
        $this->assertTrue($inspection->has_issues);
        $this->assertTrue($inspection->has_not_checked);
        $this->assertNull($inspection->keys_present);
    }

    public function test_blank_signature_and_another_members_photo_are_refused(): void
    {
        $user = $this->member();
        $other = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);

        $blank = $this->payload($user, $vehicle, ['signature' => $this->signature(false)]);
        $this->actingAs($user)->postJson(self::BASE, $blank)->assertUnprocessable()->assertJsonValidationErrors('signature');

        $stolen = $this->payload($user, $vehicle);
        $stolen['photos']['rear'] = $this->uploadPhoto($other, 'rear');
        $this->actingAs($user)->postJson(self::BASE, $stolen)->assertUnprocessable()->assertJsonValidationErrors('photos.rear');
        $this->assertSame(0, TakeHomeVehicleInspection::query()->count());
    }

    public function test_one_photo_cannot_fill_two_required_angles(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $payload = $this->payload($user, $vehicle);
        $payload['photos']['front'] = $payload['photos']['rear'];
        $payload['checks']['exterior_body'] = ['status' => 'issue', 'issue_description' => 'Dent.', 'notes' => null];
        $payload['issue_photos'] = ['exterior_body' => [$this->uploadPhoto($user, 'issue', 'exterior_body')]];

        $this->actingAs($user)->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertSame(0, TakeHomeVehicleInspection::query()->count());
    }

    public function test_unlisted_driver_is_recorded_as_unverified_and_lower_odometer_is_flagged(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $this->actingAs($user)->postJson(self::BASE, $this->payload($user, $vehicle, ['odometer' => 45000]))->assertCreated();

        $response = $this->actingAs($user)->postJson(self::BASE, $this->payload($user, $vehicle, [
            'odometer' => 44000,
            'driver_employee_id' => null,
            'driver_name' => 'Casey Contractor',
            'driver_employee_number' => null,
        ]))->assertCreated();

        $inspection = TakeHomeVehicleInspection::query()->where('reference', $response->json('reference'))->firstOrFail();
        $this->assertFalse($inspection->driver_verified);
        $this->assertNull($inspection->driver_employee_id);
        $this->assertSame('Casey Contractor', $inspection->driver_name);
        $this->assertTrue($inspection->odometer_flagged);
        $this->assertStringContainsString('45,000', (string) $inspection->odometer_note);
        $this->assertNull($vehicle->fresh()->current_driver_employee_id);
    }

    public function test_reports_are_private_to_the_inspector_and_fleet_viewers(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $reference = $this->actingAs($user)->postJson(self::BASE, $this->payload($user, $vehicle))->assertCreated()->json('reference');
        $inspection = TakeHomeVehicleInspection::query()->where('reference', $reference)->firstOrFail();
        $photo = $inspection->photos->first();

        $this->actingAs($user)->get(self::BASE.'/'.$reference)->assertOk()->assertSee('Condition checks')->assertSee('Download PDF');
        $pdf = $this->actingAs($user)->get(self::BASE.'/'.$reference.'/pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->actingAs($user)->get(self::BASE.'/'.$reference.'/signature')->assertOk()->assertHeader('Content-Type', 'image/png');

        $outsider = $this->member();
        $this->actingAs($outsider)->get(self::BASE.'/'.$reference)->assertNotFound();
        $this->actingAs($outsider)->get(self::BASE.'/'.$reference.'/pdf')->assertNotFound();
        $this->actingAs($outsider)->get(self::BASE.'/'.$reference.'/signature')->assertNotFound();
        $this->actingAs($outsider)->get(self::BASE.'/photos/'.$photo->uuid)->assertNotFound();
        $this->actingAs($outsider)->get(self::BASE)->assertOk()->assertDontSee($reference);

        $fleet = $this->member();
        foreach (['admin.access', 'admin.fleet.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $fleet->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($fleet)->get(self::BASE.'/'.$reference)->assertOk();
        $this->actingAs($fleet)->get(self::BASE.'/'.$reference.'/pdf')->assertOk();
        $this->actingAs($fleet)->get(self::BASE.'/photos/'.$photo->uuid)->assertOk();
    }

    public function test_submitted_inspections_are_immutable(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $reference = $this->actingAs($user)->postJson(self::BASE, $this->payload($user, $vehicle))->json('reference');
        $inspection = TakeHomeVehicleInspection::query()->where('reference', $reference)->firstOrFail();

        $this->expectException(LogicException::class);
        $inspection->update(['acceptable_for_use' => false]);
    }

    public function test_abandoned_uploads_are_pruned_but_submitted_photos_are_kept(): void
    {
        $user = $this->member();
        $vehicle = $this->vehicle(['veh_number' => '22514']);
        $this->actingAs($user)->postJson(self::BASE, $this->payload($user, $vehicle))->assertCreated();
        $orphanUuid = $this->uploadPhoto($user, 'front');
        TakeHomeVehicleInspectionPhoto::query()->where('uuid', $orphanUuid)->update(['created_at' => now()->subDays(5)]);

        $this->artisan('fleet:prune-take-home-uploads')->assertSuccessful();

        $this->assertDatabaseMissing('take_home_vehicle_inspection_photos', ['uuid' => $orphanUuid]);
        $this->assertSame(6, TakeHomeVehicleInspectionPhoto::query()->whereNotNull('take_home_vehicle_inspection_id')->count());
    }

    private function member(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active]);
    }

    private function employee(string $name, string $number, string $status = 'active'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $number,
            'name' => $name,
            'password' => Str::random(40),
            'roster_status' => $status,
        ]);
    }

    private function vehicle(array $attributes): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(array_merge([
            'make' => 'Ford', 'model' => 'Escape', 'year' => '2022', 'tag_number' => 'XJ'.random_int(1000, 9999),
        ], $attributes));
    }

    private function uploadPhoto(User $user, string $angle, ?string $checkKey = null): string
    {
        return (string) $this->actingAs($user)->post(self::BASE.'/photos', array_filter([
            'photo' => UploadedFile::fake()->image($angle.'.jpg', 800, 600),
            'angle' => $angle,
            'check_key' => $checkKey,
        ]), ['Accept' => 'application/json'])->assertCreated()->json('uuid');
    }

    private function payload(User $user, UnitMasterVehicle $vehicle, array $overrides = []): array
    {
        $checks = [];
        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            $checks[$key] = ['status' => 'good', 'issue_description' => null, 'notes' => null];
        }
        $photos = [];
        foreach (array_keys(TakeHomeVehicleInspection::PHOTO_ANGLES) as $angle) {
            $photos[$angle] = $this->uploadPhoto($user, $angle);
        }

        return array_merge([
            'client_submission_id' => (string) Str::uuid(),
            'started_at' => now()->subMinutes(6)->toIso8601String(),
            'vehicle_id' => $vehicle->id,
            'vehicle' => [
                'make' => $vehicle->make, 'model' => $vehicle->model, 'year' => $vehicle->year,
                'color' => $vehicle->color, 'tag_number' => $vehicle->tag_number,
                'serial_number' => $vehicle->serial_number, 'dept_code' => $vehicle->dept_code,
            ],
            'driver_employee_id' => null,
            'driver_name' => 'Morgan Member',
            'driver_employee_number' => '77777',
            'odometer' => 12345,
            'odometer_exception' => null,
            'checks' => $checks,
            'photos' => $photos,
            'issue_photos' => [],
            'keys_present' => true,
            'acceptable_for_use' => true,
            'comments' => 'Routine check.',
            'acknowledged' => true,
            'signature' => $this->signature(true),
        ], $overrides);
    }

    private function signature(bool $signed): string
    {
        $image = imagecreatetruecolor(1200, 400);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if ($signed) {
            $ink = imagecolorallocate($image, 15, 23, 42);
            imagesetthickness($image, 8);
            for ($i = 0; $i < 6; $i++) {
                imageline($image, 80 + $i * 170, 300, 220 + $i * 170, 90, $ink);
                imageline($image, 220 + $i * 170, 90, 250 + $i * 170, 300, $ink);
            }
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
