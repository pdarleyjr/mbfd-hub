<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\ApparatusChangeoutItem;

/**
 * Turns a truck's equipment list and the crew's choices into the lines of a changeout. Pure: it saves nothing. Every line
 * is "took" unless the crew said otherwise, including on a return, and nothing the crew sends is ever refused here: an
 * item the list no longer has is ignored. With no usable list there are no lines, and the crew's overall note stands.
 */
final class ChangeoutLineBuilder
{
    /**
     * @param  array{checklist_version: string|null, usable: bool, lines: list<array<string, mixed>>}  $equipment
     * @param  array<string, array{handling: string|null, note: string|null}>  $exceptions  by item key
     * @return list<array<string, mixed>>
     */
    public function linesFor(array $equipment, array $exceptions): array
    {
        $lines = [];
        foreach ($equipment['lines'] as $line) {
            $exception = $exceptions[$line['item_key']] ?? null;
            $handling = $exception['handling'] ?? ApparatusChangeoutItem::HANDLING_TRANSFER;
            $lines[] = [
                ...$line,
                'handling' => $handling,
                'note' => $exception['note'] ?? null,
                'custody_status' => $handling === ApparatusChangeoutItem::HANDLING_LEAVE
                    ? ApparatusChangeoutItem::CUSTODY_LEFT_BEHIND
                    : ApparatusChangeoutItem::CUSTODY_MOVED,
            ];
        }

        return $lines;
    }
}
