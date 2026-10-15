<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingKioskGrant;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingAssignmentProvider;
use App\Services\Training\TrainingKioskService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingKioskTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private TrainingSession $roster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Role::findOrCreate('training_admin', 'web');
        $this->instructor = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->instructor->assignRole('training_admin');
        $course = TrainingCourse::query()->create(['name' => 'Synthetic classroom test', 'classifications' => ['EMS Training'], 'created_by' => $this->instructor->id]);
        $this->roster = TrainingSession::query()->create([
            'public_id' => (string) Str::ulid(), 'training_course_id' => $course->id,
            'course_name' => $course->name, 'classifications' => $course->classifications,
            'held_on' => '2026-10-15', 'status' => 'open', 'revision' => 1, 'created_by' => $this->instructor->id,
        ]);
    }

    public function test_blank_landing_is_public_but_has_no_employee_directory(): void
    {
        $this->get('https://roster.mbfdhub.com/')->assertOk()->assertSee('training-roster-root')->assertDontSee('Synthetic classroom test');
        $csrf = $this->getJson($this->url('csrf'))->assertOk()->assertJsonStructure(['csrf_token']);
        self::assertSame(['csrf_token'], array_keys($csrf->json()));
        self::assertNotEmpty($csrf->json('csrf_token'));
        self::assertStringContainsString('no-store', (string) $csrf->headers->get('Cache-Control'));
        $this->getJson($this->url('state'))->assertForbidden();
        $this->getJson($this->url('candidates'))->assertForbidden();
        $this->postJson($this->url('authorize'), ['token' => str_repeat('0', 64)])->assertForbidden();
        $this->assertDatabaseCount('training_attendances', 0);
    }

    public function test_kiosk_host_cannot_expose_admin_login_or_private_exports(): void
    {
        foreach (['/admin', '/training', '/employee', '/login', '/api/employees', '/training-rosters/'.$this->roster->id.'/export/pdf'] as $path) {
            $this->get('https://roster.mbfdhub.com'.$path)->assertNotFound();
        }
    }

    public function test_authorize_sets_scoped_grant_without_leaking_token_or_account_cookie(): void
    {
        config(['session.domain' => '.mbfdhub.com', 'session.secure' => true]);
        $link = app(TrainingKioskService::class)->issue($this->roster, $this->instructor);
        $token = substr($link, strpos($link, '#key=') + 5);
        $response = $this->postJson($this->url('authorize'), ['token' => $token]);
        $response->assertOk()->assertJsonPath('authorized', true)->assertDontSee($token);
        $response->assertSessionHas('training_grants.'.$this->roster->id);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $cookie = $response->getCookie('mbfd_training_kiosk');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertNull($cookie->getDomain());
        $this->assertDatabaseMissing('training_kiosk_grants', ['token_hash' => $token]);
    }

    public function test_trusted_forwarded_host_is_resolved_before_kiosk_isolation(): void
    {
        config(['session.domain' => '.mbfdhub.com']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $headers = ['X-Forwarded-Host' => 'roster.mbfdhub.com', 'X-Forwarded-Proto' => 'https'];
        $response = $this->get('http://internal.example/', $headers);
        $response->assertOk()->assertSee('training-roster-root');
        $cookie = $response->getCookie('mbfd_training_kiosk');
        self::assertNotNull($cookie);
        self::assertNull($cookie->getDomain());
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->get('http://internal.example/admin', $headers)->assertNotFound();
    }

    public function test_roster_polling_does_not_consume_authorization_or_candidate_rate_limits(): void
    {
        $grant = $this->grant();
        $this->withSession(['training_grants.'.$this->roster->id => $grant->id]);
        for ($i = 0; $i < 35; $i++) {
            $this->getJson($this->url('state'))->assertOk();
        }
        $link = app(TrainingKioskService::class)->issue($this->roster, $this->instructor);
        $token = substr($link, strpos($link, '#key=') + 5);
        $this->postJson($this->url('authorize'), ['token' => $token])->assertOk();
        $this->app->instance(TrainingAssignmentProvider::class, new class extends TrainingAssignmentProvider
        {
            public function candidates(string $asOf): array
            {
                return [];
            }
        });
        $this->getJson($this->url('candidates'))->assertOk()->assertJsonPath('members', []);
    }

    public function test_grants_are_session_bound_expiring_and_revocable(): void
    {
        $grant = $this->grant();
        $this->withSession(['training_grants.'.$this->roster->id => $grant->id]);
        $this->getJson($this->url('state'))->assertOk()->assertJsonPath('course_name', 'Synthetic classroom test');
        $other = $this->roster->replicate();
        $other->public_id = (string) Str::ulid();
        $other->save();
        $this->getJson('https://roster.mbfdhub.com/r/'.$other->public_id.'/state')->assertForbidden();
        $grant->update(['expires_at' => now()->subSecond()]);
        $this->getJson($this->url('state'))->assertForbidden();
        $grant->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
        $this->getJson($this->url('state'))->assertForbidden();
    }

    public function test_real_signing_retry_and_private_public_projection(): void
    {
        $employee = Employee::query()->create(['employee_id' => '00123', 'name' => 'Synthetic Member', 'rank' => 'Firefighter', 'roster_status' => 'active', 'password' => 'unused']);
        $this->app->instance(TrainingAssignmentProvider::class, new class($employee) extends TrainingAssignmentProvider
        {
            public function __construct(private Employee $employee) {}

            public function candidates(string $asOf): array
            {
                return [['employee_profile_id' => $this->employee->id, 'employee_id' => '00123', 'first_name' => 'Synthetic', 'last_name' => 'Member', 'display_name' => 'Synthetic Member', 'rank' => 'Firefighter', 'shift' => 'A', 'term_label' => 'Current assignment', 'assignment_source' => 'transfer', 'assignment_id' => 'synthetic-assignment', 'as_of' => $asOf]];
            }
        });
        $grant = $this->grant();
        $this->withSession(['training_grants.'.$this->roster->id => $grant->id]);
        $this->getJson($this->url('candidates'))->assertOk()->assertJsonPath('members.0.identifier_hint', '0123')->assertDontSee('00123');
        $payload = ['employee_profile_id' => $employee->id, 'signature' => $this->signature(), 'client_submission_id' => (string) Str::uuid()];
        $this->postJson($this->url('attendance'), $payload)->assertOk()->assertJsonPath('recorded', true);
        $this->postJson($this->url('attendance'), $payload)->assertOk();
        $this->assertDatabaseCount('training_attendances', 1);
        $state = $this->getJson($this->url('state'))->assertOk()->assertJsonCount(1, 'attendance')->assertJsonPath('attendance.0.name', 'Synthetic Member');
        $state->assertDontSee('training-rosters/signatures')->assertDontSee('sha256')->assertDontSee('data:image')->assertDontSee('00123');
        $this->roster->update(['status' => 'closed']);
        $this->postJson($this->url('attendance'), $payload)->assertOk();
        $this->postJson($this->url('receipt'), ['client_submission_id' => $payload['client_submission_id']])->assertOk()->assertJsonPath('recorded', true);
        $this->postJson($this->url('attendance'), array_replace($payload, ['client_submission_id' => (string) Str::uuid()]))->assertUnprocessable();
    }

    public function test_authorization_exchange_requires_csrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson($this->url('authorize'), ['token' => str_repeat('a', 64)])->assertStatus(419);
    }

    public function test_staff_launch_get_is_read_only_and_viewer_cannot_issue(): void
    {
        $this->actingAs($this->instructor)->get('/training-rosters/'.$this->roster->id.'/launch')->assertOk();
        $this->assertDatabaseCount('training_kiosk_grants', 0);
        Role::findOrCreate('training_viewer', 'web');
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $viewer->assignRole('training_viewer');
        $this->actingAs($viewer)->post('/training-rosters/'.$this->roster->id.'/launch')->assertForbidden();
        $this->assertDatabaseCount('training_kiosk_grants', 0);
    }

    public function test_certified_and_archived_classroom_links_can_be_revoked_but_not_issued(): void
    {
        $this->actingAs($this->instructor);
        foreach (['certified', 'archived'] as $status) {
            $grant = $this->grant();
            $this->roster->update(['status' => $status]);
            $launch = '/training-rosters/'.$this->roster->id.'/launch';
            $this->get($launch)->assertOk()->assertSee('End all classroom links')->assertDontSee('Get classroom sign-in link');
            $this->post($launch)->assertForbidden();
            $this->post('/training-rosters/'.$this->roster->id.'/revoke')->assertRedirect($launch);
            self::assertNotNull($grant->fresh()->revoked_at);
        }
        $this->assertDatabaseCount('training_kiosk_grants', 2);
    }

    private function url(string $suffix): string
    {
        return 'https://roster.mbfdhub.com/r/'.$this->roster->public_id.'/'.$suffix;
    }

    private function grant(): TrainingKioskGrant
    {
        return TrainingKioskGrant::query()->create(['training_session_id' => $this->roster->id, 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addHour(), 'created_by' => $this->instructor->id]);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(500, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 10, 20, 40);
        imagesetthickness($image, 5);
        for ($x = 30; $x < 450; $x += 30) {
            imageline($image, $x, 130, $x + 20, 40, $ink);
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
