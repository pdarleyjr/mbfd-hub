<?php

declare(strict_types=1);

namespace Tests\Feature\SupportTriage;

use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\SupportTriage\SupportEquipmentRuleRevision;
use App\Models\User;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification as C;
use App\Services\SupportTriage\EquipmentRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class EquipmentRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private EquipmentRuleService $rules;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = app(EquipmentRuleService::class);
        $this->admin = User::factory()->create(['name' => 'Alex Admin']);
    }

    public function test_seeding_adds_the_defaults_once_and_never_overwrites_an_edit(): void
    {
        $added = $this->rules->seedDefaults();
        $this->assertGreaterThan(40, $added);
        $this->assertSame(0, $this->rules->seedDefaults(), 'running again adds nothing');

        $rule = SupportEquipmentRule::query()->where('name', 'Absorbents and kitty litter')->firstOrFail();
        $this->rules->setLevel($rule, C::MEDIUM, $this->admin);

        $this->rules->seedDefaults();
        $this->assertSame(C::MEDIUM, $rule->fresh()->level, 'an administrator edit survives a re-seed');
        $this->assertSame(SupportEquipmentRule::SOURCE_SEEDED, $rule->fresh()->source);
        $this->assertSame(2, $rule->fresh()->version);
    }

    public function test_seeding_leaves_an_alias_an_administrator_rule_already_owns(): void
    {
        $this->rules->create(['name' => 'Litter special', 'aliases' => ['Cat Litter*', 'Kitty Litter'], 'level' => C::HIGH, 'category' => 'consumable'], $this->admin);
        $this->rules->seedDefaults();

        $seeded = SupportEquipmentRule::query()->where('name', 'Absorbents and kitty litter')->firstOrFail();
        $this->assertNotContains('Cat Litter*', $seeded->aliases);
        $this->assertContains('Kitty Litter*', $seeded->aliases);
        $classifier = app(EquipmentClassifier::class);
        $this->assertSame(C::HIGH, $classifier->classifyItem('Kitty Litter')->level, 'the administrator rule answers the exact name');
        $this->assertSame(C::HIGH, $classifier->classifyItem('Cat Litter')->level);
        $this->assertSame(C::LOW, $classifier->classifyItem('Kitty Litter Bucket')->level, 'the seeded pattern still covers other names');
    }

    public function test_create_validates_and_records_version_one_with_the_editor(): void
    {
        $rule = $this->rules->create([
            'name' => 'Widget Wrench',
            'aliases' => ['widget key', 'widgets*'],
            'level' => C::LOW,
            'category' => 'hand_tool',
        ], $this->admin);

        $this->assertSame(1, $rule->version);
        $this->assertSame(SupportEquipmentRule::SOURCE_ADMIN, $rule->source);
        $this->assertSame('support_services', $rule->handler);
        $revision = SupportEquipmentRuleRevision::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(1, $revision->version);
        $this->assertSame('Alex Admin', $revision->edited_by_name);
        $this->assertSame('Widget Wrench', $revision->snapshot['name']);
    }

    public function test_validation_limits(): void
    {
        $base = ['name' => 'Thing', 'level' => C::LOW, 'category' => 'other'];
        $bad = [
            'long name' => [...$base, 'name' => str_repeat('a', 121)],
            'bad level' => [...$base, 'level' => 'urgent'],
            'bad category' => [...$base, 'category' => 'bogus'],
            'bad handler' => [...$base, 'handler' => 'nobody'],
            'too many aliases' => [...$base, 'aliases' => array_map(static fn (int $i): string => "alias {$i}", range(1, 31))],
            'long alias' => [...$base, 'aliases' => [str_repeat('b', 61)]],
            'long response' => [...$base, 'member_response' => str_repeat('c', 501)],
            'url' => [...$base, 'member_response' => 'See https://example.com for more'],
            'email' => [...$base, 'member_response' => 'Write to someone@example.org'],
            'phone' => [...$base, 'member_response' => 'Call 305-555-0123 today'],
        ];
        foreach ($bad as $label => $data) {
            try {
                $this->rules->create($data, $this->admin);
                $this->fail("{$label} should be refused");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, SupportEquipmentRule::query()->count());

        $ok = $this->rules->create([...$base, 'aliases' => array_map(static fn (int $i): string => "alias {$i}", range(1, 30)), 'member_response' => str_repeat('c', 500)], $this->admin);
        $this->assertCount(30, $ok->aliases);
    }

    public function test_a_name_or_alias_another_rule_already_answers_for_is_refused(): void
    {
        $this->rules->create(['name' => 'Backboard', 'aliases' => ['long board'], 'level' => C::LOW, 'category' => 'rescue_medical'], $this->admin);

        foreach ([['name' => 'Backboards'], ['name' => 'Something else', 'aliases' => ['Long Boards']]] as $extra) {
            try {
                $this->rules->create([...['level' => C::LOW, 'category' => 'other'], ...$extra], $this->admin);
                $this->fail('A duplicate must be refused.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Backboard', $exception->errors()['name'][0]);
            }
        }
        // The same name for a different checklist is fine.
        $scoped = $this->rules->create(['name' => 'Backboard', 'level' => C::MEDIUM, 'category' => 'rescue_medical', 'apparatus_scope' => 'rescue'], $this->admin);
        $this->assertSame('rescue', $scoped->apparatus_scope);
    }

    public function test_changing_a_level_bumps_the_version_the_policy_version_and_the_classifier(): void
    {
        $classifier = app(EquipmentClassifier::class);
        $rule = $this->rules->create(['name' => 'Pike Pole', 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $policyBefore = $classifier->policyVersion();
        $this->assertSame(C::LOW, $classifier->classifyItem('Pike Pole')->level);

        $changed = $this->rules->setLevel($rule, C::MEDIUM, $this->admin);

        $this->assertSame(2, $changed->version);
        $this->assertSame($policyBefore + 1, $classifier->policyVersion());
        $this->assertSame(C::MEDIUM, $classifier->classifyItem('Pike Pole')->level, 'the same request sees the new catalog');
        $this->assertSame(2, SupportEquipmentRuleRevision::query()->where('rule_id', $rule->id)->count());
    }

    public function test_saving_nothing_new_changes_no_version(): void
    {
        $rule = $this->rules->create(['name' => 'Pike Pole', 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $policy = app(EquipmentClassifier::class)->policyVersion();

        $same = $this->rules->update($rule, ['level' => C::LOW, 'name' => 'Pike Pole'], $this->admin);

        $this->assertSame(1, $same->version);
        $this->assertSame($policy, app(EquipmentClassifier::class)->policyVersion());
        $this->assertSame(1, SupportEquipmentRuleRevision::query()->where('rule_id', $rule->id)->count());
    }

    public function test_restore_puts_back_an_earlier_version_as_a_new_version(): void
    {
        $rule = $this->rules->create(['name' => 'Pike Pole', 'aliases' => ['hook pole'], 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $this->rules->update($rule, ['level' => C::HIGH, 'aliases' => []], $this->admin);

        $restored = $this->rules->restore($rule, 1, $this->admin);

        $this->assertSame(C::LOW, $restored->level);
        $this->assertSame(['hook pole'], $restored->aliases);
        $this->assertSame(3, $restored->version);
        $this->assertSame([3, 2, 1], $restored->revisions->pluck('version')->all());

        $this->expectException(ValidationException::class);
        $this->rules->restore($rule, 99, $this->admin);
    }

    public function test_revisions_cannot_be_edited_or_deleted(): void
    {
        $rule = $this->rules->create(['name' => 'Pike Pole', 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $revision = SupportEquipmentRuleRevision::query()->where('rule_id', $rule->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $revision->delete();
    }

    public function test_bulk_set_level_counts_only_real_changes_and_bumps_policy_once(): void
    {
        $a = $this->rules->create(['name' => 'Alpha Tool', 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $b = $this->rules->create(['name' => 'Beta Tool', 'level' => C::MEDIUM, 'category' => 'hand_tool'], $this->admin);
        $c = $this->rules->create(['name' => 'Gamma Tool', 'level' => C::LOW, 'category' => 'hand_tool'], $this->admin);
        $policy = app(EquipmentClassifier::class)->policyVersion();

        $changed = $this->rules->bulkSetLevel([$a->id, $b->id, $c->id], C::MEDIUM, $this->admin);

        $this->assertSame(2, $changed);
        $this->assertSame($policy + 1, app(EquipmentClassifier::class)->policyVersion());
        $this->assertSame(0, $this->rules->bulkSetLevel([$a->id, $b->id], C::MEDIUM, $this->admin));
        $this->assertSame($policy + 1, app(EquipmentClassifier::class)->policyVersion());
    }

    public function test_deactivated_rules_stop_matching(): void
    {
        $rule = $this->rules->create(['name' => 'Zorblax', 'level' => C::HIGH, 'category' => 'ppe'], $this->admin);
        $classifier = app(EquipmentClassifier::class);
        $this->assertSame(C::HIGH, $classifier->classifyItem('Zorblax')->level);

        $this->rules->deactivate($rule, $this->admin);

        $this->assertTrue($classifier->classifyItem('Zorblax')->unclassified);
        $this->rules->activate($rule, $this->admin);
        $this->assertSame(C::HIGH, $classifier->classifyItem('Zorblax')->level);
    }

    public function test_unclassified_checklist_items_can_be_listed_searched_and_classified(): void
    {
        $this->rules->seedDefaults();
        $items = $this->rules->unclassifiedChecklistItems();
        $names = array_column($items, 'name');
        $this->assertContains('Coil Pack', $names);

        $coil = $items[array_search('Coil Pack', $names, true)];
        $this->assertGreaterThan(0, $coil['occurrences']);
        $this->assertNotEmpty($coil['apparatus_types']);
        $this->assertSame([], array_filter($this->rules->unclassifiedChecklistItems('zzzz-nothing')));
        $this->assertSame(['Coil Pack'], array_column($this->rules->unclassifiedChecklistItems('coil pack'), 'name'));

        $rule = $this->rules->classifyChecklistItem('Coil Pack', C::LOW, $this->admin, 'other');

        $this->assertSame(C::LOW, $rule->level);
        $this->assertNotContains('Coil Pack', array_column($this->rules->unclassifiedChecklistItems(), 'name'));
        $this->assertSame(C::LOW, app(EquipmentClassifier::class)->classifyItem('Coil Pack')->level);
    }
}
