<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\FanOutNotification;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\InspectionMeterBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The SQLite-capable twin of the PostgreSQL lock test: the opt-in "every Daily Checkout" fan-out is queued
 * once for a committed submission, never for a rolled-back one, and processing a routine accepted
 * checkout (including one whose meter was overtaken) queues nothing more.
 */
final class DailyCheckoutFanOutQueueingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Apparatus
    {
        return Apparatus::create(['name' => 'Fan-out fixture', 'unit_id' => 'FAN-'.uniqid(), 'type' => 'Engine', 'status' => 'In Service', 'current_engine_hours' => 100, 'current_miles' => 1000]);
    }

    /** @return array<string, mixed> */
    private function submission(Apparatus $apparatus): array
    {
        return ['apparatus_id' => $apparatus->id, 'operator_name' => 'Fan-out fixture', 'rank' => 'Firefighter', 'review_status' => 'pending_review', 'engine_hours' => 101, 'miles' => 1001, 'results' => []];
    }

    public function test_a_committed_submission_queues_one_fan_out_and_processing_queues_nothing_more(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $apparatus = $this->fixture();
        $inspection = ApparatusInspection::create($this->submission($apparatus));
        $token = app(InspectionMeterBaseline::class)->issue($apparatus);

        Queue::assertPushed(FanOutNotification::class, 1);
        Queue::assertPushed(FanOutNotification::class, fn (FanOutNotification $job): bool => $job->categoryKey === User::NOTIFICATION_PREFERENCE_DAILY_CHECKOUT_EVERY_SUBMISSION);

        // Another reading overtakes the form's baseline before processing: superseded, accepted, nothing held.
        Queue::fake();
        DB::table('apparatuses')->where('id', $apparatus->id)->update(['current_engine_hours' => 102, 'current_miles' => 1002]);
        DB::transaction(function () use ($apparatus, $inspection, $token): void {
            $locked = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->id);
            app(ApparatusInspectionProcessingService::class)->process($inspection, $locked, $token);
        });

        $this->assertSame('accepted', $inspection->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_a_rolled_back_submission_queues_no_fan_out(): void
    {
        Queue::fake();
        $apparatus = $this->fixture();

        try {
            DB::transaction(function () use ($apparatus): void {
                ApparatusInspection::create($this->submission($apparatus));

                throw new RuntimeException('the submission fails after the row is written');
            });
            $this->fail('The transaction must roll back.');
        } catch (RuntimeException) {
            // expected
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, ApparatusInspection::query()->count());
    }
}
