<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestFulfillmentService;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use App\Services\PersonnelRequests\UniformEntitlementService;
use App\Services\UniformInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UniformJacketEntitlementTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('jacketStyles')]
    public function test_each_style_is_one_jacket_with_server_derived_name(string $style, string $label): void
    {
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $this->employee(), [$this->jacket($style) + ['item_name' => 'Untrusted browser label']], 'jacket-style-'.$style,
        );
        $item = $request->items->sole();

        $this->assertSame('jacket', $item->item_code);
        $this->assertSame($label, $item->item_name);
        $this->assertSame(1, $item->quantity);
        $this->assertSame('L', $item->size);
        $this->assertSame(['jacket_style' => $style, 'size' => 'L'], $item->metadata);
        $this->assertDatabaseCount('assigned_equipment', 0);
    }

    public static function jacketStyles(): array
    {
        return [['quarter_zip', '5.11 Quarter Zip'], ['softshell', '5.11 Softshell']];
    }

    #[DataProvider('invalidJackets')]
    public function test_invalid_styles_and_multiple_jackets_cannot_be_submitted(array $items, string $error): void
    {
        try {
            app(PersonnelRequestSubmissionService::class)->submitUniform($this->employee(), $items, 'jacket-invalid-'.Str::uuid());
            $this->fail('An invalid jacket order was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
        }
        $this->assertDatabaseCount('personnel_requests', 0);
    }

    public static function invalidJackets(): array
    {
        $structured = ['item_code' => 'jacket', 'quantity' => 1, 'metadata' => ['jacket_style' => 'quarter_zip', 'size' => 'L']];
        $legacy = ['item_code' => 'jacket', 'quantity' => 1, 'size' => 'L'];

        return [
            'removed vintage style' => [['jacket' => array_replace_recursive($structured, ['metadata' => ['jacket_style' => 'vintage']])], 'items.jacket.metadata.jacket_style'],
            'unknown style' => [['jacket' => array_replace_recursive($structured, ['metadata' => ['jacket_style' => 'unknown']])], 'items.jacket.metadata.jacket_style'],
            'missing style' => [['jacket' => ['item_code' => 'jacket', 'quantity' => 1, 'metadata' => ['size' => 'L']]], 'items.jacket.metadata.jacket_style'],
            'structured quantity two' => [['jacket' => array_replace($structured, ['quantity' => 2])], 'items.jacket.quantity'],
            'legacy quantity two' => [['legacy' => array_replace($legacy, ['quantity' => 2])], 'items.legacy.quantity'],
            'structured duplicate styles' => [['first' => $structured, 'second' => array_replace_recursive($structured, ['metadata' => ['jacket_style' => 'softshell']])], 'items.second.quantity'],
            'legacy duplicates' => [['first' => $legacy, 'second' => $legacy], 'items.second.quantity'],
            'mixed duplicates' => [['first' => $structured, 'second' => $legacy], 'items.second.quantity'],
            'boolean quantity' => [['legacy' => array_replace($legacy, ['quantity' => true])], 'items.legacy.quantity'],
        ];
    }

    public function test_no_recorded_issue_has_no_invented_date_and_legacy_submission_remains_supported(): void
    {
        $employee = $this->employee();
        $eligibility = app(UniformEntitlementService::class)->jacketEligibility($employee);

        $this->assertTrue($eligibility['can_order']);
        $this->assertNull($eligibility['last_issued_at']);
        $this->assertNull($eligibility['eligible_from']);
        $this->assertStringContainsString('off-system issue history', $eligibility['reason']);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [
            ['item_code' => 'jacket', 'quantity' => 1, 'size' => 'L'],
        ], 'jacket-legacy-valid');
        $this->assertSame('Department Jacket', $request->items->sole()->item_name);
        $this->assertNull($request->items->sole()->metadata);
    }

    #[DataProvider('issueStatuses')]
    public function test_recent_source_linked_issue_counts_even_when_returned_or_retired(string $status): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00'));
        $employee = $this->employee();
        $request = $this->issuedJacket($employee, '2025-11-01', $status);
        $request->update(['created_at' => '2021-01-01']);
        $eligibility = app(UniformEntitlementService::class)->jacketEligibility($employee);

        $this->assertFalse($eligibility['can_order']);
        $this->assertSame('2025-11-01', $eligibility['last_issued_at']);
        $this->assertSame('2028-11-01', $eligibility['eligible_from']);
        try {
            app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->jacket('softshell')], 'jacket-recent-'.$status);
            $this->fail('A recent jacket issue did not block another style.');
        } catch (ValidationException $exception) {
            $this->assertSame([$eligibility['reason']], $exception->errors()['items.jacket.quantity']);
        }
        $this->assertDatabaseCount('personnel_requests', 1);
    }

    public static function issueStatuses(): array
    {
        return [['active'], ['returned'], ['retired']];
    }

    public function test_eligibility_begins_on_the_third_issue_anniversary_including_leap_day(): void
    {
        $employee = $this->employee();
        $this->issuedJacket($employee, '2024-02-29');
        $service = app(UniformEntitlementService::class);
        $this->travelTo(Carbon::parse('2027-02-27 23:59:00'));
        $this->assertFalse($service->jacketEligibility($employee)['can_order']);
        $this->assertSame('2027-02-28', $service->jacketEligibility($employee)['eligible_from']);
        $this->travelTo(Carbon::parse('2027-02-28 00:00:00'));
        $this->assertTrue($service->jacketEligibility($employee)['can_order']);
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->jacket()], 'jacket-anniversary');
        $this->assertSame(1, $request->items->sole()->quantity);
    }

    public function test_latest_exact_legacy_issue_is_used_and_vague_descriptions_are_not_guessed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07'));
        $employee = $this->employee();
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'category' => 'Other',
            'item_description' => 'Possibly an old jacket', 'quantity' => 1, 'issued_at' => '2026-09-01']);
        $this->assertTrue(app(UniformEntitlementService::class)->jacketEligibility($employee)['can_order']);
        $uniform = Uniform::query()->create(['item_name' => 'Department Jacket', 'size' => 'L', 'quantity_on_hand' => 1, 'reorder_level' => 0]);
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'uniform_id' => $uniform->id,
            'category' => 'Uniform Inventory', 'item_description' => 'An exact linked inventory record', 'quantity' => 1, 'issued_at' => '2022-01-01']);
        AssignedEquipment::query()->create(['user_id' => $user->id, 'category' => 'Jacket',
            'item_description' => 'Legacy classified item', 'quantity' => 1, 'issued_at' => '2025-01-01', 'status' => 'retired']);
        $eligibility = app(UniformEntitlementService::class)->jacketEligibility($employee);

        $this->assertFalse($eligibility['can_order']);
        $this->assertSame('2025-01-01', $eligibility['last_issued_at']);
        $this->assertSame('2028-01-01', $eligibility['eligible_from']);
    }

    #[DataProvider('outstandingStatuses')]
    public function test_outstanding_jacket_blocks_another_order_for_any_style(string $status): void
    {
        $employee = $this->employee();
        $service = app(PersonnelRequestSubmissionService::class);
        $request = $service->submitUniform($employee, [$this->jacket()], 'jacket-outstanding-'.$status);
        $request->update(['status' => $status]);

        $this->assertFalse(app(UniformEntitlementService::class)->jacketEligibility($employee)['can_order']);
        try {
            $service->submitUniform($employee, [$this->jacket('quarter_zip')], 'jacket-duplicate-'.$status);
            $this->fail('An outstanding jacket did not block a second style.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.jacket.quantity', $exception->errors());
        }
        $this->assertDatabaseCount('personnel_requests', 1);
    }

    public static function outstandingStatuses(): array
    {
        return array_map(fn (PersonnelRequestStatus $status): array => [$status->value], [
            PersonnelRequestStatus::Pending, PersonnelRequestStatus::Acknowledged, PersonnelRequestStatus::NeedsInformation,
            PersonnelRequestStatus::Ordered, PersonnelRequestStatus::Arrived, PersonnelRequestStatus::ReadyForPickup, PersonnelRequestStatus::Completed,
        ]);
    }

    #[DataProvider('releasedStatuses')]
    public function test_denied_or_cancelled_unissued_requests_do_not_hold_the_allowance(string $status): void
    {
        $employee = $this->employee();
        $service = app(PersonnelRequestSubmissionService::class);
        $first = $service->submitUniform($employee, [$this->jacket()], 'jacket-released-'.$status);
        $first->update(['status' => $status]);

        $this->assertTrue(app(UniformEntitlementService::class)->jacketEligibility($employee)['can_order']);
        $second = $service->submitUniform($employee, [$this->jacket('softshell')], 'jacket-replacement-'.$status);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('personnel_requests', 2);
    }

    public static function releasedStatuses(): array
    {
        return [['denied'], ['cancelled']];
    }

    public function test_retry_returns_original_jacket_even_after_it_has_been_issued(): void
    {
        $employee = $this->employee();
        $service = app(PersonnelRequestSubmissionService::class);
        $first = $service->submitUniform($employee, [$this->jacket()], 'jacket-idempotent');
        $item = $first->items->sole();
        $item->update(['fulfillment_status' => 'fulfilled', 'fulfilled_quantity' => 1]);
        $first->update(['status' => PersonnelRequestStatus::Completed]);
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'category' => 'Uniform Inventory',
            'item_description' => 'Issued jacket', 'quantity' => 1, 'issued_at' => today(), 'source_personnel_request_item_id' => $item->id]);
        $retry = $service->submitUniform($employee, [], 'jacket-idempotent');

        $this->assertTrue($first->is($retry));
        $this->assertSame('5.11 Quarter Zip', $retry->items->sole()->item_name);
        $this->assertDatabaseCount('personnel_requests', 1);
        $this->assertDatabaseCount('personnel_request_updates', 1);
    }

    public function test_direct_inventory_issue_prevents_later_fulfillment_of_an_earlier_jacket_request(): void
    {
        $employee = $this->employee();
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->jacket()], 'jacket-before-direct-issue');
        $stock = $this->jacketStock();
        app(UniformInventoryService::class)->issue($stock, $employee, 1, today()->toDateString());
        try {
            app(PersonnelRequestFulfillmentService::class)->issueUniform($request->items->sole(), $stock, $this->issueAdmin(), today()->toDateString(), quantity: 1, idempotencyKey: 'jacket-after-direct-issue');
            $this->fail('A pending request bypassed the recorded jacket cycle.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issued_at', $exception->errors());
        }
        $this->assertSame(0, $request->items->sole()->refresh()->fulfilled_quantity);
        $this->assertSame(4, $stock->refresh()->quantity_on_hand);
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    public function test_legacy_request_quantity_cannot_issue_multiple_jackets_and_valid_receipt_replays(): void
    {
        $employee = $this->employee();
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->jacket()], 'jacket-legacy-issue-quantity');
        $item = $request->items->sole();
        $item->update(['quantity' => 2]);
        $stock = $this->jacketStock();
        $admin = $this->issueAdmin();
        $service = app(PersonnelRequestFulfillmentService::class);
        try {
            $service->issueUniform($item, $stock, $admin, today()->toDateString());
            $this->fail('A historical quantity bypassed the one-jacket issue cap.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $first = $service->issueUniform($item, $stock, $admin, today()->toDateString(), quantity: 1, idempotencyKey: 'jacket-issue-once');
        $retry = $service->issueUniform($item, $stock, $admin, today()->toDateString(), quantity: 1, idempotencyKey: 'jacket-issue-once');
        $this->assertTrue($first->is($retry));
        $this->assertSame(4, $stock->refresh()->quantity_on_hand);
        $this->assertSame(1, $item->refresh()->fulfilled_quantity);
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    #[DataProvider('issueStatuses')]
    public function test_direct_issue_checks_recorded_cycle_even_after_return_or_retirement(string $status): void
    {
        $employee = $this->employee();
        $stock = $this->jacketStock();
        $assignment = app(UniformInventoryService::class)->issue($stock, $employee, 1, today()->subYear()->toDateString());
        $assignment->update(['status' => $status]);
        try {
            app(UniformInventoryService::class)->issue($stock, $employee, 1, today()->toDateString());
            $this->fail('The recorded issue cycle was reset.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issued_at', $exception->errors());
        }
        $this->assertDatabaseCount('assigned_equipment', 1);
    }

    public function test_exact_jacket_stock_counts_even_when_source_order_item_is_another_uniform(): void
    {
        $employee = $this->employee();
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee,
            [['item_code' => 't_shirt', 'quantity' => 1, 'metadata' => ['size' => 'L']]], 'jacket-inventory-exact');
        $stock = $this->jacketStock();
        app(UniformInventoryService::class)->issue($stock, $employee, 1, today()->toDateString(), sourceItem: $request->items->sole());
        $this->assertFalse(app(UniformEntitlementService::class)->jacketEligibility($employee)['can_order']);
    }

    public function test_user_only_legacy_issue_history_is_checked_without_inventing_a_personnel_link(): void
    {
        $user = User::factory()->create(['employee_profile_id' => null]);
        AssignedEquipment::query()->create(['user_id' => $user->id, 'category' => 'Jacket', 'item_description' => 'Legacy jacket',
            'quantity' => 1, 'issued_at' => today()->subYear(), 'status' => 'retired']);
        try {
            DB::transaction(fn () => app(UniformEntitlementService::class)->assertJacketIssueAllowed($user, 1, today()->toDateString()));
            $this->fail('User-only history was silently exempted from the cycle.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issued_at', $exception->errors());
        }
        $this->assertNull($user->refresh()->employee_profile_id);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_previous_vintage_inventory_issue_still_counts_toward_the_three_year_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10'));
        $employee = $this->employee();
        $stock = Uniform::query()->create(['item_name' => 'MBFD Vintage jacket', 'size' => 'L', 'quantity_on_hand' => 0, 'reorder_level' => 0]);
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'uniform_id' => $stock->id,
            'category' => 'Uniform Inventory', 'item_description' => 'MBFD Vintage jacket', 'quantity' => 1, 'issued_at' => '2025-10-10']);

        $eligibility = app(UniformEntitlementService::class)->jacketEligibility($employee);

        $this->assertFalse($eligibility['can_order']);
        $this->assertSame('2025-10-10', $eligibility['last_issued_at']);
        $this->assertSame('2028-10-10', $eligibility['eligible_from']);
    }

    private function jacketStock(): Uniform
    {
        return Uniform::query()->create(['item_name' => 'Department Jacket', 'size' => 'L', 'quantity_on_hand' => 5, 'reorder_level' => 0]);
    }

    private function issueAdmin(): User
    {
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');

        return $admin;
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => 'JACKET-'.Str::random(10), 'name' => 'Jacket Test Member',
            'rank' => 'Firefighter', 'password' => 'unused-fixture-credential', 'must_change_password' => false]);
    }

    private function jacket(string $style = 'quarter_zip'): array
    {
        return ['item_code' => 'jacket', 'quantity' => 1, 'metadata' => ['jacket_style' => $style, 'size' => 'L']];
    }

    private function issuedJacket(Employee $employee, string $issuedAt, string $status = 'active'): PersonnelRequest
    {
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform($employee, [$this->jacket()], 'jacket-issued-'.Str::uuid());
        $item = $request->items->sole();
        $item->update(['fulfillment_status' => 'fulfilled', 'fulfilled_quantity' => 1]);
        $request->update(['status' => PersonnelRequestStatus::Completed, 'type' => PersonnelRequestType::Uniform]);
        AssignedEquipment::query()->create(['employee_portal_id' => $employee->id, 'category' => 'Uniform Inventory',
            'item_description' => 'Issued jacket', 'quantity' => 1, 'issued_at' => $issuedAt, 'status' => $status,
            'source_personnel_request_item_id' => $item->id]);

        return $request;
    }
}
