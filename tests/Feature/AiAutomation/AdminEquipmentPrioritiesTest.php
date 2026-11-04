<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Filament\Resources\SupportEquipmentRuleResource\Pages\CreateSupportEquipmentRule;
use App\Filament\Resources\SupportEquipmentRuleResource\Pages\EditSupportEquipmentRule;
use App\Filament\Resources\SupportEquipmentRuleResource\Pages\ListSupportEquipmentRules;
use App\Filament\Resources\SupportEquipmentRuleResource\Pages\NotClassifiedYet;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\User;
use App\Services\AiAutomation\Admin\EquipmentRulePreview;
use App\Services\AiAutomation\PlaybookRepository;
use App\Services\SupportTriage\EquipmentRuleService;
use Livewire\Livewire;

final class AdminEquipmentPrioritiesTest extends AdminScreensTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PlaybookRepository::class)->ensureSeeded();
        $this->admin = $this->actingAsSuperAdmin();
    }

    public function test_a_quick_level_tap_changes_the_priority_through_the_service_and_keeps_history(): void
    {
        $rule = $this->rule('Zebra Widget', 'low');

        Livewire::test(ListSupportEquipmentRules::class)->loadTable()
            ->assertCanSeeTableRecords([$rule])
            ->call('setRuleLevel', $rule->getKey(), 'high');

        $rule->refresh();
        self::assertSame('high', $rule->level);
        self::assertGreaterThanOrEqual(2, $rule->revisions()->count(), 'The service recorded a revision.');
        self::assertSame($this->admin->getKey(), $rule->revisions()->first()->edited_by_user_id);
    }

    public function test_the_list_shows_big_priority_buttons_and_searches_by_alias(): void
    {
        $rule = $this->rule('Zebra Widget', 'medium', ['aliases' => ['striped gadget']]);
        $other = $this->rule('Plain Hammer', 'low');

        Livewire::test(ListSupportEquipmentRules::class)->loadTable()
            ->assertSee('Low')->assertSee('Medium')->assertSee('High')
            ->searchTable('striped')
            ->assertCanSeeTableRecords([$rule])->assertCanNotSeeTableRecords([$other])
            ->searchTable('hammer')
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$rule]);
    }

    public function test_bulk_actions_set_many_items_at_once(): void
    {
        $a = $this->rule('Zebra Widget', 'low');
        $b = $this->rule('Plain Hammer', 'low');
        $c = $this->rule('Quiet Bell', 'low');

        Livewire::test(ListSupportEquipmentRules::class)->loadTable()
            ->callTableBulkAction('bulkHigh', [$a, $b])
            ->assertHasNoTableBulkActionErrors();

        self::assertSame('high', $a->fresh()->level);
        self::assertSame('high', $b->fresh()->level);
        self::assertSame('low', $c->fresh()->level);
    }

    public function test_the_edit_form_uses_plain_words_and_hides_internal_names(): void
    {
        $rule = $this->rule('Zebra Widget', 'medium');

        $html = $this->get(SupportEquipmentRuleResource::getUrl('edit', ['record' => $rule]))->assertOk()->getContent();

        foreach (['Equipment name', 'Other names people use', 'Who handles it?', 'When to contact 300', 'Is this firefighting protective gear?', 'Is this expensive?', 'Is it critical for readiness?', 'What should the reply say?', 'Advanced', 'Only if urgent', 'Support Services'] as $words) {
            self::assertStringContainsString($words, (string) $html);
        }
        foreach (['normalized_name', 'apparatus_scope', 'contact_300', 'officer_300', 'scba_unit'] as $internal) {
            self::assertStringNotContainsString('>'.$internal.'<', (string) $html, $internal);
        }
    }

    public function test_editing_saves_a_new_version_and_restore_previous_puts_the_old_values_back(): void
    {
        $rule = $this->rule('Zebra Widget', 'low');

        Livewire::test(EditSupportEquipmentRule::class, ['record' => $rule->getKey()])
            ->fillForm(['level' => 'high', 'handler' => 'air_tech', 'member_response' => 'We will check on the {item} for you.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule->refresh();
        self::assertSame('high', $rule->level);
        self::assertSame('air_tech', $rule->handler);
        $versions = $rule->revisions()->pluck('version')->all();
        self::assertGreaterThanOrEqual(2, count($versions));
        $first = min($versions);

        Livewire::test(EditSupportEquipmentRule::class, ['record' => $rule->getKey()])
            ->callAction('restorePrevious', ['version' => $first])
            ->assertHasNoActionErrors();

        $rule->refresh();
        self::assertSame('low', $rule->level);
        self::assertSame('support_services', $rule->handler);
        self::assertNull($rule->member_response);
        self::assertGreaterThan(max($versions), (int) $rule->version, 'A restore is a new version; history is never rewritten.');
    }

    public function test_the_edit_form_rejects_contact_details_in_the_reply_with_a_field_error(): void
    {
        $rule = $this->rule('Zebra Widget', 'low');

        Livewire::test(EditSupportEquipmentRule::class, ['record' => $rule->getKey()])
            ->fillForm(['member_response' => 'Call 305-555-0123 now'])
            ->call('save')
            ->assertHasFormErrors(['member_response']);

        self::assertNull($rule->fresh()->member_response);
    }

    public function test_adding_equipment_goes_through_the_service(): void
    {
        Livewire::test(CreateSupportEquipmentRule::class)
            ->fillForm([
                'name' => 'Rope Bag Tag', 'category' => 'consumable', 'level' => 'low', 'handler' => 'support_services',
                'contact_300' => 'never', 'aliases' => ['rope tag'], 'is_ppe' => false, 'is_expensive' => false, 'is_critical' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = SupportEquipmentRule::query()->where('name', 'Rope Bag Tag')->sole();
        self::assertSame('admin', $rule->source);
        self::assertSame(['rope tag'], $rule->aliases);
        self::assertSame('never', $rule->contact_300);
        self::assertSame(1, $rule->revisions()->count());
    }

    public function test_a_name_another_item_already_covers_shows_a_plain_error_on_the_name_field(): void
    {
        $this->rule('Zebra Widget', 'low');

        Livewire::test(CreateSupportEquipmentRule::class)
            ->fillForm(['name' => 'Zebra Widget', 'category' => 'consumable', 'level' => 'low', 'handler' => 'support_services', 'contact_300' => 'urgent'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_the_not_classified_page_lists_checklist_items_and_classifies_with_one_tap(): void
    {
        $items = app(EquipmentRuleService::class)->unclassifiedChecklistItems(null, 5);
        if ($items === []) {
            self::markTestSkipped('Every checklist item already has a rule in this catalog.');
        }
        $name = $items[0]['name'];

        $page = Livewire::test(NotClassifiedYet::class)->assertSuccessful()->assertSee($name);
        $page->call('classify', $name, 'high');

        $rule = SupportEquipmentRule::query()->where('name', $name)->sole();
        self::assertSame('high', $rule->level);
        self::assertSame('admin', $rule->source);
        self::assertNotContains($name, array_column(app(EquipmentRuleService::class)->unclassifiedChecklistItems(null, 500), 'name'));
    }

    public function test_classify_with_one_tap_for_a_made_up_item_creates_a_rule_with_the_chosen_level(): void
    {
        Livewire::test(NotClassifiedYet::class)->call('classify', 'Made Up Gadget', 'medium');

        self::assertSame('medium', SupportEquipmentRule::query()->where('name', 'Made Up Gadget')->sole()->level);
    }

    public function test_the_preview_shows_the_member_reply_priority_handler_and_who_is_alerted(): void
    {
        $preview = app(EquipmentRulePreview::class)->build([
            'name' => 'Zebra Widget', 'category' => 'rescue_medical', 'level' => 'high', 'handler' => 'captain5', 'contact_300' => 'always',
            'member_response' => 'We are on the {item} right now.',
        ]);

        self::assertSame('We are on the Zebra Widget right now.', $preview['reply']);
        self::assertSame('High', $preview['level_label']);
        self::assertSame('Captain 5', $preview['handler']);
        self::assertStringContainsString('300 is always contacted', $preview['alerts']);
        self::assertStringContainsString('alerted right away', $preview['alerts']);

        $standard = app(EquipmentRulePreview::class)->build(['name' => 'Zebra Widget', 'category' => 'hand_tool', 'level' => 'low', 'handler' => 'support_services', 'contact_300' => 'never']);
        self::assertNotSame('', $standard['reply']);
        self::assertStringContainsString('Zebra Widget', $standard['reply']);
    }

    public function test_update_open_issues_applies_a_changed_priority_to_issues_that_are_already_open(): void
    {
        $rule = $this->rule('Zebra Widget', 'low');
        $station = \App\Models\Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Main St', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139', 'is_active' => true]);
        $truck = \App\Models\Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1-'.uniqid(), 'name' => 'E1', 'type' => 'Engine', 'vehicle_number' => 'E1',
            'designation' => 'E1', 'slug' => 'e1-'.uniqid(), 'status' => 'In Service',
        ]);
        $defect = \App\Models\ApparatusDefect::query()->create([
            'apparatus_id' => $truck->id, 'compartment' => 'A', 'item' => 'Zebra Widget', 'status' => 'open', 'issue_type' => 'missing', 'reported_date' => now(),
        ]);
        app(\App\Services\SupportTriage\TriageProjector::class)->refreshDefect($defect);
        self::assertSame('low', app(\App\Services\SupportTriage\TriageProjector::class)->levelFor('apparatus_defect', (int) $defect->getKey()));

        app(EquipmentRuleService::class)->setLevel($rule, 'high', $this->admin);
        Livewire::test(ListSupportEquipmentRules::class)->callAction('applyToOpenIssues')->assertHasNoActionErrors();

        self::assertSame('high', app(\App\Services\SupportTriage\TriageProjector::class)->levelFor('apparatus_defect', (int) $defect->getKey()));
    }

    public function test_the_list_table_exports_excel_and_pdf(): void
    {
        $this->rule('Zebra Widget', 'low');

        foreach (['xlsx', 'pdf'] as $format) {
            Livewire::test(ListSupportEquipmentRules::class)->loadTable()
                ->assertTableActionVisible('export')
                ->assertTableBulkActionExists('export_selected')
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'all', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rule(string $name, string $level, array $extra = []): SupportEquipmentRule
    {
        return app(EquipmentRuleService::class)->create([
            'name' => $name, 'level' => $level, 'category' => 'hand_tool', 'handler' => 'support_services', 'contact_300' => 'urgent',
        ] + $extra, $this->admin);
    }
}
