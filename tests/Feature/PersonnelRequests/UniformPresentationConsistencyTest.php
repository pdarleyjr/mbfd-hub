<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages\ViewPersonnelRequest;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Models\Employee;
use App\Models\PersonnelRequestItem;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class UniformPresentationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_builder_refuses_items_it_does_not_offer_even_if_the_browser_sends_them(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-TAMPER');
        $builder = $this->builder($employee);

        $builder->set('data.items.work_boots', ['item_code' => 'work_boots', 'quantity' => 1, 'metadata' => ['size' => '10']])
            ->call('submit')
            ->assertHasErrors('data.items');

        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public function test_offered_items_still_submit_normally(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-NORMAL');
        $builder = $this->builder($employee);

        $builder->set('data.items.tie.quantity', 1)->call('submit')->assertHasNoErrors();

        $this->assertDatabaseCount('personnel_requests', 1);
        $this->assertNotNull($builder->get('submittedRequestNumber'));
    }

    public function test_recent_requests_refresh_after_a_submission(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-RECENT');
        $builder = $this->builder($employee);
        $builder->assertViewHas('recentRequests', fn ($requests): bool => $requests->isEmpty());

        $builder->set('data.items.tie.quantity', 1)->call('submit');

        $builder->assertViewHas('recentRequests', fn ($requests): bool => $requests->count() === 1)
            ->assertSee('hub-tag', false);
    }

    public function test_status_tone_is_shared_by_member_and_admin_surfaces(): void
    {
        $this->assertSame('hub-tag hub-tag--success', PersonnelRequestStatus::Completed->tagClass());
        $this->assertSame('success', PersonnelRequestStatus::Completed->filamentColor());
        $this->assertSame('hub-tag hub-tag--warning', PersonnelRequestStatus::NeedsInformation->tagClass());
        $this->assertSame('hub-tag hub-tag--danger', PersonnelRequestStatus::Denied->tagClass());
        $this->assertSame('hub-tag hub-tag--info', PersonnelRequestStatus::ReadyForPickup->tagClass());
        $this->assertSame('hub-tag', PersonnelRequestStatus::Pending->tagClass());
        $this->assertSame('gray', PersonnelRequestStatus::Pending->filamentColor());
    }

    public function test_member_pages_show_status_with_the_shared_tag(): void
    {
        $this->withoutVite();
        $employee = $this->employee('UNIFORM-TAGS');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [
            ['item_code' => 'tie', 'metadata' => [], 'quantity' => 1],
        ], 'uniform-tags-1');
        $updated = DB::table('personnel_requests')->where('id', $request->id)->update(['status' => PersonnelRequestStatus::NeedsInformation->value]);
        $this->assertSame(1, $updated);

        $this->actingAs($employee, 'employee');
        $this->get('/employee/my-requests')->assertOk()
            ->assertSee('class="hub-tag hub-tag--warning"', false)->assertSee('Needs Information');
        $this->get('/employee/my-requests/'.$request->public_id)->assertOk()
            ->assertSee('class="hub-tag hub-tag--warning" data-request-status', false)
            ->assertSee('Awaiting arrival');
    }

    public function test_member_item_progress_reflects_recorded_quantities(): void
    {
        $item = new PersonnelRequestItem(['quantity' => 3, 'arrived_quantity' => 0, 'fulfilled_quantity' => 0, 'fulfillment_status' => 'pending']);
        $this->assertSame('Awaiting arrival', $item->memberProgressLabel());
        $item->fulfillment_status = 'acknowledged';
        $this->assertSame('Acknowledged', $item->memberProgressLabel());
        $item->arrived_quantity = 1;
        $this->assertSame('Partially arrived', $item->memberProgressLabel());
        $item->arrived_quantity = 3;
        $this->assertSame('Arrived', $item->memberProgressLabel());
        $this->assertSame('hub-tag hub-tag--info', $item->memberProgressTagClass());
        $item->fulfilled_quantity = 1;
        $this->assertSame('Partially issued', $item->memberProgressLabel());
        $item->fulfilled_quantity = 3;
        $this->assertSame('Issued', $item->memberProgressLabel());
        $this->assertSame('hub-tag hub-tag--success', $item->memberProgressTagClass());
    }

    public function test_inventory_choices_list_matching_stock_first_and_keep_everything_available(): void
    {
        $stock = [
            ['id' => 1, 'label' => 'Navy Polo — L — 4 on hand', 'name' => 'Navy Polo', 'size' => 'L'],
            ['id' => 2, 'label' => '5.11 Tactical Pants — 34x32 — 2 on hand', 'name' => '5.11 Tactical Pants', 'size' => '34x32'],
            ['id' => 3, 'label' => '5.11 Tactical Pants — 36x32 — 1 on hand', 'name' => '5.11 Tactical Pants', 'size' => '36x32'],
            ['id' => 4, 'label' => 'JUKMO Work Belt — M — 5 on hand', 'name' => 'JUKMO Work Belt', 'size' => 'M'],
        ];

        $grouped = ViewPersonnelRequest::groupInventoryChoices($stock, '5.11 Tactical Pants', '34x32');

        $this->assertSame(['Matches item and size', 'Matches item', 'Other stock'], array_keys($grouped));
        $this->assertSame([2], array_keys($grouped['Matches item and size']));
        $this->assertSame([3], array_keys($grouped['Matches item']));
        $this->assertEqualsCanonicalizing([1, 4], array_keys($grouped['Other stock']));

        $ungrouped = ViewPersonnelRequest::groupInventoryChoices($stock, null, null);
        $this->assertSame([1, 2, 3, 4], array_keys($ungrouped));
    }

    private function builder(Employee $employee): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($employee, 'employee');
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        return Livewire::test(RequestEquipmentPage::class);
    }

    private function employee(string $number): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $number, 'name' => 'Uniform Member '.$number,
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
        User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);

        return $employee;
    }
}
