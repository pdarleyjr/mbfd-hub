<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Filament\Resources\InventoryRequestResource\Pages\ListInventoryRequests;
use App\Filament\Resources\InventoryRequestResource\Pages\ViewInventoryRequest;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsStatistics;
use App\Services\Ems\Exports\StatisticsView;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Admin -> EMS shows which of the four medical inventory forms a request came from: a Form column and filter on
 * Submitted Inventory Forms, the family on the request page, and a "By form" table on Medical Data / Statistics.
 * A request with no family stored (written before the four forms, or by a rolled-back image) is ALS Transport.
 */
final class FormFamilyAdminTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const ALS_TRANSPORT_TITLE = 'ALS Transport Inventory Form';

    private const FIRE_BOAT_TITLE = 'Fire Boat 6 Medical Inventory Form';

    private User $member;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function actAsManager(): void
    {
        $this->forgetEmsMemo();
        $this->actingAs($this->manager->fresh());
    }

    /**
     * A request with the family stored as given. The family is written straight to the row: the member form decides it
     * at submission, and these tests are about how staff read it.
     */
    private function requestOn(?string $family, int $quantity = 4): InventoryRequest
    {
        $sent = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => $quantity]]);
        DB::table('ems_inventory_requests')->where('uuid', $sent['uuid'])->update(['form_family' => $family]);

        return $this->requestModel($sent['uuid']);
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_has_a_form_column_that_reads_a_missing_family_as_als_transport(): void
    {
        $old = $this->requestOn(null);
        $stored = $this->requestOn('als-transport');
        $boat = $this->requestOn('fire-boat-6');
        $detail = $this->requestOn('bls-detail');
        $this->actAsManager();

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable')
            ->assertTableColumnExists('form_family')
            ->assertTableColumnStateSet('form_family', self::ALS_TRANSPORT_TITLE, $old)
            ->assertTableColumnStateSet('form_family', self::ALS_TRANSPORT_TITLE, $stored)
            ->assertTableColumnStateSet('form_family', self::FIRE_BOAT_TITLE, $boat)
            ->assertTableColumnStateSet('form_family', 'BLS Detail Medical Inventory Form', $detail);
        $page->assertSee('Form');
    }

    public function test_the_form_filter_keeps_one_family_and_als_transport_includes_rows_with_no_family(): void
    {
        $old = $this->requestOn(null);
        $stored = $this->requestOn('als-transport');
        $boat = $this->requestOn('fire-boat-6');
        $detail = $this->requestOn('bls-detail');
        $nonTransport = $this->requestOn('als-non-transport');
        $this->actAsManager();

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable');
        $page->assertCanSeeTableRecords([$old, $stored, $boat, $detail, $nonTransport]);

        $page->filterTable('form_family', 'fire-boat-6')
            ->assertCanSeeTableRecords([$boat])
            ->assertCanNotSeeTableRecords([$old, $stored, $detail, $nonTransport]);
        $page->filterTable('form_family', 'als-transport')
            ->assertCanSeeTableRecords([$old, $stored])
            ->assertCanNotSeeTableRecords([$boat, $detail, $nonTransport]);
        $page->filterTable('form_family', 'bls-detail')
            ->assertCanSeeTableRecords([$detail])
            ->assertCanNotSeeTableRecords([$old, $stored, $boat, $nonTransport]);
        $page->filterTable('form_family', 'als-non-transport')
            ->assertCanSeeTableRecords([$nonTransport])
            ->assertCanNotSeeTableRecords([$old, $stored, $boat, $detail]);
    }

    public function test_the_form_filter_offers_the_four_forms_and_combines_with_the_status_filter(): void
    {
        $this->requestOn(null);
        $boat = $this->requestOn('fire-boat-6');
        $this->actAsManager();

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable');
        $options = $page->instance()->getTable()->getFilter('form_family')->getOptions();
        $this->assertSame(
            ['als-transport', 'als-non-transport', 'fire-boat-6', 'bls-detail'],
            array_keys($options),
            'The four forms, in the order the member sees them.',
        );
        $this->assertSame(self::FIRE_BOAT_TITLE, $options['fire-boat-6']);

        // A closed boat form leaves the default (active) view, and the family filter does not bring it back.
        $this->requestModel($boat->uuid)->forceFill(['status' => 'closed'])->saveQuietly();
        $page->filterTable('form_family', 'fire-boat-6')->assertCanNotSeeTableRecords([$boat]);
        $page->filterTable('status', ['closed'])->assertCanSeeTableRecords([$boat]);
    }

    // ------------------------------------------------------------------ request page

    public function test_the_request_page_names_the_form(): void
    {
        $old = $this->requestOn(null);
        $boat = $this->requestOn('fire-boat-6');
        $this->actAsManager();

        Livewire::test(ViewInventoryRequest::class, ['record' => $boat->uuid])
            ->assertOk()
            ->assertSee(self::FIRE_BOAT_TITLE)
            ->assertSeeHtml('data-testid="request-family"');
        Livewire::test(ViewInventoryRequest::class, ['record' => $old->uuid])
            ->assertOk()
            ->assertSee(self::ALS_TRANSPORT_TITLE)
            ->assertDontSee(self::FIRE_BOAT_TITLE);
    }

    // ------------------------------------------------------------------ statistics

    public function test_the_statistics_page_has_a_by_form_table_labelled_as_requests_not_consumption(): void
    {
        $this->requestOn('fire-boat-6', 4);
        $this->requestOn(null, 2);
        $this->requestOn('als-transport', 3);
        $this->actAsManager();

        $page = Livewire::test(MedicalDataStatistics::class)->assertOk();
        // Livewire wraps each loop pass in marker comments; the rows are read without them.
        $html = (string) preg_replace('~<!--.*?-->~s', '', $page->html());

        $this->assertStringContainsString('By form', $html);
        $this->assertStringContainsString('These are requests, not supplies used on calls.', $html);
        foreach (['ALS Transport Inventory Form', 'ALS Non Transport Inventory Form', self::FIRE_BOAT_TITLE, 'BLS Detail Medical Inventory Form'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        // title, requests, requested quantity, issued quantity
        $this->assertMatchesRegularExpression('~'.preg_quote(self::FIRE_BOAT_TITLE, '~').'</th>\s*<td[^>]*>\s*1\s*</td>\s*<td[^>]*>\s*4\s*</td>\s*<td[^>]*>\s*0\s*</td>~', $html);
        $this->assertMatchesRegularExpression('~ALS Transport Inventory Form</th>\s*<td[^>]*>\s*2\s*</td>\s*<td[^>]*>\s*5\s*</td>\s*<td[^>]*>\s*0\s*</td>~', $html, 'A request with no family counts as ALS Transport.');
        $this->assertMatchesRegularExpression('~BLS Detail Medical Inventory Form</th>\s*<td[^>]*>\s*0\s*</td>~', $html, 'A form nobody used shows zero, not a missing row.');
    }

    public function test_the_statistics_export_carries_the_same_by_form_section(): void
    {
        $this->requestOn('fire-boat-6', 4);
        $this->requestOn(null, 2);

        $sections = collect(StatisticsView::build(app(EmsStatistics::class)->report([]))['sections'])->keyBy('heading');

        $this->assertTrue($sections->has('By form'));
        $rows = collect($sections['By form']->rows)->keyBy(0);
        $this->assertSame([self::FIRE_BOAT_TITLE, 1, 4, 0], $rows[self::FIRE_BOAT_TITLE]);
        $this->assertSame(['ALS Transport Inventory Form', 1, 2, 0], $rows['ALS Transport Inventory Form'], 'A request with no family counts as ALS Transport.');
    }
}
