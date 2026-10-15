<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingRosterService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingPublicRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setTimezone('America/New_York')->setDate(2026, 10, 15)->setTime(10, 12, 34));
        config(['identity.local_login_enabled' => true]);
        $employee = Employee::query()->create(['employee_id' => 'PUBLIC-QA-1', 'name' => 'Synthetic Approver', 'roster_status' => 'active', 'must_change_password' => false]);
        $this->approver = User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active, 'must_change_password' => false,
            'password' => Hash::make('synthetic-roster-password'),
        ]);
        Role::findOrCreate('super_admin', 'web');
        $this->approver->assignRole('super_admin');
        $this->course = TrainingCourse::query()->create([
            'name' => 'Synthetic recurring course', 'classifications' => ['EMS Training'],
            'default_location' => 'Synthetic classroom', 'created_by' => $this->approver->id,
            'description' => 'Private instructor preparation notes',
        ]);
    }

    public function test_public_catalog_lists_courses_and_only_open_dates_without_private_data(): void
    {
        $this->course->update(['classifications' => ['STC', 'Custom classification']]);
        $open = $this->start();
        foreach (['draft', 'closed', 'submitted', 'certified', 'archived'] as $status) {
            $other = $open->replicate();
            $other->public_id = (string) Str::ulid();
            $other->status = $status;
            $other->save();
        }
        $response = $this->getJson($this->url('/catalog'))->assertOk()
            ->assertJsonCount(1, 'courses')->assertJsonCount(1, 'courses.0.sessions')
            ->assertJsonPath('courses.0.sessions.0.public_id', $open->public_id)
            ->assertJsonPath('courses.0.classifications', ['STC', 'Custom classification'])
            ->assertJsonPath('today', '2026-10-15');
        self::assertSame(['id', 'name', 'default_location', 'classifications', 'sessions'], array_keys($response->json('courses.0')));
        self::assertSame(['public_id', 'held_on', 'location'], array_keys($response->json('courses.0.sessions.0')));
        $response->assertDontSee('Private instructor preparation notes')->assertDontSee('Synthetic Approver')->assertDontSee('PUBLIC-QA-1');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_public_start_reuses_today_and_retains_previous_dates(): void
    {
        $first = $this->start();
        self::assertSame($first->id, $this->start()->id);
        self::assertNull($first->created_by);
        $this->travel(1)->days();
        $second = $this->start();
        self::assertNotSame($first->id, $second->id);
        self::assertSame('2026-10-15', $first->held_on->toDateString());
        self::assertSame('2026-10-16', $second->held_on->toDateString());
        $this->assertDatabaseCount('training_sessions', 2);
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_multiple_open_classes_require_explicit_selection_instead_of_guessing(): void
    {
        $first = $this->start();
        $other = $first->replicate();
        $other->public_id = (string) Str::ulid();
        $other->location = 'Another classroom';
        $other->save();
        $this->postJson($this->url('/courses/'.$this->course->id.'/start'), [])->assertUnprocessable()->assertJsonValidationErrors('session');
        $this->postJson($this->url('/r/'.$other->public_id.'/open'), [])->assertOk();
        $this->assertDatabaseCount('training_sessions', 2);
    }

    public function test_submission_requires_fresh_credentials_locks_snapshot_and_recovers_same_receipt(): void
    {
        $roster = $this->start();
        $attendance = $this->attendance($roster);
        $payload = $this->approval($roster);
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload + ['unused' => 'ignored'])
            ->assertOk()->assertJsonPath('submitted', true)->assertJsonPath('held_on', '2026-10-15');
        $roster->refresh();
        self::assertSame('submitted', $roster->status);
        self::assertSame($this->approver->id, $roster->submitted_by);
        $snapshot = $roster->latestVersion->snapshot;
        self::assertSame($attendance->signed_at->toIso8601String(), $snapshot['attendance'][0]['signed_at']);
        self::assertSame($attendance->signature_signed_at->toIso8601String(), $snapshot['attendance'][0]['signature_signed_at']);
        self::assertNull($snapshot['certification']);
        self::assertSame($this->approver->id, $snapshot['submission']['submitted_by']);
        $versionCount = $roster->versions()->count();
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertOk();
        self::assertSame($versionCount, $roster->versions()->count());
        self::assertSame(1, $roster->audits()->where('action', 'session.submitted')->count());
        self::assertFalse(Auth::guard('web')->check());
        self::assertArrayNotHasKey('password', session()->all());
        $this->getJson($this->url('/r/'.$roster->public_id.'/state'))->assertForbidden();
        $this->getJson($this->url('/r/'.$roster->public_id.'/candidates'))->assertConflict();
        $this->postJson($this->url('/r/'.$roster->public_id.'/open'), [])->assertForbidden();
        $this->getJson($this->url('/catalog'))->assertOk()->assertJsonCount(0, 'courses.0.sessions');
        $next = $this->start();
        self::assertNotSame($roster->id, $next->id);
        self::assertSame(0, $next->attendances()->count());
        self::assertSame($snapshot, $roster->latestVersion->fresh()->snapshot);
    }

    public function test_wrong_password_and_missing_permission_do_not_submit_or_create_a_login(): void
    {
        $roster = $this->start();
        $this->attendance($roster);
        $payload = $this->approval($roster);
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), array_replace($payload, ['password' => 'incorrect']))
            ->assertUnprocessable()->assertJsonValidationErrors('credentials');
        $this->approver->removeRole('super_admin');
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('credentials');
        self::assertSame('open', $roster->fresh()->status);
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_empty_or_changed_roster_cannot_be_submitted(): void
    {
        $roster = $this->start();
        $payload = $this->approval($roster);
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertUnprocessable()->assertJsonValidationErrors('attendance');
        $this->attendance($roster);
        $roster->increment('revision');
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        self::assertSame('open', $roster->fresh()->status);
    }

    public function test_revoked_grant_is_rechecked_at_the_write_boundary(): void
    {
        $roster = $this->start();
        $this->attendance($roster);
        $grant = $roster->kioskGrants()->firstOrFail();
        $grant->update(['revoked_at' => now()]);
        try {
            app(TrainingRosterService::class)->submit($roster, $this->approver, $roster->revision, (string) Str::uuid(), $grant->id);
            self::fail('Revoked kiosk grant submitted a roster.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame('open', $roster->fresh()->status);
    }

    public function test_submitted_record_requires_reason_to_reopen_and_keeps_the_approved_snapshot(): void
    {
        $roster = $this->start();
        $this->attendance($roster);
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $this->approval($roster))->assertOk();
        $roster->refresh();
        $savedVersion = $roster->latestVersion;
        try {
            app(TrainingRosterService::class)->transition($roster, 'closed', $this->approver);
            self::fail('Submitted record reopened without a reason.');
        } catch (ValidationException) {
            self::assertSame('submitted', $roster->fresh()->status);
        }
        $reopened = app(TrainingRosterService::class)->transition($roster, 'closed', $this->approver, 'Synthetic correction requested');
        self::assertNull($reopened->submitted_at);
        self::assertSame('submitted', $savedVersion->fresh()->snapshot['session']['status']);
        self::assertNotNull($savedVersion->snapshot['submission']);
    }

    public function test_public_mutations_require_csrf(): void
    {
        $roster = $this->start();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        foreach (['/courses/'.$this->course->id.'/start', '/r/'.$roster->public_id.'/open', '/r/'.$roster->public_id.'/submit'] as $path) {
            $this->postJson($this->url($path), [])->assertStatus(419);
        }
    }

    public function test_public_entry_upgrades_to_https_before_rendering_credentials(): void
    {
        $this->get('http://rosters.mbfdhub.com/')->assertStatus(308)->assertRedirect('https://rosters.mbfdhub.com/');
    }

    public function test_submission_validation_never_flashes_credentials_for_html_clients(): void
    {
        $roster = $this->start();
        $this->post($this->url('/r/'.$roster->public_id.'/submit'), ['email' => 'PUBLIC-QA-1', 'password' => 'incorrect'], ['Accept' => 'text/html'])
            ->assertUnprocessable()->assertJsonValidationErrors(['expected_revision', 'client_submission_id'])
            ->assertSessionMissing('_old_input');
        $this->post($this->url('/r/'.$roster->public_id.'/submit'), array_replace($this->approval($roster), ['password' => 'incorrect']), ['Accept' => 'text/html'])
            ->assertUnprocessable()->assertJsonValidationErrors('credentials')->assertSessionMissing('_old_input');
        self::assertFalse(Auth::guard('web')->check());
    }

    private function start(): TrainingSession
    {
        $response = $this->postJson($this->url('/courses/'.$this->course->id.'/start'), [])->assertOk()->assertJsonStructure(['url', 'csrf_token']);

        return TrainingSession::query()->where('public_id', substr($response->json('url'), 3))->firstOrFail();
    }

    private function approval(TrainingSession $roster): array
    {
        return ['email' => 'PUBLIC-QA-1', 'password' => 'synthetic-roster-password', 'expected_revision' => $roster->revision, 'client_submission_id' => (string) Str::uuid()];
    }

    private function attendance(TrainingSession $roster): TrainingAttendance
    {
        return $roster->attendances()->create([
            'employee_profile_id' => $this->approver->employee_profile_id,
            'snapshot' => ['display_name' => 'Synthetic Approver', 'employee_id' => 'PUBLIC-QA-1'],
            'signature' => ['disk' => 'local', 'path' => 'synthetic-test-only', 'mime' => 'image/png', 'sha256' => hash('sha256', 'synthetic')],
            'signed_at' => now()->utc(), 'signature_signed_at' => now()->utc(),
            'client_submission_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'synthetic'),
        ]);
    }

    private function url(string $path): string
    {
        return 'https://rosters.mbfdhub.com'.$path;
    }
}
