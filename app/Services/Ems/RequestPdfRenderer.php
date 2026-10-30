<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\SourceForm;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * The member's Files copy: a letter-size PDF of the immutable request snapshot (never of live
 * status), with the MBFD logo and page numbers. Same engine and look as the Take Home inspection report.
 */
final class RequestPdfRenderer
{
    public const LOGO_PATH = 'images/mbfd_logo-256.png';

    public function render(InventoryRequest $request): \Barryvdh\DomPDF\PDF
    {
        $snapshot = (array) $request->snapshot;
        $lines = (array) ($snapshot['lines'] ?? []);

        $pdf = Pdf::loadView('pdf.medical-inventory-request', [
            'number' => (string) ($snapshot['number'] ?? $request->number),
            'submittedAt' => CarbonImmutable::parse((string) ($snapshot['submitted_at'] ?? $request->submitted_at))->setTimezone(StockLedger::TIMEZONE)->format('M j, Y g:i A T'),
            'submitterName' => (string) ($snapshot['submitter']['name'] ?? $request->submitter_name),
            'submitterRank' => $snapshot['submitter']['rank'] ?? $request->submitter_rank,
            'targetLabel' => (string) ($snapshot['target']['label'] ?? $request->target_label),
            'eventAssignment' => $snapshot['target']['event_assignment'] ?? null,
            'memberNote' => $snapshot['member_note'] ?? null,
            'lineCount' => count($lines),
            'bags' => $this->bags($snapshot, $lines),
            'snapshotSha' => (string) $request->snapshot_sha256,
            'logo' => $this->logo(),
        ]);
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 150, $canvas->get_height() - 28, $request->number.'  ·  Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.33, 0.39, 0.47]);

        return $pdf;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function bags(array $snapshot, array $lines): array
    {
        $bags = [];
        foreach ((array) ($snapshot['forms'] ?? []) as $key => $form) {
            $sections = [];
            foreach (collect($lines)->where('bag_key', $key)->groupBy(fn (array $l): string => (string) ($l['section_label'] ?? '')) as $label => $group) {
                $sections[] = [
                    'label' => $label !== '' ? $label : 'Items',
                    'lines' => $group->map(fn (array $l): array => [
                        'label' => (string) $l['label'],
                        'min_text' => $l['min_text'] ?? null,
                        'refrigerated' => (bool) ($l['flags']['refrigerated'] ?? false),
                        'answer' => ($l['response_type'] ?? 'quantity') === 'yes_no'
                            ? (($l['requested_flag'] ?? false) ? 'Yes' : 'No')
                            : (string) ($l['requested_qty'] ?? ''),
                    ])->values()->all(),
                ];
            }
            if ($sections !== []) {
                $bags[] = ['key' => (string) $key, 'min_heading' => $key === SourceForm::TRANSPORT ? 'Vehicle par' : 'Bag minimum', 'title' => (string) ($form['title'] ?? $key), 'version_label' => $form['version_label'] ?? null, 'sections' => $sections];
            }
        }

        return $bags;
    }

    private function logo(): ?string
    {
        $path = public_path(self::LOGO_PATH);

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
