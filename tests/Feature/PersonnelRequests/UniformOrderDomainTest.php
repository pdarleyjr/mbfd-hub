<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Services\PersonnelRequests\PersonnelCatalog;
use App\Services\PersonnelRequests\UniformEntitlementService;
use App\Services\PersonnelRequests\UniformOrderCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UniformOrderDomainTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('assignments')]
    public function test_structured_assignment_classification(array $assignment, string $profile, bool $marine): void
    {
        self::assertSame(['profile' => $profile, 'marine' => $marine], app(UniformEntitlementService::class)->classify($assignment));
    }

    public static function assignments(): array
    {
        return [
            'Engine 2 unit' => [['division_label' => 'Operations', 'unit_label' => 'Engine 2', 'bid_selection_label' => 'Operational seat'], 'combat', false],
            'Ladder unit' => [['unit_label' => 'Ladder 3', 'position_label' => 'Firefighter'], 'combat', false],
            'Combat Float selection' => [['assignment_type' => 'Floating', 'bid_selection_label' => 'Combat Float'], 'combat', false],
            'Suppression float with Combat selection' => [['division_label' => 'Suppression', 'unit_label' => 'Float', 'bid_selection_label' => 'Combat Float'], 'combat', false],
            'Combat structured division' => [['division_label' => 'Combat', 'unit_label' => 'Operational unit'], 'combat', false],
            'Rescue 1 unit' => [['unit_label' => 'Rescue 1', 'bid_selection_label' => 'Operational seat'], 'rescue', false],
            'Rescue 11 unit' => [['unit_label' => 'Rescue 11'], 'rescue', false],
            'Rescue Float selection' => [['bid_selection_label' => 'Rescue Float', 'assignment_type' => 'Floating'], 'rescue', false],
            'Captain 5 selection' => [['division_label' => 'Operations', 'position_label' => 'Captain', 'bid_selection_label' => 'Captain 5'], 'rescue', false],
            'Rescue structured division' => [['division_label' => 'Rescue', 'unit_label' => 'Operational unit'], 'rescue', false],
            'Fire Boat 6' => [['unit_label' => 'Fire Boat 6', 'position_label' => 'Deckhand', 'division_label' => 'Marine'], 'day_other', true],
            'Marine Float' => [['bid_selection_label' => 'Marine Float', 'assignment_type' => 'Floating'], 'day_other', true],
            'Marine additive to Combat' => [['division_label' => 'Combat', 'position_label' => 'Marine Fire Boat Operator'], 'combat', true],
            'Division Chief' => [['division_label' => 'Combat', 'position_label' => 'Division Chief'], 'day_other', false],
            'Division Chief rank with broad operational fields' => [['rank_label' => 'Division Chief', 'division_label' => 'Combat', 'unit_label' => 'Operations'], 'day_other', false],
            'Prevention' => [['division_label' => 'Prevention', 'unit_label' => 'Office'], 'day_other', false],
            'Support Services' => [['unit_label' => 'Support Services'], 'day_other', false],
            'Public Education' => [['bid_selection_label' => 'Public Education'], 'day_other', false],
            'Day staff' => [['assignment_type' => 'Day assignment', 'division_label' => 'Operations'], 'day_other', false],
            'No bid' => [[], 'day_other', false],
        ];
    }

    public function test_latest_non_superseded_assignment_is_snapshotted_with_no_fixed_bid_year(): void
    {
        $employee = $this->employee();
        $this->assignment($employee, 2030, 'Engine 2');
        $superseded = $this->assignment($employee, 2031, 'Combat Float');
        $superseded->update(['superseded_at' => now()]);
        $current = $this->assignment($employee, 2031, 'Captain 5');
        $service = app(UniformEntitlementService::class);
        $context = $service->forEmployee($employee);

        self::assertSame('rescue', $context['profile']);
        self::assertSame(2031, $context['bid_year']);
        self::assertSame($current->id, $context['assignment_snapshot']['id']);
        self::assertSame('2031–2032', $context['term_label']);
        $snapshot = $service->snapshot($context, $service->summarize($context, []), '  Replacement needs.  ');
        $current->update(['superseded_at' => now()]);
        $this->assignment($employee, 2031, 'Engine 3');

        self::assertSame('Captain 5', $snapshot['bid_assignment_snapshot']['bid_selection_label']);
        self::assertSame('Replacement needs.', $snapshot['member_note']);
        self::assertSame('combat', $service->forEmployee($employee)['profile']);
    }

    #[DataProvider('swaps')]
    public function test_jumpsuit_exchange_counts_both_sleeve_variants_and_work_sets(string $profile, int $jumpsuits, int $workSets): void
    {
        $service = app(UniformEntitlementService::class);
        $summary = $service->summarize($this->context($profile), [
            ['item_code' => 'jumpsuit', 'quantity' => $jumpsuits],
            ['item_code' => 'polo_shirt', 'quantity' => 1],
            ['item_code' => 'long_sleeve_polo', 'quantity' => $workSets - 1],
            ['item_code' => 'uniform_pants', 'quantity' => $workSets],
        ]);

        self::assertSame($workSets, $summary['allowed']['polos']);
        self::assertSame($workSets, $summary['allowed']['pants']);
        self::assertSame($workSets, $summary['work_sets']['selected']);
        self::assertSame($workSets, $summary['work_sets']['allowed']);
        self::assertSame($workSets - config("uniform_orders.profiles.{$profile}.allowances.work_sets"), $summary['swaps']);
        self::assertSame([], $summary['warnings']);
    }

    public static function swaps(): array
    {
        return [['combat', 2, 3], ['combat', 1, 4], ['combat', 0, 5],
            ['rescue', 3, 2], ['rescue', 2, 3], ['rescue', 1, 4], ['rescue', 0, 5]];
    }

    public function test_overages_and_mismatched_sets_are_advisories_and_all_products_remain_available(): void
    {
        $service = app(UniformEntitlementService::class);
        $items = $service->normalizeItems([
            ['item_code' => 'jumpsuit', 'quantity' => 4, 'metadata' => ['jumpsuit_chest' => 44, 'jumpsuit_length' => 'Regular']],
            ['item_code' => 'polo_shirt', 'quantity' => 12, 'metadata' => ['size' => 'XXXL']],
            ['item_code' => 'uniform_pants', 'quantity' => 2, 'metadata' => ['waist' => 34, 'inseam' => 32]],
            ['item_code' => 'marine_short_sleeve_shirt', 'quantity' => 1, 'metadata' => ['size' => 'XS']],
        ]);
        $summary = $service->summarize($this->context('rescue'), $items);

        self::assertSame(19, $summary['selected_total']);
        self::assertSame(0, $summary['swaps']);
        self::assertCount(4, $summary['warnings']);
        self::assertSame(2, $summary['work_sets']['selected']);
        self::assertArrayHasKey('marine_short_sleeve_shirt', app(UniformOrderCatalog::class)->products());
    }

    public function test_tshirts_and_dress_shirts_use_combined_allowances(): void
    {
        $service = app(UniformEntitlementService::class);
        $summary = $service->summarize($this->context('combat'), [
            ['item_code' => 't_shirt', 'quantity' => 3], ['item_code' => 'long_sleeve_shirt', 'quantity' => 2],
            ['item_code' => 'class_a_shirt', 'quantity' => 1], ['item_code' => 'class_a_long_sleeve_shirt', 'quantity' => 1],
        ]);

        self::assertSame(5, $summary['quantities']['tshirts']);
        self::assertSame(2, $summary['quantities']['dress_shirts']);
        self::assertCount(2, $summary['warnings']);
    }

    public function test_legacy_uniform_shirt_remains_available_without_claiming_it_is_a_polo(): void
    {
        $summary = app(UniformEntitlementService::class)->summarize($this->context('combat'), [
            ['item_code' => 'uniform_shirt', 'quantity' => 1],
        ]);

        self::assertSame(1, $summary['quantities']['uniform_shirts']);
        self::assertSame(0, $summary['quantities']['polos']);
        self::assertSame(0, $summary['work_sets']['selected']);
        self::assertCount(1, $summary['warnings']);
    }

    public function test_day_rule_has_no_fabricated_cost_or_rescue_quantity_limits(): void
    {
        $service = app(UniformEntitlementService::class);
        $context = $service->forEmployee($this->employee('Division Chief'));
        $summary = $service->summarize($context, [['item_code' => 't_shirt', 'quantity' => 20]]);

        self::assertSame('day_other', $context['profile']);
        self::assertStringContainsString('total value', $context['message']);
        self::assertSame([], $summary['allowed']);
        self::assertFalse($summary['pricing_available']);
        self::assertNull($summary['order_value']);
        self::assertSame([], $summary['warnings']);
        self::assertSame('black', $context['recommended_variants']['class_a_pants']['color']);
    }

    public function test_marine_additions_include_shoes_and_periodic_items_do_not_claim_due_dates(): void
    {
        $employee = $this->employee();
        $this->assignment($employee, 2031, 'Fire Boat 6', ['division_label' => 'Marine']);
        $service = app(UniformEntitlementService::class);
        $context = $service->forEmployee($employee);
        $summary = $service->summarize($context, [
            ['item_code' => 'boating_shoes', 'quantity' => 1], ['item_code' => 'marine_shorts', 'quantity' => 3],
            ['item_code' => 'jacket', 'quantity' => 1], ['item_code' => 'raincoat', 'quantity' => 1],
        ]);

        self::assertTrue($context['marine']);
        self::assertSame(1, $context['allowances']['marine_shoes']);
        self::assertSame(3, $context['allowances']['marine_shorts']);
        self::assertCount(1, $summary['warnings']);
        self::assertStringContainsString('Issue history is not available', $summary['warnings'][0]);
        self::assertSame('every_3_years', app(UniformOrderCatalog::class)->product('raincoat')['frequency']);
    }

    public function test_structured_inputs_have_canonical_sizes_and_only_trusted_metadata(): void
    {
        $items = app(UniformEntitlementService::class)->normalizeItems([
            ['item_code' => 'uniform_pants', 'quantity' => 2, 'metadata' => ['waist' => '34', 'inseam' => '32', 'cut' => 'mens', 'untrusted' => true]],
            ['item_code' => 'class_a_long_sleeve_shirt', 'quantity' => 1, 'metadata' => ['neck' => 16.5, 'sleeve_length' => 34, 'sleeve' => 'short']],
            ['item_code' => 'jumpsuit', 'quantity' => 1, 'metadata' => ['jumpsuit_chest' => 44, 'jumpsuit_length' => 'Regular']],
            ['item_code' => 'tie', 'quantity' => 1, 'metadata' => []],
            ['item_code' => 'work_boots', 'quantity' => 1, 'metadata' => ['footwear_type' => 'dress_shoes', 'shoe_size' => 10.5, 'cut' => 'mens', 'width' => 'Wide']],
            ['item_code' => 'class_a_pants', 'quantity' => 1, 'metadata' => ['waist' => 36, 'inseam' => 32, 'color' => 'black']],
        ]);

        self::assertSame("34W × 32L / Men's", $items[0]['size']);
        self::assertArrayNotHasKey('untrusted', $items[0]['metadata']);
        self::assertSame('Neck 16.5 / Sleeve 34', $items[1]['size']);
        self::assertSame('long', $items[1]['metadata']['sleeve']);
        self::assertSame('Chest 44 / Regular', $items[2]['size']);
        self::assertSame('Standard', $items[3]['size']);
        self::assertSame("Dress shoes / US 10.5 / Men's / Width Wide", $items[4]['size']);
        self::assertSame('36W × 32L / Black', $items[5]['size']);
        self::assertSame('uniform', $items[0]['category']);
        self::assertSame('5.11 Tactical Pants', $items[0]['item_name']);
    }

    #[DataProvider('invalidItems')]
    public function test_input_validation_blocks_only_invalid_inputs(array $item, string $error): void
    {
        try {
            app(UniformEntitlementService::class)->normalizeItems(['selected' => $item]);
            self::fail('Invalid ordering input was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('items.selected.'.$error, $exception->errors());
        }
    }

    public static function invalidItems(): array
    {
        return [
            'missing waist' => [['item_code' => 'uniform_pants', 'quantity' => 1, 'metadata' => ['inseam' => 32]], 'metadata.waist'],
            'invalid neck' => [['item_code' => 'class_a_shirt', 'quantity' => 1, 'metadata' => ['neck' => -1]], 'metadata.neck'],
            'fractional unsupported step' => [['item_code' => 'class_a_shirt', 'quantity' => 1, 'metadata' => ['neck' => 16.3]], 'metadata.neck'],
            'negative quantity' => [['item_code' => 'tie', 'quantity' => -1, 'metadata' => []], 'quantity'],
            'fractional quantity' => [['item_code' => 'tie', 'quantity' => 1.5, 'metadata' => []], 'quantity'],
            'boolean quantity' => [['item_code' => 'tie', 'quantity' => true, 'metadata' => []], 'quantity'],
            'unsupported option' => [['item_code' => 'polo_shirt', 'quantity' => 1, 'metadata' => ['size' => 'Made up']], 'metadata.size'],
            'unsupported chest' => [['item_code' => 'jumpsuit', 'quantity' => 1, 'metadata' => ['jumpsuit_chest' => 80, 'jumpsuit_length' => 'Regular']], 'metadata.jumpsuit_chest'],
            'unsupported length' => [['item_code' => 'jumpsuit', 'quantity' => 1, 'metadata' => ['jumpsuit_chest' => 44, 'jumpsuit_length' => 'Unknown']], 'metadata.jumpsuit_length'],
            'array measurement' => [['item_code' => 'class_a_shirt', 'quantity' => 1, 'metadata' => ['neck' => []]], 'metadata.neck'],
            'unknown item' => [['item_code' => 'made_up_item', 'quantity' => 1, 'metadata' => []], 'item_code'],
        ];
    }

    public function test_existing_product_codes_and_extended_production_sizes_are_retained(): void
    {
        $catalog = app(PersonnelCatalog::class);
        $products = app(UniformOrderCatalog::class)->products();
        foreach (config('personnel_requests.uniform_catalog') as $code => $legacy) {
            self::assertArrayHasKey($code, $products);
            self::assertSame($legacy['sizes'], $catalog->uniform($code)['sizes']);
        }
        self::assertArrayHasKey('XS', $products['polo_shirt']['fields'][0]['options']);
        self::assertArrayHasKey('XXXL', $products['long_sleeve_shirt']['fields'][0]['options']);
        self::assertArrayHasKey('tie', $catalog->uniforms());
        self::assertArrayNotHasKey('bunker_coat', $catalog->uniforms());
    }

    private function context(string $profile): array
    {
        return ['profile' => $profile, 'marine' => false, 'allowances' => config("uniform_orders.profiles.{$profile}.allowances")];
    }

    private function employee(string $rank = 'Firefighter'): Employee
    {
        return Employee::query()->create(['employee_id' => 'UNIFORM-DOMAIN-'.str()->random(8), 'name' => 'Uniform Domain Fixture',
            'rank' => $rank, 'password' => bcrypt(str()->random(40)), 'must_change_password' => false]);
    }

    private function assignment(Employee $employee, int $year, string $selection, array $attributes = []): EmployeeBidAssignment
    {
        return EmployeeBidAssignment::query()->create($attributes + [
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => $year,
            'term_label' => $year.'–'.($year + 1), 'bid_session_id' => 'UNIFORM-DOMAIN', 'position_id' => 'TEST-SEAT',
            'rank_label' => 'Firefighter', 'shift_label' => 'A Shift', 'station_label' => 'Station #1',
            'division_label' => 'Operations', 'unit_label' => $selection, 'position_label' => 'Firefighter',
            'bid_selection_label' => $selection, 'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award',
            'a_day_code' => 'G1', 'a_day_label' => 'Group 1', 'idempotency_key' => (string) str()->uuid(),
            'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
        ]);
    }
}
