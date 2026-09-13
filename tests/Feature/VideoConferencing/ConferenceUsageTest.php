<?php

declare(strict_types=1);

namespace Tests\Feature\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Enums\VideoConferencing\ConferenceRoomType;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConferenceUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_is_labeled_estimated_and_counts_only_joined_participation_lifecycle(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'F099',
            'name' => 'Usage Test',
            'password' => 'not-used',
            'must_change_password' => false,
        ]);
        $session = VideoConferenceSession::query()->create([
            'type' => ConferenceRoomType::Lineup,
            'logical_key' => 'lineup:2026-08-19',
            'livekit_profile' => 'cloud',
            'livekit_room_name' => 'usage-test-room',
            'created_by_employee_id' => $employee->id,
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
            'provisioned_at' => now()->subMinutes(10),
        ]);
        VideoConferenceParticipation::query()->create([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
            'participant_identity' => 'mbfd:member:'.$employee->id,
            'join_as' => ConferenceJoinRole::Self,
            'display_name' => 'Usage Test',
            'token_issued_at' => now()->subMinutes(10),
            'joined_at' => now()->subMinutes(8),
            'left_at' => now()->subMinutes(3),
            'downstream_bytes' => 1_500_000_000,
        ]);
        VideoConferenceParticipation::query()->create([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
            'participant_identity' => 'mbfd:member:never-joined',
            'join_as' => ConferenceJoinRole::Self,
            'display_name' => 'Never Joined',
            'token_issued_at' => now()->subMinutes(9),
        ]);

        $usage = app(ConferenceUsageService::class)->monthlyEstimate();

        $this->assertSame(5.0, $usage['participant_minutes_estimated']);
        $this->assertSame(1_500_000_000, $usage['downstream_bytes_estimated']);
        $this->assertSame(1.5, $usage['downstream_gb_estimated']);
        $this->assertSame('normal', $usage['band']);
        $this->assertStringStartsWith('Estimated', $usage['estimate_label']);
        $this->assertSame(5000, $usage['participant_minutes_allowance']);
        $this->assertSame(4995.0, $usage['participant_minutes_remaining']);
        $this->assertSame(50, $usage['downstream_allowance_gb']);
        $this->assertSame(48.5, $usage['downstream_gb_remaining']);
        $this->assertSame(\Carbon\CarbonImmutable::now('UTC')->startOfMonth()->addMonth()->toIso8601String(), $usage['resets_at']);

        config(['video-conferencing.usage.webrtc_minutes_allowance' => 3, 'video-conferencing.usage.downstream_allowance_gb' => 1]);
        $limited = app(ConferenceUsageService::class)->monthlyEstimate();
        $this->assertSame(0, $limited['participant_minutes_remaining']);
        $this->assertSame(0, $limited['downstream_gb_remaining']);

        $joined = VideoConferenceParticipation::query()->whereNotNull('joined_at')->firstOrFail();
        $month = \Carbon\CarbonImmutable::now('UTC')->startOfMonth();
        $joined->update(['token_issued_at' => $month->subMinutes(10), 'joined_at' => $month->subMinutes(5), 'left_at' => $month->addMinutes(2)]);
        $this->assertSame(2.0, app(ConferenceUsageService::class)->monthlyEstimate()['participant_minutes_estimated']);
    }
}
