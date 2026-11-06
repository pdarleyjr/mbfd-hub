<?php

declare(strict_types=1);

namespace Tests\Feature\Photos;

use App\Enums\AccountStatus;
use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Inspections\EmployeeInspectionHistoryService;
use App\Support\Media\MemberMedia;
use App\Support\Security\Base64Image;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Member photos and signatures are private: who may read them, through which route,
 * from which disk, and that the public /storage path never serves them.
 */
final class MemberMediaAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Filesystem $public;

    private Filesystem $private;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->public = Storage::fake('public');
        $this->private = Storage::fake('local');
    }

    // ------------------------------------------------------------- signed address

    public function test_a_signed_address_serves_the_image_privately_to_the_viewer_it_was_issued_to(): void
    {
        $viewer = $this->member('M-1');
        $this->private->put('defects/a.png', base64_decode(self::PNG));
        $url = (string) MemberMedia::url('defects/a.png', $viewer);

        $this->assertStringContainsString('/member-media/defects/a.png?', $url);
        $response = $this->actingAs($viewer)->get($url)->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(base64_decode(self::PNG), $response->streamedContent());
    }

    public function test_a_signed_address_is_useless_to_another_member_a_guest_or_after_it_expires(): void
    {
        $viewer = $this->member('M-2');
        $other = $this->member('M-3');
        $this->private->put('defects/a.png', base64_decode(self::PNG));
        $this->private->put('defects/b.png', base64_decode(self::PNG));
        $url = (string) MemberMedia::url('defects/a.png', $viewer);

        $this->get($url)->assertRedirect('/login');
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($viewer)->get(str_replace('defects/a.png', 'defects/b.png', $url))->assertForbidden();
        $this->actingAs($other)->get((string) preg_replace('/viewer=\d+/', 'viewer='.$other->id, $url))->assertForbidden();

        $this->travel(MemberMedia::SIGNED_URL_MINUTES + 6)->minutes();
        $this->actingAs($viewer)->get($url)->assertForbidden();
    }

    public function test_addresses_are_only_issued_for_member_images_with_a_viewer(): void
    {
        $viewer = $this->member('M-4');

        $this->assertNull(MemberMedia::url('narcotics-checks/a.png', $viewer));
        $this->assertNull(MemberMedia::url('defects/../.env', $viewer));
        $this->assertNull(MemberMedia::url('defects/a.php', $viewer));
        $this->assertNull(MemberMedia::url('defects/a.png'), 'No signed-in viewer');
        $this->assertNotNull(MemberMedia::url('defects/uploads/ab/ab12.jpg', $viewer));
    }

    // ------------------------------------------------------------- both disks

    public function test_reads_find_the_private_copy_first_and_fall_back_to_a_public_copy_not_moved_yet(): void
    {
        $viewer = $this->member('M-5');
        $this->public->put('defects/old.png', base64_decode(self::PNG));
        $this->public->put('defects/both.png', 'public bytes');
        $this->private->put('defects/both.png', base64_decode(self::PNG));

        $this->assertSame(['disk' => 'public', 'path' => 'defects/old.png'], MemberMedia::locate('defects/old.png'));
        $this->assertSame('local', MemberMedia::locate('defects/both.png')['disk'] ?? null);
        $this->assertSame(base64_decode(self::PNG), MemberMedia::get('defects/both.png'));
        $this->actingAs($viewer)->get((string) MemberMedia::url('defects/old.png', $viewer))->assertOk();

        $this->assertSame('defects/old.png', $this->defect(['photo_path' => 'defects/old.png'])->availablePhotoPath());
        $this->assertSame('defects/both.png', $this->defect(['photo_path' => 'defects/both.png'])->availablePhotoPath());
        $this->assertNull($this->defect(['photo_path' => 'defects/gone.png'])->availablePhotoPath());
    }

    public function test_the_admin_issue_table_shows_a_signed_address_and_never_a_public_storage_url(): void
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->withoutVite();
        $this->public->put('defects/legacy.png', base64_decode(self::PNG)); // not moved yet
        $this->defect(['photo_path' => 'defects/legacy.png']);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListDefects::class)
            ->call('loadTable')
            ->assertSeeHtml('/member-media/defects/legacy.png?')
            ->assertDontSeeHtml('/storage/defects/legacy.png');
    }

    // ------------------------------------------------------------- issue photo routes (member app)

    /** Issue history is the whole crew's by design (docs/apparatus-issue-history.md section 2): reporter, participants and crewmates alike. */
    public function test_issue_photos_follow_the_issue_history_rule_and_stream_from_the_private_disk(): void
    {
        $reporter = $this->member('M-6');
        $crewmate = $this->member('M-8');
        $noProfile = User::factory()->create(['account_status' => AccountStatus::Active]);
        $fleetStaff = User::factory()->create(['account_status' => AccountStatus::Active]);
        $fleetStaff->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->private->put('defects/issue.png', base64_decode(self::PNG));
        $defect = $this->defect(['photo_path' => 'defects/issue.png']);
        $url = "/api/public/apparatus-issues/{$defect->id}/photo";

        $this->getJson($url)->assertUnauthorized();
        foreach ([$reporter, $crewmate, $fleetStaff] as $allowed) {
            $this->actingAsCanonicalUser($allowed)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $this->actingAsCanonicalUser($noProfile)->getJson($url)->assertForbidden();
        $this->private->delete('defects/issue.png');
        $this->actingAsCanonicalUser($reporter)->get($url)->assertNotFound();
    }

    // ------------------------------------------------------------- inspection evidence route (own record)

    public function test_inspection_signature_and_photos_are_for_the_owner_and_inspection_staff_only(): void
    {
        $owner = $this->member('M-9');
        $other = $this->member('M-10');
        $staff = User::factory()->create(['account_status' => AccountStatus::Active]);
        $staff->givePermissionTo(Permission::findOrCreate('view_inspection', 'web'));
        $this->private->put('signatures/sig.png', base64_decode(self::PNG));
        $this->public->put('defects/finding.png', base64_decode(self::PNG)); // not moved yet
        $inspection = $this->inspection([
            'actor_user_id' => $owner->id,
            'officer_signature' => 'signatures/sig.png',
            'results' => [['id' => 'c', 'name' => 'Cab', 'items' => [['id' => 'a', 'name' => 'Gauge', 'status' => 'Damaged', 'notes' => null, 'photo_path' => 'defects/finding.png']]]],
        ]);
        $history = app(EmployeeInspectionHistoryService::class);
        $signature = route('employee.vehicle-inspections.image', [$inspection, 'signature']);
        $photo = route('employee.vehicle-inspections.image', [$inspection, $history->imageKey('defects/finding.png')]);

        $this->get($signature)->assertRedirect('/login');
        $this->actingAs($owner)->get($signature)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($owner)->get($photo)->assertOk();
        $this->actingAs($staff)->get($signature)->assertOk();
        $this->actingAs($other)->get($signature)->assertForbidden();
        $this->actingAs($other)->get($photo)->assertForbidden();

        $this->assertStringStartsWith('data:image/png;base64,', (string) $history->embeddableImage('signatures/sig.png', 1_000_000));
        $this->assertStringStartsWith('data:image/png;base64,', (string) $history->embeddableImage('defects/finding.png', 1_000_000));
    }

    // ------------------------------------------------------------- public path and new writes

    public function test_the_public_storage_path_never_serves_member_images(): void
    {
        $this->public->put('defects/x.jpg', 'leftover');
        $this->public->put('signatures/s.png', base64_decode(self::PNG));

        foreach (['/storage/defects/x.jpg', '/storage/signatures/s.png', '/storage/defects/uploads/ab/ab.jpg', '/storage/station-requests/signatures/m.png', '/storage/defects'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->actingAs($this->member('M-11'))->get('/storage/defects/x.jpg')->assertNotFound();
    }

    public function test_the_web_server_configs_refuse_member_image_paths_on_the_public_disk(): void
    {
        $nginx = str_replace("\r\n", "\n", (string) file_get_contents(base_path('docker/production/nginx.conf')));
        $server = (string) file_get_contents(base_path('server.php'));
        $pattern = implode('|', MemberMedia::DIRECTORIES);

        $this->assertStringContainsString("location ~ ^/storage/(?:{$pattern})(?:/|$) {\n            return 404;", $nginx);
        $this->assertStringContainsString("(?:{$pattern})", $server);
    }

    public function test_new_member_images_are_written_to_the_private_disk(): void
    {
        $path = Base64Image::store('data:image/png;base64,'.self::PNG, 'signatures', 'officer-signature');

        $this->assertNotNull($path);
        $this->private->assertExists((string) $path);
        $this->public->assertMissing((string) $path);

        MemberMedia::delete($path);
        $this->private->assertMissing((string) $path);
    }

    // ------------------------------------------------------------- fixtures

    private function member(string $number): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $number, 'name' => "Member {$number}", 'rank' => 'Firefighter',
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
        ]);
    }

    private function apparatus(): Apparatus
    {
        return Apparatus::query()->firstOrCreate(['slug' => 'e77'], [
            'name' => 'Engine E77', 'unit_id' => 'E77', 'designation' => 'E77', 'type' => 'Engine',
            'vehicle_number' => 'TEST-177', 'status' => 'In Service', 'daily_checkout_requirement' => 'required',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function defect(array $attributes): ApparatusDefect
    {
        return ApparatusDefect::query()->create([
            'apparatus_id' => $this->apparatus()->id, 'compartment' => 'Deck', 'item' => 'Hinge '.(++$this->sequence),
            'status' => 'open', 'issue_type' => 'damaged', 'notes' => 'Seeded issue', 'action_status' => 'reported',
            'first_reported_at' => CarbonImmutable::parse('2026-11-05 12:00:00'),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function inspection(array $overrides): ApparatusInspection
    {
        $apparatus = $this->apparatus();
        $number = ++$this->sequence;

        return ApparatusInspection::query()->create(array_merge([
            'apparatus_id' => $apparatus->id, 'operator_name' => 'Fixture Operator', 'rank' => 'Firefighter', 'shift' => 'A',
            'vehicle_number' => $apparatus->vehicle_number, 'designation_at_time' => $apparatus->designation,
            'inspection_reference' => sprintf('INS-E77-%06d', $number), 'results' => [],
            'review_status' => 'approved', 'processing_status' => 'accepted', 'checklist_version' => str_repeat('a', 64),
            'completed_at' => now()->subMinutes($number),
        ], $overrides));
    }
}
