<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Models\User;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\DefaultEquipmentCatalog;
use App\Services\SupportTriage\EquipmentClassification as C;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Services\SupportTriage\PhraseText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EquipmentClassifierTest extends TestCase
{
    use RefreshDatabase;

    private EquipmentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        app(EquipmentRuleService::class)->seedDefaults();
        $this->classifier = app(EquipmentClassifier::class);
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function itemCases(): array
    {
        return [
            'kitty litter' => ['Kitty Litter', C::LOW, 'consumable'],
            'absorbent pads' => ['Absorbent Pads', C::LOW, 'consumable'],
            'cleaning supply' => ['Bleach', C::LOW, 'consumable'],
            'hand tool' => ['Pike Pole', C::LOW, 'hand_tool'],
            'plural hand tool' => ['Bolt Cutters', C::LOW, 'hand_tool'],
            'hardware' => ['Storz to 4" Female', C::LOW, 'hand_tool'],
            'dewalt battery' => ['M18 Batteries', C::LOW, 'battery'],
            'spare battery' => ['Spare Battery', C::LOW, 'battery'],
            'expensive power tool' => ['Dewalt Reciprocating Saw (UASI#)', C::MEDIUM, 'power_tool'],
            'hydraulic spreader' => ['Hydraulic Spreaders', C::MEDIUM, 'specialized_tool'],
            'gas meter' => ['Gas Meter', C::MEDIUM, 'detection'],
            'drager monitor' => ['Drager Gas monitor', C::MEDIUM, 'detection'],
            'complete scba' => ['SCBA #2', C::HIGH, 'scba_unit'],
            'scba plural' => ['SCBAs', C::HIGH, 'scba_unit'],
            'facepiece' => ['SCBA Facemask', C::HIGH, 'scba_facepiece'],
            'facepiece by phrase' => ['Spare facepiece', C::HIGH, 'scba_facepiece'],
            'hood' => ['Hood', C::HIGH, 'ppe'],
            'helmet' => ['Helmet', C::HIGH, 'ppe'],
            'bunker coat' => ['Bunker Coat', C::HIGH, 'ppe'],
            'bunker pants' => ['Bunker Pants', C::HIGH, 'ppe'],
            'scba accessory' => ['SCBA regulator', C::MEDIUM, 'scba_component'],
            'scba valve' => ['SCBA valve', C::MEDIUM, 'scba_component'],
            'scba cylinder' => ['SCBA Bottles', C::MEDIUM, 'scba_cylinder'],
            'cylinder with count in parentheses' => ['(2) SCBA Bottles', C::MEDIUM, 'scba_cylinder'],
            'lifepak' => ['LifePak 15', C::MEDIUM, 'rescue_medical'],
            'lucas with battery' => ['LUCAS Device W/ Spare Battery', C::MEDIUM, 'rescue_medical'],
            'backboard' => ['Backboards', C::LOW, 'rescue_medical'],
            'splints' => ['Cardboard Splints', C::LOW, 'rescue_medical'],
            'helmet shields are not helmets' => ['Helmet Shields', C::LOW, 'hand_tool'],
            'oil level is a vehicle check' => ['Engine oil level', C::MEDIUM, 'vehicle_component'],
        ];
    }

    #[DataProvider('itemCases')]
    public function test_owner_item_levels(string $item, string $level, ?string $category): void
    {
        $result = $this->classifier->classifyItem($item);

        $this->assertSame($level, $result->level, $item);
        $this->assertSame($category, $result->category, $item);
        $this->assertFalse($result->unclassified, $item);
        $this->assertContains('catalog_match', $result->reasons);
    }

    public function test_handlers_follow_the_owner_rules(): void
    {
        $this->assertSame('captain5', $this->classifier->classifyItem('LifePak', null, 'rescue')->handler);
        $this->assertSame('captain5', $this->classifier->classifyItem('Backboards', null, 'rescue')->handler);
        $this->assertSame('captain5', $this->classifier->classifyItem('Cardboard Splints', null, 'rescue')->handler);
        $this->assertSame('air_tech', $this->classifier->classifyItem('SCBA Bottles')->handler);
        $this->assertSame('air_tech', $this->classifier->classifyItem('SCBA #1')->handler);
        $this->assertSame('support_services', $this->classifier->classifyItem('Kitty Litter')->handler);
        $this->assertTrue($this->classifier->classifyItem('Bunker Coat')->isPpe);
        $this->assertTrue($this->classifier->classifyItem('Bunker Coat')->isCritical);
    }

    public function test_damaged_helmet_is_high(): void
    {
        $result = $this->classifier->classifyItem('Helmet', 'Cracked shell', 'engine', false, 'damaged');

        $this->assertSame(C::HIGH, $result->level);
    }

    public function test_cylinder_leak_is_medium_with_leak_handling_until_it_is_dangerous(): void
    {
        $leak = $this->classifier->classifyItem('SCBA Bottles', 'Cylinder valve is leaking slowly', 'engine', false, 'damaged');
        $this->assertSame(C::MEDIUM, $leak->level);
        $this->assertContains('cylinder_leak', $leak->reasons);
        $this->assertFalse($leak->hazard);
        $this->assertSame('air_tech', $leak->handler);

        foreach (['It is an uncontrolled leak', "It won't stop leaking", 'This is unsafe to use'] as $note) {
            $danger = $this->classifier->classifyItem('SCBA Bottles', $note, 'engine', false, 'damaged');
            $this->assertSame(C::HIGH, $danger->level, $note);
            $this->assertTrue($danger->hazard, $note);
            $this->assertContains('hazard_override', $danger->reasons);
        }
    }

    public function test_significant_flag_is_always_high(): void
    {
        $result = $this->classifier->classifyItem('Kitty Litter', null, null, true);

        $this->assertSame(C::HIGH, $result->level);
        $this->assertContains('significant_flag', $result->reasons);
    }

    public function test_unknown_item_is_medium_unclassified(): void
    {
        $result = $this->classifier->classifyItem('Zorblax Widget 9000');

        $this->assertSame(C::MEDIUM, $result->level);
        $this->assertTrue($result->unclassified);
        $this->assertSame(['unclassified_fallback'], $result->reasons);
        $this->assertSame('other', $result->category);
    }

    public function test_unclear_scba_is_not_downgraded(): void
    {
        $result = $this->classifier->classifyItem('SCBA thing');

        $this->assertSame(C::MEDIUM, $result->level);
        $this->assertTrue($result->unclassified);
        $this->assertContains('unclear_scba', $result->reasons);
        $this->assertSame('air_tech', $result->handler);
    }

    public function test_mixed_findings_aggregate_to_the_highest_level_and_keep_their_own(): void
    {
        $results = array_map(fn (string $item) => $this->classifier->classifyItem($item), ['Kitty Litter', 'Gas Meter', 'SCBA #1']);

        $this->assertSame([C::LOW, C::MEDIUM, C::HIGH], array_map(static fn ($r) => $r->level, $results));
        $this->assertSame(C::HIGH, C::highest(...array_map(static fn ($r) => $r->level, $results)));
        $this->assertSame(C::MEDIUM, C::highest(C::LOW, C::MEDIUM));
    }

    public function test_apparatus_scoped_rule_beats_the_everywhere_rule(): void
    {
        $admin = User::factory()->create();
        app(EquipmentRuleService::class)->create(['name' => 'Absorbent Pads', 'level' => C::MEDIUM, 'category' => 'consumable', 'apparatus_scope' => 'fireboat6'], $admin);

        $this->assertSame(C::MEDIUM, $this->classifier->classifyItem('Absorbent Pads', null, 'fireboat6')->level);
        $this->assertSame(C::LOW, $this->classifier->classifyItem('Absorbent Pads', null, 'engine')->level);
        $this->assertSame(C::LOW, $this->classifier->classifyItem('Absorbent Pads')->level);
    }

    public function test_protective_equipment_phrase_outranks_a_longer_low_phrase_in_an_item_name(): void
    {
        $this->assertSame(C::HIGH, $this->classifier->classifyItem('Spare helmet liner kit')->level);
    }

    /** @return array<string, array{string, string, string}> */
    public static function textCases(): array
    {
        return [
            'station a/c' => ['The A/C is not working in the dayroom', 'station', C::MEDIUM],
            'air conditioner' => ['Air conditioning unit is out at Station 4', 'station', C::MEDIUM],
            'routine supplies' => ['We are out of paper towels and trash bags', 'supply', C::LOW],
            'kitty litter request' => ['Need kitty litter for the engine', 'supply', C::LOW],
            'electrical sparking' => ['The outlet by the kitchen is sparking', 'station', C::HIGH],
            'gas smell' => ['We smell gas near the water heater', 'station', C::HIGH],
            'engine 2 light bar' => ['Engine 2 light bar is dim', 'support', C::MEDIUM],
            'bunker gear dropped off' => ['I dropped off my bunker gear for cleaning', 'support', C::LOW],
            'bunker gear damaged' => ['My bunker coat is torn at the sleeve', 'support', C::HIGH],
            'new helmet needed' => ['Need a new helmet', 'supply', C::HIGH],
            'smoke detector battery' => ['Smoke detector battery is chirping', 'station', C::LOW],
            'no smoke' => ['No smoke seen, just a dead bulb', 'station', C::LOW],
        ];
    }

    #[DataProvider('textCases')]
    public function test_free_text_levels(string $text, string $context, string $level): void
    {
        $this->assertSame($level, $this->classifier->classifyText($text, $context)->level, $text);
    }

    public function test_broad_words_alone_never_make_text_high(): void
    {
        foreach (['The light is out', 'Engine 2 pump is slow', 'Fire inspection forms', 'Monitor on the wall', 'Need gear bags', 'Light bar on Engine 2'] as $text) {
            $this->assertNotSame(C::HIGH, $this->classifier->classifyText($text, 'support')->level, $text);
        }
    }

    public function test_station_a_c_is_a_facility_item(): void
    {
        $result = $this->classifier->classifyText('The A/C is not working', 'station');

        $this->assertSame('station_facility', $result->category);
        $this->assertContains('catalog_match', $result->reasons);
    }

    public function test_hazard_text_is_high_with_300_always(): void
    {
        $result = $this->classifier->classifyText('Kitty litter is smoking and there is a burning smell', 'support');

        $this->assertSame(C::HIGH, $result->level);
        $this->assertTrue($result->hazard);
        $this->assertSame('always', $result->contact300);
    }

    public function test_unknown_text_is_medium_unclassified(): void
    {
        $result = $this->classifier->classifyText('Thanks for the help yesterday', 'support');

        $this->assertSame(C::MEDIUM, $result->level);
        $this->assertTrue($result->unclassified);
        $this->assertSame(['unclassified_fallback'], $result->reasons);
    }

    public function test_default_catalog_has_no_duplicate_phrases_and_respects_the_limits(): void
    {
        $seen = [];
        foreach (DefaultEquipmentCatalog::rows() as $row) {
            $this->assertLessThanOrEqual(120, mb_strlen($row['name']));
            $this->assertLessThanOrEqual(30, count($row['aliases']), $row['name']);
            $this->assertContains($row['level'], C::LEVELS);
            $this->assertContains($row['category'], C::CATEGORIES);
            $this->assertContains($row['handler'], C::HANDLERS);
            $tokens = [PhraseText::key($row['name']), ...array_map(static fn (string $a): string => (str_ends_with($a, '*') ? '*' : '').PhraseText::key($a), $row['aliases'])];
            foreach (array_unique($tokens) as $token) {
                $this->assertArrayNotHasKey($token, $seen, "{$token} is in both {$row['name']} and ".($seen[$token] ?? ''));
                $seen[$token] = $row['name'];
            }
            foreach ($row['aliases'] as $alias) {
                $this->assertLessThanOrEqual(60, mb_strlen($alias));
            }
        }
    }

    public function test_the_checklists_are_mostly_classified_by_the_default_catalog(): void
    {
        $unclassified = app(EquipmentRuleService::class)->unclassifiedChecklistItems(null, 1000);

        $this->assertLessThan(20, count($unclassified), 'The default catalog should leave only genuinely ambiguous checklist items unclassified.');
        $names = array_column($unclassified, 'name');
        // Position gear sets (PPE, Officer Gear, F-1 Gear...) are HIGH protective equipment, not left unclassified.
        $this->assertNotContains('PPE', $names);
        $this->assertNotContains('Officer Gear', $names);
        $this->assertSame('high', app(\App\Services\SupportTriage\Contracts\EquipmentClassifier::class)->classifyItem('Officer Gear', null, 'engine', false, 'missing')->level);
        $this->assertNotContains('Kitty Litter', $names);
        $this->assertNotContains('SCBA #1', $names);
    }
}
