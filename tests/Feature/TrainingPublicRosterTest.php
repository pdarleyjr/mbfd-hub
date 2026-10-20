<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ViewTrainingSession;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingRosterService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingPublicRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private TrainingCourse $course;

    private Employee $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['filesystems.private' => 'local']);
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
        $this->lead = Employee::query()->create(['employee_id' => 'PUBLIC-LEAD-2', 'name' => 'Synthetic Different Lead', 'roster_status' => 'active']);
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
        self::assertSame(['id', 'name', 'default_location', 'classifications', 'requires_session_selection', 'modules', 'sessions'], array_keys($response->json('courses.0')));
        self::assertSame(['public_id', 'held_on', 'location', 'starts_at', 'module_id', 'module_name', 'staff_managed'], array_keys($response->json('courses.0.sessions.0')));
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
        self::assertSame($this->lead->id, $roster->oic_employee_profile_id);
        self::assertSame($this->lead->id, $snapshot['instructor_signoff']['lead_snapshot']['employee_profile_id']);
        self::assertSame($this->approver->id, $snapshot['instructor_signoff']['recorded_by']);
        self::assertSame(now()->utc()->toIso8601String(), $snapshot['instructor_signoff']['signed_at']);
        Storage::disk('local')->assertExists($snapshot['instructor_signoff']['signature']['path']);
        self::assertNull($this->lead->user);
        $versionCount = $roster->versions()->count();
        $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertOk();
        self::assertSame($versionCount, $roster->versions()->count());
        self::assertSame(1, $roster->audits()->where('action', 'session.submitted')->count());
        self::assertFalse(Auth::guard('web')->check());
        self::assertArrayNotHasKey('password', session()->all());
        $this->getJson($this->url('/r/'.$roster->public_id.'/state'))->assertForbidden();
        $this->getJson($this->url('/r/'.$roster->public_id.'/candidates'))->assertConflict();
        $this->getJson($this->url('/r/'.$roster->public_id.'/leads'))->assertForbidden();
        $this->postJson($this->url('/r/'.$roster->public_id.'/open'), [])->assertForbidden();
        $this->getJson($this->url('/catalog'))->assertOk()->assertJsonCount(0, 'courses.0.sessions');
        $next = $this->start();
        self::assertNotSame($roster->id, $next->id);
        self::assertSame(0, $next->attendances()->count());
        self::assertSame($snapshot, $roster->latestVersion->fresh()->snapshot);
        Filament::setCurrentPanel(Filament::getPanel('training'));
        $this->actingAs($this->approver);
        Livewire::test(ViewTrainingSession::class, ['record' => $roster->id])
            ->assertSee('Synthetic Different Lead')->assertSee('Recorded with confirmation by submitting account');
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
        self::assertNotNull($savedVersion->snapshot['instructor_signoff']);
        self::assertNull($reopened->latestVersion->snapshot['instructor_signoff']);
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

    public function test_lead_directory_is_safe_session_scoped_and_keeps_preset_selection(): void
    {
        $roster = $this->start();
        $roster->update(['oic_employee_profile_id' => $this->approver->employee_profile_id]);
        Employee::query()->create(['employee_id' => 'INACTIVE-LEAD', 'name' => 'Hidden Inactive Lead', 'roster_status' => 'departed']);
        $response = $this->getJson($this->url('/r/'.$roster->public_id.'/leads'))->assertOk()
            ->assertJsonCount(2, 'leads')->assertJsonPath('selected_lead_id', $this->approver->employee_profile_id);
        self::assertSame(['id', 'name', 'identifier_hint'], array_keys($response->json('leads.0')));
        $response->assertDontSee('PUBLIC-QA-1')->assertDontSee('PUBLIC-LEAD-2')->assertDontSee('Hidden Inactive Lead')->assertDontSee('super_admin');
        $roster->kioskGrants()->update(['revoked_at' => now()]);
        $this->getJson($this->url('/r/'.$roster->public_id.'/leads'))->assertForbidden();
    }

    public function test_missing_invalid_or_unconfirmed_lead_signoff_never_changes_roster_or_preset(): void
    {
        $roster = $this->start();
        $roster->update(['oic_employee_profile_id' => $this->approver->employee_profile_id]);
        $this->attendance($roster);
        $versionCount = $roster->versions()->count();
        foreach (['missing_lead', 'missing_ink', 'blank', 'malformed', 'inactive', 'unconfirmed', 'missing_confirmation'] as $case) {
            $this->lead->update(['roster_status' => $case === 'inactive' ? 'departed' : 'active']);
            $payload = $this->approval($roster);
            $field = 'lead_signature';
            if ($case === 'missing_lead') {
                unset($payload['lead_employee_profile_id']);
                $field = 'lead_employee_profile_id';
            } elseif ($case === 'missing_ink') {
                unset($payload['lead_signature']);
            } elseif ($case === 'blank') {
                $payload['lead_signature'] = $this->signature(blank: true);
            } elseif ($case === 'malformed') {
                $payload['lead_signature'] = 'not-a-signature';
            } elseif ($case === 'inactive') {
                $field = 'lead_employee_profile_id';
            } else {
                $payload['lead_confirmed'] = false;
                if ($case === 'missing_confirmation') {
                    unset($payload['lead_confirmed']);
                }
                $field = 'lead_confirmed';
            }
            $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            self::assertSame('open', $roster->fresh()->status);
            self::assertSame($this->approver->employee_profile_id, $roster->fresh()->oic_employee_profile_id);
            self::assertSame($versionCount, $roster->versions()->count());
        }
        self::assertSame([], Storage::disk('local')->allFiles('training-rosters'));
    }

    public function test_reload_recovery_needs_no_ink_but_only_same_admin_and_changed_payload_is_rejected(): void
    {
        $roster = $this->start();
        $this->attendance($roster);
        $payload = $this->approval($roster);
        $path = $this->url('/r/'.$roster->public_id.'/submit');
        $this->postJson($path, $payload)->assertOk();
        $before = $roster->fresh()->latestVersion->snapshot;
        foreach ([['lead_employee_profile_id' => $this->approver->employee_profile_id], ['lead_signature' => $this->signature(offset: 8)], ['expected_revision' => $roster->revision + 1]] as $change) {
            $this->postJson($path, array_replace($payload, $change))->assertUnprocessable()->assertJsonValidationErrors('client_submission_id');
        }
        $recovery = array_diff_key($payload, array_flip(['lead_employee_profile_id', 'lead_signature', 'lead_confirmed'])) + ['recovery_only' => true];
        $this->postJson($path, $recovery)->assertOk()->assertJsonPath('lead.id', $this->lead->id)->assertJsonPath('lead.name', $this->lead->name);
        $this->postJson($path, array_replace($recovery, ['client_submission_id' => (string) Str::uuid()]))->assertStatus(409)->assertJsonMissingValidationErrors('needs_signoff');
        $other = User::factory()->create(['employee_id' => $this->lead->employee_id, 'employee_profile_id' => $this->lead->id,
            'account_status' => 'active', 'must_change_password' => false, 'password' => 'other-synthetic-password']);
        $other->assignRole('super_admin');
        $this->postJson($path, array_replace($recovery, ['email' => $this->lead->employee_id, 'password' => 'other-synthetic-password']))->assertForbidden();
        self::assertSame($before, $roster->fresh()->latestVersion->snapshot);
        self::assertSame(1, count(Storage::disk('local')->allFiles('training-rosters')));
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_unknown_recovery_does_not_submit_and_same_uuid_can_collect_required_signoff(): void
    {
        $roster = $this->start();
        $this->attendance($roster);
        $payload = $this->approval($roster);
        $recovery = array_diff_key($payload, array_flip(['lead_employee_profile_id', 'lead_signature', 'lead_confirmed'])) + ['recovery_only' => true];
        $path = $this->url('/r/'.$roster->public_id.'/submit');
        $this->postJson($path, $recovery)->assertUnprocessable()->assertJsonValidationErrors('needs_signoff');
        self::assertSame('open', $roster->fresh()->status);
        self::assertSame([], Storage::disk('local')->allFiles('training-rosters'));
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('lead.id', $this->lead->id);
    }

    public function test_archive_and_separate_self_certification_preserve_witnessed_lead_signoff(): void
    {
        foreach ([false, true] as $certify) {
            $roster = $this->start();
            $this->attendance($roster);
            $payload = $this->approval($roster);
            $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $payload)->assertOk();
            $roster->refresh();
            $signoff = $roster->latestVersion->snapshot['instructor_signoff'];
            $service = app(TrainingRosterService::class);
            if ($certify) {
                $version = $service->certify($roster, $this->approver, $this->approver->employee_profile_id, $this->signature(offset: 5), $roster->revision);
                self::assertSame($signoff, $version->snapshot['instructor_signoff']);
                self::assertSame($this->approver->employee_profile_id, $version->snapshot['certification']['oic_snapshot']['employee_profile_id']);
            }
            $archived = $service->transition($roster->fresh(), 'archived', $this->approver);
            self::assertSame($signoff, $archived->latestVersion->snapshot['instructor_signoff']);
            self::assertSame($certify, $archived->latestVersion->snapshot['certification'] !== null);
            $recovery = array_diff_key($payload, array_flip(['lead_employee_profile_id', 'lead_signature', 'lead_confirmed'])) + ['recovery_only' => true];
            $this->postJson($this->url('/r/'.$roster->public_id.'/submit'), $recovery)->assertOk()->assertJsonPath('lead.id', $this->lead->id);
        }
    }

    private function start(): TrainingSession
    {
        $response = $this->postJson($this->url('/courses/'.$this->course->id.'/start'), [])->assertOk()->assertJsonStructure(['url', 'csrf_token']);

        return TrainingSession::query()->where('public_id', substr($response->json('url'), 3))->firstOrFail();
    }

    private function approval(TrainingSession $roster): array
    {
        return ['email' => 'PUBLIC-QA-1', 'password' => 'synthetic-roster-password', 'expected_revision' => $roster->revision, 'client_submission_id' => (string) Str::uuid(),
            'lead_employee_profile_id' => $this->lead->id, 'lead_signature' => $this->signature(), 'lead_confirmed' => true];
    }

    private function signature(bool $blank = false, int $offset = 0): string
    {
        $image = imagecreatetruecolor(500, 180);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if (! $blank) {
            $ink = imagecolorallocate($image, 10, 20, 40);
            imagesetthickness($image, 5);
            for ($x = 30; $x < 450; $x += 30) {
                imageline($image, $x, 130, $x + 20 + $offset, 40, $ink);
            }
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
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
