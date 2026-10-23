<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\Captain5\Captain5ItemClassification;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionPaperFields;
use Carbon\CarbonImmutable;

/**
 * The Admin Panel's Medical scope review queue: Engine and Ladder checklist items that are not
 * yet classified as medical or not medical, plus the decisions administrators already made.
 *
 * An item waits here when MedicalScope cannot decide it: a mapped `ambiguous` item, an item the
 * reviewed template did not contain (for example an inventory addition), or any item of a
 * template that changed after its last review. Nothing here is ever shown to Captain 5 until an
 * administrator decides it.
 *
 * @phpstan-type PendingRow array{checklist_type: string, checklist_label: string, item_key: string, item_name: string, compartment: string|null, reason: string, reason_label: string, open_issues: int}
 * @phpstan-type DecidedRow array{checklist_type: string, checklist_label: string, item_key: string, item_name: string, classification: string, classification_label: string, reason: string, decided_by: string, decided_at: string}
 */
final class MedicalScopeReview
{
    public const REASON_AMBIGUOUS = 'ambiguous';

    public const REASON_NEW_ITEM = 'new_item';

    public const REASON_TEMPLATE_CHANGED = 'template_changed';

    private const REASON_LABELS = [
        self::REASON_AMBIGUOUS => 'Medical role depends on how the department uses it',
        self::REASON_NEW_ITEM => 'Added after the last medical review',
        self::REASON_TEMPLATE_CHANGED => 'Checklist changed since its last medical review',
    ];

    public function __construct(
        private readonly MedicalScope $medical,
        private readonly Captain5IssueScope $scope,
        private readonly DailyCheckoutChecklistResolver $resolver,
        private readonly InspectionPaperFields $paperFields,
    ) {}

    /**
     * @return array{pending: list<PendingRow>, decided: list<DecidedRow>, unmatched_open_issues: int}
     */
    public function build(): array
    {
        $openIssues = $this->openUnclassifiedIssues();
        $pending = [];

        foreach ($this->checklistItems() as $type => $items) {
            foreach ($items as $key => $info) {
                if ($this->medical->classify($type, (string) $key) !== MedicalScope::UNCLASSIFIED) {
                    continue;
                }
                $pending[$type.'|'.$key] = $this->pendingRow($type, (string) $key, $info['name'], $info['compartment'], $openIssues['counts'][$type.'|'.$key]['count'] ?? 0);
            }
        }
        // An open issue on an item that is no longer on any checklist still needs a decision.
        foreach ($openIssues['counts'] as $id => $issue) {
            if (! isset($pending[$id])) {
                $pending[$id] = $this->pendingRow($issue['type'], $issue['key'], $issue['name'], $issue['compartment'], $issue['count']);
            }
        }

        $rows = array_values($pending);
        $order = array_flip(MedicalScope::MEDICAL_ONLY_TYPES);
        usort($rows, static fn (array $a, array $b): int => [$b['open_issues'], $order[$a['checklist_type']] ?? 99, $a['item_name']]
            <=> [$a['open_issues'], $order[$b['checklist_type']] ?? 99, $b['item_name']]);

        return ['pending' => $rows, 'decided' => $this->decided(), 'unmatched_open_issues' => $openIssues['unmatched']];
    }

    public static function label(string $checklistType): string
    {
        return ucfirst((string) preg_replace('/(\d+)$/', ' $1', $checklistType));
    }

