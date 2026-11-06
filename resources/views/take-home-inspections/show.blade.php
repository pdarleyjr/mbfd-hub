@php
    use App\Models\TakeHomeVehicleInspection as Inspection;
    $snapshot = $inspection->vehicle_snapshot ?? [];
    $value = fn ($v): string => filled($v) ? (string) $v : 'Not recorded';
    $local = fn ($date): string => $date->clone()->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T');
    $tag = fn (?string $status): string => match ($status) { 'good' => 'hub-tag--success', 'issue' => 'hub-tag--danger', default => 'hub-tag--warning' };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>{{ $inspection->reference }} · Take Home Vehicle Inspection · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/take-home-inspection.css'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header :back-href="route('take-home-inspections.index')" back-label="Take Home Inspections" />
    <main id="main" class="hub-page__main thvi-main mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Take Home Vehicle Inspection" :description="$inspection->reference.' · Submitted '.$inspection->submittedAtLocal().' by '.$inspection->inspector_name">
            <a href="{{ route('take-home-inspections.pdf', $inspection) }}" class="hub-btn hub-btn--primary">Download PDF</a>
            @if($canEdit)
                <a href="{{ route('take-home-inspections.edit', $inspection) }}" class="hub-btn hub-btn--secondary" data-thvi-edit>Edit inspection</a>
            @endif
            @if($isOwn)
                <a href="{{ route('take-home-inspections.create') }}" class="hub-btn hub-btn--secondary">New inspection</a>
            @endif
        </x-hub.page-header>

        @if($revisedNumber)
            <div class="hub-callout" role="status"><p class="font-bold">Your changes were saved. The report below shows them.</p></div>
        @elseif($unchanged)
            <div class="hub-callout" role="status"><p class="font-bold">Nothing was changed, so nothing new was recorded.</p></div>
        @endif

        @if($original->isArchived())
            <div class="hub-callout" role="note" data-thvi-cleared>
                <p class="font-bold">Cleared by Fleet {{ $original->archived_at->clone()->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T') }}@if($original->archivedBy) by {{ $original->archivedBy->display_name ?: $original->archivedBy->name }}@endif.</p>
                @if(filled($original->archive_reason))<p class="mt-1 whitespace-pre-line text-sm">{{ $original->archive_reason }}</p>@endif
                @if($isOwn)<p class="mt-1 text-sm">This inspection has been handled and can no longer be edited.</p>@endif
            </div>
        @endif

        @if($inspection->acceptable_for_use === false)
            <div class="hub-callout hub-callout--warning" role="note"><p class="font-bold">Reported not acceptable for use.</p></div>
        @elseif($inspection->acceptable_for_use === null)
            <div class="hub-callout hub-callout--warning" role="note"><p class="font-bold">Acceptability for use was not answered.</p></div>
        @elseif($inspection->has_issues || $inspection->has_not_checked)
            <div class="hub-callout hub-callout--warning" role="note"><p class="font-bold">Acceptable for use, with {{ $inspection->has_issues ? 'issues found' : 'items not checked' }}{{ $inspection->has_issues && $inspection->has_not_checked ? ' and items not checked' : '' }}.</p></div>
        @endif

        @if($original->revisions->isNotEmpty())
            @php($latest = $original->revisions->last())
            <section class="hub-panel" aria-labelledby="changes-heading" data-thvi-revisions>
                <div class="hub-panel__header">
                    <h2 id="changes-heading" class="text-lg font-bold">Edited by {{ $latest->revised_by_name }} on {{ $latest->createdAtLocal() }}</h2>
                    <span class="hub-tag">{{ $original->revisions->count() === 1 ? '1 edit' : $original->revisions->count().' edits' }}</span>
                </div>
                <div class="p-4">
                    <p class="text-sm">This report shows the corrected answers. The answers as signed on {{ $original->submittedAtLocal() }} are kept.</p>
                    <details class="thvi-disclosure mt-2">
                        <summary>View original and changes</summary>
                        <ol class="grid gap-3">
                            @foreach($original->revisions as $revision)
                                <li class="hub-well p-3" data-thvi-revision="{{ $revision->revision_number }}">
                                    <p class="font-bold">Edit {{ $revision->revision_number }} · {{ $revision->revised_by_name }} · {{ $revision->createdAtLocal() }}</p>
                                    @if(filled($revision->reason))<p class="mt-1 whitespace-pre-line text-sm">Reason: {{ $revision->reason }}</p>@endif
                                    <ul class="thvi-changes">
                                        @foreach($revision->changeLines() as $line)
                                            <li><span class="font-semibold">{{ $line['label'] }}:</span> <del>{{ $line['from'] }}</del> → <ins>{{ $line['to'] }}</ins></li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ol>
                        <p class="mt-2 text-sm text-hub-muted">In each edit, the first value is what the report said before and the second is the correction. Edit 1 starts from the original as signed.</p>
                    </details>
                </div>
            </section>
        @endif

        <section class="hub-panel overflow-hidden" aria-labelledby="vehicle-heading">
            <div class="hub-panel__header"><h2 id="vehicle-heading" class="text-lg font-bold">Vehicle and driver</h2><span class="hub-tag">As recorded at signing</span></div>
            <dl class="thvi-report-grid">
                <div><dt>Vehicle / Fleet #</dt><dd class="tabular-nums">{{ $value($snapshot['veh_number'] ?? null) }}</dd></div>
                <div><dt>Year · Make · Model</dt><dd>{{ $value(trim(($snapshot['year'] ?? '').' '.($snapshot['make'] ?? '').' '.($snapshot['model'] ?? ''))) }}</dd></div>
                <div><dt>Color</dt><dd>{{ $value($snapshot['color'] ?? null) }}</dd></div>
                <div><dt>Category</dt><dd>{{ $value($snapshot['category_label'] ?? null) }}</dd></div>
                <div><dt>License plate / Tag</dt><dd>{{ $value($snapshot['tag_number'] ?? null) }}</dd></div>
                <div><dt>VIN / Serial</dt><dd class="font-mono">{{ $value($snapshot['serial_number'] ?? null) }}</dd></div>
                <div><dt>Department</dt><dd>{{ $value($snapshot['dept_code'] ?? null) }}</dd></div>
                <div><dt>Odometer</dt><dd>
                    @if($inspection->odometer !== null){{ number_format($inspection->odometer) }} mi @else {{ Inspection::ODOMETER_EXCEPTIONS[$inspection->odometer_exception] ?? 'Not recorded' }} @endif
                    @if($inspection->odometer_flagged)<span class="block text-sm text-hub-muted">Flagged: {{ $inspection->odometer_note }}</span>@endif
                </dd></div>
                <div><dt>Assigned driver</dt><dd>{{ $inspection->driverLabel() }}@if(filled($inspection->driver_name))<span class="block text-sm font-normal text-hub-muted">{{ $inspection->driver_employee_number ? 'Employee ID '.$inspection->driver_employee_number.' · ' : '' }}{{ $inspection->driver_verified ? 'Verified personnel record' : 'Entered manually, not verified' }}</span>@endif</dd></div>
                <div><dt>Inspector</dt><dd>{{ $inspection->inspector_name }}</dd></div>
                <div><dt>Keys present</dt><dd>{{ $inspection->keysLabel() }}</dd></div>
                <div><dt>Acceptable for use</dt><dd><span class="hub-tag hub-tag--{{ Inspection::answerColor($inspection->acceptable_for_use) }}">{{ $inspection->acceptableLabel() }}</span></dd></div>
            </dl>
        </section>

        <section class="hub-panel" aria-labelledby="checks-heading">
            <div class="hub-panel__header"><h2 id="checks-heading" class="text-lg font-bold">Condition checks</h2></div>
            <ul class="grid gap-2 p-4">
                @foreach($inspection->checks as $check)
                    <li class="hub-well p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-bold">{{ $check['label'] }}</span>
                            <span class="hub-tag {{ $tag($check['status']) }}">{{ Inspection::statusLabel($check['status']) }}</span>
                        </div>
                        @if($check['issue_description'])<p class="mt-2 whitespace-pre-line text-sm">{{ $check['issue_description'] }}</p>@endif
                        @if($check['notes'])<p class="mt-1 whitespace-pre-line text-sm text-hub-muted">Notes: {{ $check['notes'] }}</p>@endif
                        @php($issuePhotos = $inspection->issuePhotos($check['key']))
                        @if($issuePhotos->isNotEmpty())
                            <div class="thvi-thumbs mt-2">
                                @foreach($issuePhotos as $photo)
                                    @if($photo->isPendingConversion())
                                        <p class="hub-well p-3 text-sm">Photo still being processed.</p>
                                        @continue
                                    @endif
                                    <a class="thvi-thumb" href="{{ route('take-home-inspections.photo', $photo) }}" target="_blank" rel="noopener" data-hub-viewer="image" data-hub-viewer-title="{{ $photo->label() }}"><img src="{{ route('take-home-inspections.photo', $photo) }}" alt="{{ $photo->label() }}" loading="lazy"></a>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="hub-panel" aria-labelledby="photos-heading">
            <div class="hub-panel__header"><h2 id="photos-heading" class="text-lg font-bold">Standard photos</h2></div>
            <div class="thvi-report-photos p-4">
                @foreach(Inspection::PHOTO_ANGLES as $angle => $label)
                    @php($photo = $inspection->photos->firstWhere('angle', $angle))
                    <figure>
                        @if($photo?->isPendingConversion())
                            <p class="hub-well p-6 text-center text-sm">Photo received, still being processed. Refresh in a minute.</p>
                        @elseif($photo)
                            <a href="{{ route('take-home-inspections.photo', $photo) }}" target="_blank" rel="noopener" data-hub-viewer="image" data-hub-viewer-title="{{ $label }}"><img src="{{ route('take-home-inspections.photo', $photo) }}" alt="{{ $label }}" loading="lazy"></a>
                        @else
                            <p class="hub-well p-6 text-center text-sm">Not provided</p>
                        @endif
                        <figcaption>{{ $label }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </section>

        <section class="hub-panel" aria-labelledby="final-heading">
            <div class="hub-panel__header"><h2 id="final-heading" class="text-lg font-bold">Comments and signature</h2></div>
            <div class="grid gap-4 p-4">
                <p class="whitespace-pre-line">{{ $inspection->comments ?: 'No additional comments.' }}</p>
                <div>
                    <img class="thvi-signature-img" src="{{ route('take-home-inspections.signature', $inspection) }}" alt="Signature of {{ $inspection->inspector_name }}">
                    <p class="mt-1 text-sm"><span class="font-semibold">{{ $inspection->inspector_name }}</span> · Signed {{ $local($inspection->acknowledged_at) }}</p>
                </div>
                @if($canViewAll && $inspection->vehicleChanges->isNotEmpty())
                    <div>
                        <h3 class="font-bold">Vehicle record updates from this inspection</h3>
                        <ul class="mt-2 grid gap-1 text-sm">
                            @foreach($inspection->vehicleChanges as $change)
                                <li>{{ $change->fieldLabel() }}: @if($change->field === 'current_driver_employee_id'){{ $inspection->driver_name }}@else{{ $change->old_value ?? 'blank' }} → {{ $change->new_value ?? 'blank' }}@endif ·<span class="font-semibold">{{ $change->status === 'applied' ? 'Saved to roster' : ucfirst($change->status) }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
