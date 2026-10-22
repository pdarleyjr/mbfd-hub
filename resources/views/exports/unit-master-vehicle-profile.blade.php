<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Master Vehicle Inventory — {{ $vehicle->displayName() }}</title>
    <style>
        @page { margin: 32px 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1b2330; font-size: 9pt; line-height: 1.4; }
        .header { width: 100%; border-bottom: 3px solid #c5a55a; margin-bottom: 16px; padding-bottom: 10px; }
        .header td { vertical-align: middle; }
        .logo { width: 64px; height: 64px; }
        .eyebrow { color: #475569; font-size: 8pt; letter-spacing: 1px; }
        h1 { color: #012766; font-size: 19pt; line-height: 1.2; margin: 3px 0; }
        h2 { color: #012766; font-size: 12pt; margin: 16px 0 7px; page-break-after: avoid; }
        .vehicle { font-size: 12pt; font-weight: bold; margin-top: 4px; }
        .meta, .note { color: #475569; font-size: 8pt; }
        .note { padding: 8px 10px; background: #f3f5f8; border-left: 2px solid #c5a55a; }
        table.records { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .records thead { display: table-header-group; }
        .records tr { page-break-inside: avoid; }
        .records th { background: #012766; color: #fff; text-align: left; padding: 7px 8px; font-size: 8pt; }
        .records td { border-bottom: 1px solid #dce2e8; padding: 6px 8px; vertical-align: top; word-wrap: break-word; }
        .records tr:nth-child(even) td { background: #f3f5f8; }
        .label { width: 35%; color: #475569; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            @if ($logo)
                <td style="width: 78px;"><img class="logo" src="{{ $logo }}" alt="Miami Beach Fire Department"></td>
            @endif
            <td>
                <div class="eyebrow">MIAMI BEACH FIRE DEPARTMENT</div>
                <h1>Master Vehicle Inventory</h1>
                <div class="vehicle">{{ $vehicle->displayName() }}</div>
                <div class="meta">Generated {{ \Carbon\Carbon::parse($manifest['generated_at'])->timezone('America/New_York')->format('M j, Y g:i A T') }}</div>
            </td>
        </tr>
    </table>

    <h2>Vehicle profile</h2>
    <table class="records">
        <tbody>
            @foreach ($profile as [$label, $value])
                <tr><td class="label">{{ $label }}</td><td>{{ $value !== '' ? $value : 'Not recorded' }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2>Document index — {{ count($manifest['documents']) }} originals included</h2>
    @if (count($manifest['documents']) > 0)
        <div class="note">This profile and the selected original documents are delivered together in a ZIP package. Originals remain unchanged in the Documents folder. The package manifest records their SHA-256 hashes and file sizes.</div>
        <table class="records">
            <thead><tr><th style="width: 46%;">Document</th><th style="width: 29%;">Category</th><th style="width: 25%;">Date / status</th></tr></thead>
            <tbody>
                @foreach ($manifest['documents'] as $document)
                    <tr>
                        <td>{{ $document['title'] }}<br><span class="meta">{{ $document['path'] }}</span></td>
                        <td>{{ $document['category'] }}</td>
                        <td>{{ $document['document_date'] ?? 'Not recorded' }}<br><span class="meta">{{ ucfirst(str_replace('_', ' ', $document['review_status'])) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="muted">Vehicle profile only. No original documents were requested.</p>
    @endif

    <h2>Take-home inspection summary</h2>
    <p class="meta">Most recent 100 inspections. This summary preserves the original inspection workflow and does not replace an inspection report.</p>
    <table class="records">
        <thead><tr><th style="width: 35%;">Reference</th><th style="width: 35%;">Submitted (Eastern)</th><th style="width: 30%;">Acceptable for use</th></tr></thead>
        <tbody>
            @forelse ($inspections as $inspection)
                <tr><td>{{ $inspection->reference }}</td><td>{{ $inspection->submitted_at?->clone()->timezone('America/New_York')->format('M j, Y g:i A T') ?? 'Not recorded' }}</td><td>{{ $inspection->acceptableLabel() }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No take-home inspections recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Vehicle history</h2>
    <p class="meta">Most recent 100 recorded changes. Proposed corrections remain proposals until accepted by Fleet.</p>
    <table class="records">
        <thead><tr><th style="width: 25%;">Field</th><th style="width: 27%;">Previous value</th><th style="width: 28%;">Proposed / new value</th><th style="width: 20%;">Status</th></tr></thead>
        <tbody>
            @forelse ($changes as $change)
                <tr><td>{{ $change->fieldLabel() }}</td><td>{{ $change->old_value }}</td><td>{{ $change->new_value }}</td><td>{{ ucfirst($change->status) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No vehicle changes recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
