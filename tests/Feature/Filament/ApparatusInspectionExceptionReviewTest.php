<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApparatusInspectionExceptionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_can_compare_and_correct_a_meter_from_the_exception_queue(): void
    {
        $exception = $this->fixture();
        $reviewer = User::factory()->create();
        $reviewer->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $this->actingAs($reviewer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
        Queue::fake();

        Livewire::test(ListInspectionExceptions::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$exception])
            ->callTableAction('reconcile', $exception, data: [
                'action' => 'correct', 'value' => 101, 'reason' => 'Rechecked the dashboard display.', 'expected_current_value' => 100,
            ])->assertHasNoTableActionErrors();

        $this->assertSame('resolved', $exception->fresh()->status);
        $this->assertEquals(101, $exception->apparatus->fresh()->current_engine_hours);
        $this->assertEquals(90, $exception->inspection->fresh()->engine_hours);
        $this->assertDatabaseHas('apparatus_inspection_review_events', ['changed_by_user_id' => $reviewer->id, 'internal_note' => 'Rechecked the dashboard display.']);
    }

    public function test_ordinary_member_cannot_open_exception_queue(): void
    {
        $this->fixture();
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ListInspectionExceptions::class)->assertForbidden();
    }

    private function fixture(): ApparatusInspectionException
    {
        $apparatus = Apparatus::create(['name' => 'Test Engine', 'unit_id' => 'TEST-E2', 'type' => 'Engine', 'vehicle_number' => 'TEST-102', 'status' => 'In Service', 'current_engine_hours' => 100]);
        $inspection = ApparatusInspection::create(['apparatus_id' => $apparatus->id, 'operator_name' => 'Test Member', 'rank' => 'Firefighter', 'vehicle_number' => 'TEST-102', 'engine_hours' => 90, 'completed_at' => now(), 'review_status' => 'approved', 'processing_status' => 'accepted_with_exception']);

        return ApparatusInspectionException::create(['apparatus_id' => $apparatus->id, 'apparatus_inspection_id' => $inspection->id, 'field' => 'engine_hours', 'reason' => 'rollback', 'submitted_value' => 90, 'baseline_value' => 100, 'authoritative_value' => 100]);
    }
}
