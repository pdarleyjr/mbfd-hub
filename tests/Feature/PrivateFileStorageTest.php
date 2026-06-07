<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Station;
use App\Models\StationInventorySubmission;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Finding H-04 — sensitive files must not live on the web-reachable public disk.
 *
 * Station inventory PDFs and workgroup shared uploads are stored on a NON-public
 * (private) disk and only served through authorized controller routes — never via
 * a bare /storage/... URL.
 */
class PrivateFileStorageTest extends TestCase
{
    use RefreshDatabase;

    private function privateDisk(): string
    {
        return config('filesystems.private', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Fake both disks so nothing touches the real filesystem.
        Storage::fake('public');
        Storage::fake($this->privateDisk());
    }

    private function makeStation(): Station
    {
        return Station::create([
            'station_number' => 1,
            'address' => '123 Main St',
            'is_active' => true,
            'inventory_pin' => '1234',
        ]);
    }

    /**
     * Seed a submission whose PDF lives on the private disk (mirrors what the
     * hardened StationInventoryController::store now does).
     */
    private function seedInventorySubmission(User $user, Station $station): StationInventorySubmission
    {
        $path = 'inventory-submissions/inventory-'.$station->id.'-test.pdf';
        Storage::disk($this->privateDisk())->put($path, '%PDF-1.4 test');

        return StationInventorySubmission::create([
            'station_id' => $station->id,
            'items' => [['itemId' => 'paper_towels', 'quantity' => 1]],
            'pdf_path' => $path,
            'created_by' => $user->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // 1. The PDF is NOT reachable under the public /storage/ path.
    // ---------------------------------------------------------------------

    public function test_inventory_pdf_is_on_private_disk_and_absent_from_public(): void
    {
        $user = User::factory()->create();
        $station = $this->makeStation();
        $submission = $this->seedInventorySubmission($user, $station);

        Storage::disk($this->privateDisk())->assertExists($submission->pdf_path);
        Storage::disk('public')->assertMissing($submission->pdf_path);

        // The symlinked public route must NOT serve the file (403/404) — it is
        // not web-reachable because it lives on the private disk.
        $status = $this->get('/storage/'.$submission->pdf_path)->getStatusCode();
        $this->assertContains($status, [403, 404], 'PDF must not be reachable via /storage/.');
    }

    // ---------------------------------------------------------------------
    // 2. The authorized controller route streams the file; anon is rejected.
    // ---------------------------------------------------------------------

    public function test_authenticated_user_downloads_inventory_pdf_from_private_disk(): void
    {
        $user = User::factory()->create();
        $station = $this->makeStation();
        $submission = $this->seedInventorySubmission($user, $station);

        Sanctum::actingAs($user);
        $response = $this->get(route('download-inventory-pdf', $submission));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_anonymous_user_cannot_download_inventory_pdf(): void
    {
        $user = User::factory()->create();
        $station = $this->makeStation();
        $submission = $this->seedInventorySubmission($user, $station);

        // Web route is auth-protected -> redirect to login for guests.
        $this->get(route('download-inventory-pdf', $submission))->assertRedirect();
    }

    // ---------------------------------------------------------------------
    // 3. Workgroup shared upload: private disk + authorized-only download.
    // ---------------------------------------------------------------------

    private function seedWorkgroupUpload(User $user): WorkgroupSharedUpload
    {
        $workgroup = Workgroup::create(['name' => 'Test WG', 'created_by' => $user->id]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'S1',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $member = WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $path = 'workgroup-shared-uploads/'.$workgroup->id.'/test.txt';
        Storage::disk($this->privateDisk())->put($path, 'secret-contents');

        return WorkgroupSharedUpload::create([
            'workgroup_id' => $workgroup->id,
            'workgroup_session_id' => $session->id,
            'user_id' => $user->id,
            'workgroup_member_id' => $member->id,
            'filename' => 'test.txt',
            'filepath' => $path,
            'file_type' => 'text/plain',
            'file_size' => 15,
        ]);
    }

    public function test_workgroup_shared_upload_is_private_and_served_only_to_authorized_member(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::firstOrCreate(['name' => 'workgroup_member', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole($role);

        $upload = $this->seedWorkgroupUpload($user);

        // Not on the public disk.
        Storage::disk('public')->assertMissing($upload->filepath);

        // Anonymous request is rejected (auth middleware redirects guests).
        $this->get(route('workgroup.shared-upload.download', $upload))->assertRedirect();

        // Authorized member downloads successfully from the private disk.
        $this->actingAs($user);
        $this->get(route('workgroup.shared-upload.download', $upload))->assertStatus(200);
    }
}
