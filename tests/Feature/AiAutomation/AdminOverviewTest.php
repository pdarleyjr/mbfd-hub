<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Admin\Pages\AiActivityHistory;
use App\Filament\Admin\Pages\AiResponseAutomation;
use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Filament\Resources\AiPlaybookResource;
use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\Station;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Services\AiAutomation\Admin\OverviewNumbers;
use App\Services\AiAutomation\AiAutomationSwitch;
use App\Services\AiAutomation\PlaybookRepository;
use App\Services\SupportTriage\EquipmentRuleService;
use Filament\Facades\Filament;
use Livewire\Livewire;

final class AdminOverviewTest extends AdminScreensTestCase
{
    /** @return array<string, array{string}> */
    public static function screens(): array
    {
        return [
            'overview' => ['/admin/ai-response-automation'],
            'activity' => ['/admin/ai-activity'],
            'priorities' => ['/admin/support-equipment-priorities'],
            'add equipment' => ['/admin/support-equipment-priorities/create'],
            'not classified' => ['/admin/support-equipment-priorities/not-classified-yet'],
            'knowledge' => ['/admin/ai-knowledge'],
            'new article' => ['/admin/ai-knowledge/create'],
            'messages' => ['/admin/ai-playbooks'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_every_screen_opens_for_a_super_admin_and_a_permission_holder_only(string $path): void
    {
        $this->actingAsSuperAdmin();
        $this->get($path)->assertOk();

        $this->actingAs($this->admin(permissions: ['admin.access', 'admin.ai_automation.manage']));
        $this->get($path)->assertOk();

        foreach ([['admin.access'], ['admin.ai_automation.manage'], []] as $permissions) {
            $this->actingAs($this->admin(permissions: $permissions));
            self::assertNotSame(200, $this->get($path)->getStatusCode(), $path.' for '.implode(',', $permissions));
        }
    }

    public function test_the_menu_group_is_named_support_services_automation_with_the_five_pages_in_order(): void
    {
        $this->actingAsSuperAdmin();

        $items = collect(Filament::getNavigation())
            ->first(fn ($group) => $group->getLabel() === 'Support Services Automation');
        self::assertNotNull($items, 'The group is missing from the menu.');
        self::assertSame(
            ['Overview', 'Equipment Priorities', 'Knowledge & Instructions', 'Response Messages', 'Activity & History'],
            $items->getItems()->map(fn ($item) => $item->getLabel())->values()->all(),
        );
        self::assertNull(collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'AI Response Automation'));
        foreach ($items->getItems() as $item) {
            self::assertNull($item->getIcon(), 'Items inside an icon group carry no icon of their own.');
        }
    }

    public function test_the_overview_has_the_big_switch_and_uses_plain_words(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/admin/ai-response-automation')->assertOk()
            ->assertSee('AUTOMATIC REPLIES')
            ->assertSee('Replies sent today')
            ->assertSee('Issues waiting for staff')
            ->assertSee('Errors needing attention')
            ->assertSee('Open issues by priority')
            ->assertSee('Try a message')
            ->assertDontSee('Playbook')
            ->assertDontSee('template_key');

        $page = Livewire::test(AiResponseAutomation::class)->assertSuccessful();
        $page->callAction('turnOn')->assertHasNoActionErrors();
        self::assertTrue(app(AiAutomationSwitch::class)->isOn());
        Livewire::test(AiResponseAutomation::class)->callAction('turnOff')->assertHasNoActionErrors();
        self::assertFalse(app(AiAutomationSwitch::class)->isOn());
    }

    public function test_open_issue_counts_use_the_effective_level_and_skip_closed_records(): void
    {
        $station = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Main St', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139', 'is_active' => true]);
        $truck = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'E1-'.uniqid(), 'name' => 'E1', 'type' => 'Engine', 'vehicle_number' => 'E1',
            'designation' => 'E1', 'slug' => 'e1-'.uniqid(), 'status' => 'In Service',
        ]);
        $open = fn (string $item): ApparatusDefect => ApparatusDefect::query()->create([
            'apparatus_id' => $truck->id, 'compartment' => 'A', 'item' => $item, 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now(),
        ]);
        $closed = ApparatusDefect::query()->create([
            'apparatus_id' => $truck->id, 'compartment' => 'A', 'item' => 'Old', 'status' => 'resolved', 'resolved' => true, 'issue_type' => 'damaged', 'reported_date' => now(),
        ]);
        $rows = [
            [$open('Light')->id, 'low', null, 'low'],
            [$open('Hose')->id, 'low', 'high', 'high'], // staff raised it: the effective level counts
            [$open('Axe')->id, 'medium', null, 'medium'],
            [$closed->id, 'high', null, 'high'], // closed: never counted
        ];
        foreach ($rows as [$id, $auto, $override, $effective]) {
            SupportTriageLevel::query()->updateOrCreate(['subject_type' => 'apparatus_defect', 'subject_id' => $id], [
                'auto_level' => $auto, 'override_level' => $override,
                'level' => $effective, 'policy_version' => 1, 'computed_at' => now(),
            ]);
        }

        self::assertSame(['low' => 1, 'medium' => 1, 'high' => 1], app(OverviewNumbers::class)->openLevelCounts());
    }

    public function test_today_numbers_count_sent_waiting_and_failed_replies(): void
    {
        $this->event(['state' => 'sent', 'risk_tier' => 'critical', 'needs_human' => true]);
        $this->event(['state' => 'sent', 'risk_tier' => 'routine', 'needs_human' => true]);
        $this->event(['state' => 'failed', 'risk_tier' => null]);

        $today = app(OverviewNumbers::class)->today();

        self::assertSame(2, $today['sent_today']);
        self::assertSame(1, $today['waiting_for_staff']);
        self::assertSame(1, $today['errors']);
    }

    public function test_try_a_message_shows_priority_who_handles_it_and_the_reply(): void
    {
        $admin = $this->actingAsSuperAdmin();
        app(PlaybookRepository::class)->ensureSeeded();
        app(EquipmentRuleService::class)->create(['name' => 'Zebra Widget', 'level' => 'high', 'category' => 'rescue_medical', 'handler' => 'captain5'], $admin);

        $page = Livewire::test(AiResponseAutomation::class)
            ->set('testArea', 'vehicle')
            ->set('testItem', 'Zebra Widget')
            ->set('testCondition', 'missing')
            ->call('runTestResponse')
            ->assertSet('testLevel', 'high')
            ->assertSet('testHandler', 'Captain 5');
        self::assertNotSame('', trim((string) $page->get('testResult')));
        self::assertNotNull($page->get('testAlerts'));
    }

    public function test_try_a_message_can_name_a_vehicle_and_still_answers(): void
    {
        $this->actingAsSuperAdmin();
        app(PlaybookRepository::class)->ensureSeeded();

        $page = Livewire::test(AiResponseAutomation::class)
            ->set('testArea', 'vehicle')->set('testItem', 'Kitty Litter')->set('testCondition', 'missing')->set('testApparatus', 'ladder3')
            ->call('runTestResponse')->assertHasNoErrors();
        self::assertNotSame('', trim((string) $page->get('testResult')));

        Livewire::test(AiResponseAutomation::class)->set('testApparatus', 'spaceship')->call('runTestResponse')->assertHasErrors(['testApparatus']);
    }

    public function test_the_activity_table_shows_plain_columns_and_both_export_formats(): void
    {
        $this->actingAsSuperAdmin();
        $this->event(['state' => 'sent', 'reply_text' => 'Acknowledged.', 'reason_codes' => ['level_high', 'handler_support_services'], 'risk_tier' => 'critical', 'needs_human' => true]);

        $component = Livewire::test(AiActivityHistory::class)->loadTable()->assertSuccessful()
            ->assertSee('Reply sent')
            ->assertSee('High')
            ->assertSee('Follow up: staff must handle this')
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected');

        foreach (['xlsx', 'pdf'] as $format) {
            Livewire::test(AiActivityHistory::class)->loadTable()
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'all', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
        }
        $component->assertSuccessful();
    }

    public function test_every_table_on_the_new_menu_group_has_export_actions(): void
    {
        $this->actingAsSuperAdmin();

        foreach ([SupportEquipmentRuleResource::class, AiKnowledgeArticleResource::class, AiPlaybookResource::class] as $resource) {
            self::assertContains(\App\Filament\Concerns\HasExportActions::class, class_uses_recursive($resource), $resource);
        }
        self::assertContains(\App\Filament\Concerns\HasPageTableExportActions::class, class_uses_recursive(AiActivityHistory::class));
    }

    /** @param array<string, mixed> $override */
    private function event(array $override = []): AiIntakeEvent
    {
        static $n = 0;

        return AiIntakeEvent::query()->create($override + [
            'domain' => 'vehicle', 'source_type' => 'apparatus_inspection', 'source_id' => ++$n, 'event_key' => 'k'.$n, 'origin' => 'live',
            'epoch' => 1, 'state' => 'sent', 'decision' => 'receipt_only', 'processed_at' => now(),
        ]);
    }
}
