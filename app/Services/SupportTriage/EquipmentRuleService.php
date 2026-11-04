<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\AiAutomation\AiAutomationSetting;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\SupportTriage\SupportEquipmentRuleRevision;
use App\Models\User;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of the equipment catalog. Every write validates, saves the rule, appends a revision with the
 * full values and who saved them, raises the rule's version, and bumps the catalog's policy version, all in one
 * transaction. Callers (the admin screens) check permissions; this class does not.
 *
 * Nothing here touches open records: after a change an administrator previews and applies it with
 * TriageProjector::previewReapply() / reapply().
 */
final class EquipmentRuleService
{
    public const APPARATUS_SCOPES = ['engine', 'engine2', 'ladder1', 'ladder3', 'rescue', 'fireboat6', 'default'];

    public const CONTACT_OPTIONS = ['never', 'urgent', 'always'];

    /** Who follows up when an administrator does not choose. */
    public const CATEGORY_HANDLERS = [
        'scba_unit' => 'air_tech', 'scba_facepiece' => 'air_tech', 'scba_component' => 'air_tech', 'scba_cylinder' => 'air_tech',
        'rescue_medical' => 'captain5', 'vehicle_component' => 'support_services',
    ];

    private const MEMBER_RESPONSE_MAX = 500;

    /** @var list<string> */
    private const RULE_FIELDS = ['name', 'aliases', 'category', 'level', 'handler', 'apparatus_scope', 'member_response', 'is_ppe', 'is_critical', 'is_expensive', 'contact_300', 'active'];

    // ------------------------------------------------------------------ writes

