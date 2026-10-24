<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Station;
use App\Models\StationInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/** New-submission alerts (AppServiceProvider model hooks) go to people who are eligible NOW, never to saved rows. */
final class SubmissionAlertRecipientsTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    private function submitStationInspection(): StationInspection
    {
        $station = Station::query()->firstOrCreate(['station_number' => 1], ['name' => 'Station 1', 'address' => '1051 Jefferson Avenue', 'is_active' => true]);

        return StationInspection::create([
            'station_id' => $station->id,
            'inspector_id' => $this->member()->id,
            'inspection_date' => now()->toDateString(),
            'inspection_type' => 'annual',
            'overall_status' => 'pass',
            'form_data' => [],
        ]);
    }

    private function inspectionReviewer(): User
    {
        return $this->withPermissions($this->member(), 'admin.access', 'admin.stations.view');
    }

    public function test_an_eligible_person_with_no_saved_row_gets_the_in_app_alert(): void
    {
        $eligible = $this->inspectionReviewer();

        $inspection = $this->submitStationInspection();

        $this->assertSame(0, $eligible->notificationSubscriptions()->count(), 'nothing was ever saved for this person');
        $alert = $eligible->notifications()->sole();
        $this->assertSame('New Station Inspection Submitted', $alert->data['title']);
        $this->assertSame('station_inspections', $alert->data['provenance']['category']);
        $this->assertStringContainsString('station_inspection:'.$inspection->id.':created', (string) $alert->data['provenance']['event_key']);
    }

    public function test_an_ineligible_person_with_an_old_enabled_row_is_never_told(): void
    {
        $outsider = $this->member();
        $outsider->notificationSubscriptions()->create(['event_key' => 'station_inspections', 'database_enabled' => true, 'webpush_enabled' => true, 'email_enabled' => true]);
        $eligible = $this->inspectionReviewer();

        $this->submitStationInspection();

        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(1, $eligible->notifications()->count());
    }

    public function test_an_eligible_person_can_still_turn_the_alert_off(): void
    {
        $eligible = $this->inspectionReviewer();
        $eligible->notificationSubscriptions()->create(['event_key' => 'station_inspections', 'database_enabled' => false, 'webpush_enabled' => false, 'email_enabled' => false]);

        $this->submitStationInspection();

        $this->assertSame(0, $eligible->notifications()->count(), 'a saved choice can only narrow');
    }

    public function test_a_permission_revoked_before_the_next_submission_removes_the_person(): void
    {
        $eligible = $this->inspectionReviewer();
        $this->submitStationInspection();
        $this->assertSame(1, $eligible->notifications()->count());

        $eligible->revokePermissionTo('admin.stations.view');
        $this->resetPermissionCache();
        $this->submitStationInspection();

        $this->assertSame(1, $eligible->fresh()->notifications()->count(), 'no second alert after losing access');
    }
}
