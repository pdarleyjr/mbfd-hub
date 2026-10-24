<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\PromotionalListEvent;

/** Plain-language lines for the promotional list history. */
final class PromotionalHistoryPresenter
{
    public static function describe(PromotionalListEvent $event): string
    {
        $meta = (array) $event->metadata;
        $who = $event->entry?->printedName();
        $entry = $who !== null && $who !== '' ? $who.' (original rank '.$event->entry->original_rank.')' : 'An entry';

        return match ($event->action) {
            'created' => 'List created.',
            'imported' => sprintf('Imported from %s: %d new, %d linked to personnel, %d unchanged, %d conflicts.',
                $meta['source_file'] ?? 'a CSV file', $meta['created'] ?? 0, $meta['linked'] ?? 0, $meta['unchanged'] ?? 0, $meta['conflicts'] ?? 0),
            'list_updated' => 'List details changed: '.implode(', ', array_map(self::fieldLabel(...), array_keys((array) ($meta['changes'] ?? [])))).'.',
            'list_status_changed' => 'List status changed from '.self::listStatus($meta['from'] ?? '').' to '.self::listStatus($meta['to'] ?? '').'.',
            'entry_added' => $entry.' added.',
            'entry_updated' => $entry.' corrected: '.implode(', ', array_map(self::fieldLabel(...), array_keys((array) ($meta['changes'] ?? [])))).'.',
            'entry_linked' => $entry.' linked to a personnel record.',
            'entry_unlinked' => $entry.' unlinked from its personnel record.',
            'entry_status_changed' => $entry.' changed from '.self::entryStatus($meta['from'] ?? '').' to '.self::entryStatus($meta['to'] ?? '').'.',
            'ranks_recomputed' => self::ranks($meta),
            'exported' => 'Exported as '.strtoupper((string) ($meta['format'] ?? '')).' ('.($meta['entries'] ?? 0).' entries).',
            default => ucfirst(str_replace('_', ' ', $event->action)).'.',
        };
    }

    /** @param array<string, mixed> $meta */
    private static function ranks(array $meta): string
    {
        $changes = (array) ($meta['changes'] ?? []);
        $parts = array_map(fn (array $change): string => '#'.$change['original_rank'].': '
            .($change['from'] ?? 'none').' to '.($change['to'] ?? 'none'), array_slice($changes, 0, 15));
        $more = count($changes) > 15 ? ' and '.(count($changes) - 15).' more' : '';

        return 'Current ranks updated (original rank: before to after): '.implode('; ', $parts).$more.'.';
    }

    private static function fieldLabel(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    private static function listStatus(string $status): string
    {
        return strtolower(PromotionalList::statuses()[$status] ?? $status);
    }

    private static function entryStatus(string $status): string
    {
        return strtolower(PromotionalListEntry::statuses()[$status] ?? $status);
    }
}