    /**
     * Every item on a medical-scope checklist: the template files plus what each Engine/Ladder
     * apparatus actually issues (which carries inventory additions).
     *
     * @return array<string, array<string, array{name: string, compartment: string|null}>>
     */
    private function checklistItems(): array
    {
        $items = array_fill_keys(MedicalScope::MEDICAL_ONLY_TYPES, []);

        foreach (MedicalScope::MEDICAL_ONLY_TYPES as $type) {
            $template = $this->medical->baselineTemplate($type);
            if ($template !== null) {
                $this->collect($items, $type, $template);
            }
        }

        $ids = $this->scope->candidateApparatusIds();
        foreach ($ids === [] ? [] : Apparatus::query()->whereIn('id', $ids)->get() as $apparatus) {
            $type = $this->scope->checklistType((int) $apparatus->getKey());
            if (! in_array($type, MedicalScope::MEDICAL_ONLY_TYPES, true)) {
                continue;
            }
            $checklist = $this->resolver->resolve($apparatus)['checklist'] ?? null;
            if (is_array($checklist)) {
                $this->collect($items, $type, $checklist);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, array{name: string, compartment: string|null}>>  $items
     * @param  array<string, mixed>  $checklist
     */
    private function collect(array &$items, string $type, array $checklist): void
    {
        foreach ($this->paperFields->equipmentDefinitions($checklist) as $definitions) {
            foreach ($definitions as $id => $definition) {
                $items[$type][(string) $id] ??= ['name' => (string) $definition['item'], 'compartment' => (string) $definition['compartment']];
            }
        }
    }

    /**
     * Open issues whose item cannot yet be classified, counted per (checklist, item).
     *
     * @return array{counts: array<string, array{type: string, key: string, name: string, compartment: string|null, count: int}>, unmatched: int}
     */
    private function openUnclassifiedIssues(): array
    {
        $counts = [];
        $unmatched = 0;
        foreach ($this->scope->candidateQuery()->where('resolved', false)->get() as $defect) {
            $result = $this->scope->classify($defect);
            if (! in_array($result->checklistType, MedicalScope::MEDICAL_ONLY_TYPES, true) || $result->classification !== MedicalScope::UNCLASSIFIED) {
                continue;
            }
            if ($result->itemKey === null) {
                $unmatched++;

                continue;
            }
            $id = $result->checklistType.'|'.$result->itemKey;
            $counts[$id] ??= [
                'type' => $result->checklistType, 'key' => $result->itemKey,
                'name' => (string) $defect->item, 'compartment' => (string) $defect->compartment, 'count' => 0,
            ];
            $counts[$id]['count']++;
        }

        return ['counts' => $counts, 'unmatched' => $unmatched];
    }

    /** @return PendingRow */
    private function pendingRow(string $type, string $key, string $name, ?string $compartment, int $openIssues): array
    {
        $reason = match (true) {
            in_array($key, (array) config("captain5_medical_scope.{$type}.ambiguous", []), true) => self::REASON_AMBIGUOUS,
            $this->templateChanged($type) => self::REASON_TEMPLATE_CHANGED,
            default => self::REASON_NEW_ITEM,
        };

        return [
            'checklist_type' => $type, 'checklist_label' => self::label($type), 'item_key' => $key, 'item_name' => $name,
            'compartment' => $compartment, 'reason' => $reason, 'reason_label' => self::REASON_LABELS[$reason], 'open_issues' => $openIssues,
        ];
    }

    private function templateChanged(string $type): bool
    {
        $template = $this->medical->baselineTemplate($type);
        $reviewed = (string) config("captain5_medical_scope.{$type}.reviewed_version", '');

        return $template === null || $reviewed === '' || ! hash_equals($reviewed, MedicalScope::canonicalVersion($template));
    }

    /** @return list<DecidedRow> */
    private function decided(): array
    {
        $timezone = (string) config('captain5.timezone', 'America/New_York');

        return Captain5ItemClassification::query()->current()->with('decidedBy:id,name')
            ->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(static fn (Captain5ItemClassification $row): array => [
                'checklist_type' => (string) $row->checklist_type,
                'checklist_label' => self::label((string) $row->checklist_type),
                'item_key' => (string) $row->item_key,
                'item_name' => (string) $row->item_name,
                'classification' => (string) $row->classification,
                'classification_label' => $row->classification === Captain5ItemClassification::MEDICAL
                    ? 'Medical (Captain 5 handles these issues)'
                    : 'Not medical (stays with Fleet)',
                'reason' => (string) $row->reason,
                'decided_by' => (string) ($row->decidedBy->name ?? 'A former administrator'),
                'decided_at' => CarbonImmutable::parse($row->updated_at)->setTimezone($timezone)->format('M j, Y g:i A'),
            ])->values()->all();
    }
}