    /**
     * @param  array<string, mixed>  $data  name, level, category are required; the rest default sensibly
     */
    public function create(array $data, User $editor): SupportEquipmentRule
    {
        $values = $this->validated($this->withDefaults($data), null);

        return $this->commit(function () use ($values, $editor): SupportEquipmentRule {
            $rule = new SupportEquipmentRule([
                ...$values,
                'normalized_name' => PhraseText::key($values['name']),
                'source' => SupportEquipmentRule::SOURCE_ADMIN,
                'version' => 0,
            ]);
            $this->persist($rule, $editor);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data  only the fields to change
     */
    public function update(SupportEquipmentRule $rule, array $data, User $editor): SupportEquipmentRule
    {
        $changed = false;

        return $this->commit(function () use ($rule, $data, $editor, &$changed): SupportEquipmentRule {
            $locked = $this->lock($rule);
            $changed = $this->change($locked, $data, $editor);

            return $locked->refresh();
        }, bump: function () use (&$changed): bool {
            return $changed;
        });
    }

    public function setLevel(SupportEquipmentRule $rule, string $level, User $editor): SupportEquipmentRule
    {
        return $this->update($rule, ['level' => $level], $editor);
    }

    /**
     * @param  list<int>  $ruleIds
     * @return int rules whose level actually changed
     */
    public function bulkSetLevel(array $ruleIds, string $level, User $editor): int
    {
        $this->assertIn($level, EquipmentClassification::LEVELS, 'level', 'Choose Low, Medium or High.');
        $ids = array_values(array_unique(array_map('intval', $ruleIds)));
        if ($ids === []) {
            return 0;
        }

        return $this->commit(function () use ($ids, $level, $editor): int {
            $changed = 0;
            foreach (SupportEquipmentRule::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $rule) {
                if ($rule->level !== $level && $this->change($rule, ['level' => $level], $editor)) {
                    $changed++;
                }
            }

            return $changed;
        }, bump: static fn (mixed $result): bool => $result > 0);
    }

    /** Puts a previous version's values back as a new version (history is never rewritten). */
    public function restore(SupportEquipmentRule $rule, int $version, User $editor): SupportEquipmentRule
    {
        $changed = false;

        return $this->commit(function () use ($rule, $version, $editor, &$changed): SupportEquipmentRule {
            $locked = $this->lock($rule);
            $revision = SupportEquipmentRuleRevision::query()->where('rule_id', $locked->getKey())->where('version', $version)->first();
            if ($revision === null) {
                throw ValidationException::withMessages(['version' => 'That version of this rule does not exist.']);
            }
            $snapshot = array_intersect_key($revision->snapshot, array_flip(self::RULE_FIELDS));
            $changed = $this->change($locked, $snapshot, $editor);

            return $locked->refresh();
        }, bump: function () use (&$changed): bool {
            return $changed;
        });
    }

    public function deactivate(SupportEquipmentRule $rule, User $editor): SupportEquipmentRule
    {
        return $this->update($rule, ['active' => false], $editor);
    }

    public function activate(SupportEquipmentRule $rule, User $editor): SupportEquipmentRule
    {
        return $this->update($rule, ['active' => true], $editor);
    }

    /** Creates a rule for a checklist item that nothing in the catalog matched. */
    public function classifyChecklistItem(string $name, string $level, User $editor, ?string $category = null): SupportEquipmentRule
    {
        $category ??= 'other';
        $isHigh = $level === EquipmentClassification::HIGH;

        return $this->create([
            'name' => trim($name),
            'level' => $level,
            'category' => $category,
            'handler' => self::CATEGORY_HANDLERS[$category] ?? 'support_services',
            'is_ppe' => in_array($category, ['ppe', 'scba_unit', 'scba_facepiece'], true),
            'is_critical' => $isHigh,
            'is_expensive' => $level !== EquipmentClassification::LOW,
            'contact_300' => $this->contactFor($level),
        ], $editor);
    }

    // ------------------------------------------------------------- seeding

    /**
     * Adds the default catalog rows that are not there yet. A row that already exists (by name and checklist
     * scope), whether seeded earlier or edited by an administrator, is never touched; an alias another rule
     * already owns is left off the seeded row so the administrator's rule keeps it.
     *
     * @return int rows added
     */
    public function seedDefaults(): int
    {
        $existing = SupportEquipmentRule::query()->get(['id', 'normalized_name', 'apparatus_scope', 'aliases', 'active']);
        $names = [];
        $taken = [];
        foreach ($existing as $row) {
            $names[$row->normalized_name.'|'.($row->apparatus_scope ?? '')] = true;
            if ($row->active) {
                foreach ($this->tokensOf((string) $row->normalized_name, $row->aliases ?? []) as $token) {
                    $taken[($row->apparatus_scope ?? '').'|'.$token] = true;
                }
            }
        }

        $added = 0;
        DB::transaction(function () use (&$added, &$names, &$taken): void {
            foreach (DefaultEquipmentCatalog::rows() as $row) {
                $normalized = PhraseText::key($row['name']);
                $scope = $row['apparatus_scope'] ?? null;
                if (isset($names[$normalized.'|'.($scope ?? '')])) {
                    continue;
                }
                $aliases = [];
                foreach ($row['aliases'] as $alias) {
                    $token = $this->tokenOf($alias);
                    if ($token !== null && ! isset($taken[($scope ?? '').'|'.$token])) {
                        $aliases[] = $alias;
                    }
                }
                $rule = new SupportEquipmentRule([
                    ...$row,
                    'aliases' => $aliases,
                    'normalized_name' => $normalized,
                    'source' => SupportEquipmentRule::SOURCE_SEEDED,
                    'active' => true,
                    'version' => 0,
                ]);
                $this->persist($rule, null);
                $names[$normalized.'|'.($scope ?? '')] = true;
                foreach ($this->tokensOf($normalized, $aliases) as $token) {
                    $taken[($scope ?? '').'|'.$token] = true;
                }
                $added++;
            }
            if ($added > 0) {
                $this->bumpPolicyVersion();
            }
        });
        CatalogEquipmentClassifier::forget();

        return $added;
    }

    // ------------------------------------------------------- unclassified items

    /**
     * Checklist item names the catalog has no rule for, most common first, so an administrator can classify them.
     *
     * @return list<array{name: string, apparatus_types: list<string>, occurrences: int}>
     */
    public function unclassifiedChecklistItems(?string $search = null, int $limit = 200): array
    {
        $classifier = app(EquipmentClassifier::class);
        $needle = $search !== null ? mb_strtolower(trim($search)) : '';
        $found = [];

        foreach ($this->checklistItemNames() as $type => $names) {
            foreach ($names as $name => $count) {
                if ($needle !== '' && ! str_contains(mb_strtolower($name), $needle)) {
                    continue;
                }
                if (! $classifier->classifyItem($name, null, $type)->unclassified) {
                    continue;
                }
                $found[$name]['types'][$type] = true;
                $found[$name]['count'] = ($found[$name]['count'] ?? 0) + $count;
            }
        }

        $rows = [];
        foreach ($found as $name => $info) {
            $rows[] = ['name' => (string) $name, 'apparatus_types' => array_keys($info['types']), 'occurrences' => $info['count']];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['occurrences'], $a['name']] <=> [$a['occurrences'], $b['name']]);

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Item names on the Daily Checkout checklists, by checklist type.
     *
     * @return array<string, array<string, int>> type => name => occurrences
     */
    private function checklistItemNames(): array
    {
        $directory = base_path('storage/checklists');
        $result = [];
        foreach (DailyCheckoutChecklistTemplate::cases() as $template) {
            if (! $template->isConfigured()) {
                continue;
            }
            $path = $directory.'/'.$template->value.'_checklist.json';
            $contents = is_file($path) ? file_get_contents($path) : false;
            $checklist = $contents === false ? null : json_decode($contents, true);
            if (! is_array($checklist)) {
                continue;
            }
            foreach ((array) ($checklist['compartments'] ?? []) as $compartment) {
                foreach ((array) ($compartment['items'] ?? []) as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    if ($name !== '') {
                        $result[$template->value][$name] = ($result[$template->value][$name] ?? 0) + 1;
                    }
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------ internals

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @param  (callable(mixed): bool)|null  $bump  when given, decides from the work's result whether the policy version rises
     * @return T
     */
    private function commit(callable $work, ?callable $bump = null): mixed
    {
        $result = DB::transaction(function () use ($work, $bump): mixed {
            $result = $work();
            if ($bump === null || $bump($result)) {
                $this->bumpPolicyVersion();
            }

            return $result;
        });
        CatalogEquipmentClassifier::forget();

        return $result;
    }

    private function lock(SupportEquipmentRule $rule): SupportEquipmentRule
    {
        return SupportEquipmentRule::query()->lockForUpdate()->findOrFail($rule->getKey());
    }

    /**
     * Applies changed fields to a locked rule and records the revision. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $data
     */
    private function change(SupportEquipmentRule $rule, array $data, User $editor): bool
    {
        $merged = [...$rule->snapshot(), ...array_intersect_key($data, array_flip(self::RULE_FIELDS))];
        $values = $this->validated($merged, $rule);
        $current = $rule->snapshot();
        $values['aliases'] = array_values($values['aliases']);
        if ($values == array_intersect_key($current, $values)) {
            return false;
        }

        $rule->fill([...$values, 'normalized_name' => PhraseText::key($values['name'])]);
        $this->persist($rule, $editor);

        return true;
    }

    /** Saves the rule at its next version and appends the matching revision. */
    private function persist(SupportEquipmentRule $rule, ?User $editor): void
    {
        $rule->version = (int) $rule->version + 1;
        $rule->updated_by_user_id = $editor?->getKey();
        $rule->save();

        SupportEquipmentRuleRevision::query()->create([
            'rule_id' => $rule->getKey(),
            'version' => $rule->version,
            'snapshot' => $rule->snapshot(),
            'edited_by_user_id' => $editor?->getKey(),
            'edited_by_name' => $editor === null ? 'Default catalog' : (string) ($editor->display_name ?: $editor->name),
        ]);
    }

    private function bumpPolicyVersion(): void
    {
        $setting = AiAutomationSetting::query()->orderBy('id')->lockForUpdate()->first()
            ?? AiAutomationSetting::query()->create(['enabled' => false, 'epoch' => 1]);
        $setting->forceFill(['triage_policy_version' => max(1, (int) $setting->getAttribute('triage_policy_version')) + 1])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withDefaults(array $data): array
    {
        $level = (string) ($data['level'] ?? '');
        $category = (string) ($data['category'] ?? 'other');

        return [
            'aliases' => [],
            'handler' => self::CATEGORY_HANDLERS[$category] ?? 'support_services',
            'apparatus_scope' => null,
            'member_response' => null,
            'is_ppe' => in_array($category, ['ppe', 'scba_unit', 'scba_facepiece'], true),
            'is_critical' => $level === EquipmentClassification::HIGH,
            'is_expensive' => false,
            'contact_300' => $this->contactFor($level),
            'active' => true,
            ...$data,
        ];
    }

    private function contactFor(string $level): string
    {
        return match ($level) {
            EquipmentClassification::HIGH => 'always',
            EquipmentClassification::MEDIUM => 'urgent',
            default => 'never',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, ?SupportEquipmentRule $existing): array
    {
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['aliases'] = $this->cleanAliases($data['aliases'] ?? []);
        if (array_key_exists('member_response', $data)) {
            $data['member_response'] = filled($data['member_response']) ? trim((string) $data['member_response']) : null;
        }
        if (($data['apparatus_scope'] ?? null) === '') {
            $data['apparatus_scope'] = null;
        }

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'aliases' => ['array', 'max:30'],
            'aliases.*' => ['string', 'max:60'],
            'category' => ['required', 'in:'.implode(',', EquipmentClassification::CATEGORIES)],
            'level' => ['required', 'in:'.implode(',', EquipmentClassification::LEVELS)],
            'handler' => ['required', 'in:'.implode(',', EquipmentClassification::HANDLERS)],
            'apparatus_scope' => ['nullable', 'in:'.implode(',', self::APPARATUS_SCOPES)],
            'member_response' => ['nullable', 'string', 'max:'.self::MEMBER_RESPONSE_MAX],
            'is_ppe' => ['boolean'],
            'is_critical' => ['boolean'],
            'is_expensive' => ['boolean'],
            'contact_300' => ['required', 'in:'.implode(',', self::CONTACT_OPTIONS)],
            'active' => ['boolean'],
        ], [
            'name.required' => 'Give the equipment a name.',
            'name.max' => 'The name can be up to 120 characters.',
            'aliases.max' => 'Use up to 30 other names.',
            'aliases.*.max' => 'Each other name can be up to 60 characters.',
            'level.in' => 'Choose Low, Medium or High.',
            'category.in' => 'Choose a category from the list.',
            'handler.in' => 'Choose who follows up from the list.',
            'member_response.max' => 'The member message can be up to '.self::MEMBER_RESPONSE_MAX.' characters.',
        ]);
        $validator->after(function ($validator) use ($data, $existing): void {
            if (filled($data['member_response'] ?? null) && $this->hasContactDetails((string) $data['member_response'])) {
                $validator->errors()->add('member_response', 'Leave web links, phone numbers and email addresses out of the member message.');
            }
            if ($validator->errors()->isEmpty()) {
                foreach ($this->conflicts($data, $existing) as $message) {
                    $validator->errors()->add('name', $message);
                }
            }
        });
        $validated = $validator->validate();

        return [
            'name' => $data['name'],
            'aliases' => $data['aliases'],
            'category' => $validated['category'],
            'level' => $validated['level'],
            'handler' => $validated['handler'],
            'apparatus_scope' => $validated['apparatus_scope'] ?? null,
            'member_response' => $data['member_response'] ?? null,
            'is_ppe' => (bool) ($validated['is_ppe'] ?? false),
            'is_critical' => (bool) ($validated['is_critical'] ?? false),
            'is_expensive' => (bool) ($validated['is_expensive'] ?? false),
            'contact_300' => $validated['contact_300'],
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    /**
     * @return list<string>
     */
    private function cleanAliases(mixed $aliases): array
    {
        if (is_string($aliases)) {
            $aliases = preg_split('/\R|,/u', $aliases) ?: [];
        }
        $clean = [];
        foreach ((array) $aliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '' && PhraseText::key($alias) !== '') {
                $clean[mb_strtolower($alias)] = $alias;
            }
        }

        return array_values($clean);
    }

    private function hasContactDetails(string $text): bool
    {
        return preg_match('~(?:https?://|www\.)~i', $text) === 1
            || preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text) === 1
            || preg_match('/(?:\+?\d[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', $text) === 1
            || preg_match('/\b\d{3}[\s.-]\d{4}\b/', $text) === 1;
    }

    /**
     * Messages for any name or alias another rule in the same scope already answers for.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function conflicts(array $data, ?SupportEquipmentRule $existing): array
    {
        $scope = $data['apparatus_scope'] ?? null;
        $mine = $this->tokensOf(PhraseText::key((string) $data['name']), $data['aliases']);
        $messages = [];
        $query = $scope === null
            ? SupportEquipmentRule::query()->whereNull('apparatus_scope')
            : SupportEquipmentRule::query()->where('apparatus_scope', $scope);
        if ($existing !== null) {
            $query->where('id', '!=', $existing->getKey());
        }
        foreach ($query->get(['id', 'name', 'normalized_name', 'aliases', 'active']) as $other) {
            $theirs = $other->active
                ? $this->tokensOf((string) $other->normalized_name, $other->aliases ?? [])
                : ['exact:'.$other->normalized_name];
            $shared = array_intersect($mine, $theirs);
            if ($shared !== [] && ($other->active || in_array('exact:'.PhraseText::key((string) $data['name']), $shared, true))) {
                $messages[] = '"'.$other->name.'" already covers '.$this->describeToken((string) reset($shared)).'. Edit that one instead.';
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  list<string>  $aliases
     * @return list<string>
     */
    private function tokensOf(string $nameKey, array $aliases): array
    {
        $tokens = $nameKey === '' ? [] : ['exact:'.$nameKey];
        foreach ($aliases as $alias) {
            $token = $this->tokenOf((string) $alias);
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function tokenOf(string $alias): ?string
    {
        $key = PhraseText::key($alias);

        return $key === '' ? null : (PhraseText::isPattern($alias) ? 'pattern:' : 'exact:').$key;
    }

    private function describeToken(string $token): string
    {
        return '"'.substr($token, (int) strpos($token, ':') + 1).(str_starts_with($token, 'pattern:') ? '*' : '').'"';
    }

    /** @param list<string> $allowed */
    private function assertIn(string $value, array $allowed, string $field, string $message): void
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
