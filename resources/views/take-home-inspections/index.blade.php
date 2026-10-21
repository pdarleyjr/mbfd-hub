<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Take Home Vehicle Inspections · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/take-home-inspection.css'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-label="Hub home" />
    <main id="main" class="hub-page__main thvi-main mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Take Home Vehicle Inspections" eyebrow="Fleet" description="Inspect a take-home vehicle in four short steps. Your completed inspections are listed here.">
            <a href="{{ route('take-home-inspections.create') }}" class="hub-btn hub-btn--primary hub-btn--field">New inspection</a>
        </x-hub.page-header>

        @if($receipt)
            <div class="hub-callout" role="status">
                <p class="font-bold">Inspection submitted · {{ $receipt->reference }}</p>
                <p class="mt-1 text-sm">Vehicle {{ $receipt->vehicleLabel() }} · {{ $receipt->submittedAtLocal() }} · Acceptable for use: {{ $receipt->acceptable_for_use ? 'Yes' : 'No' }}</p>
                @if($receipt->needsAttention())
                    <p class="mt-1 text-sm">Fleet will see the flagged items on this report.</p>
                @endif
                <p class="mt-2 flex flex-wrap gap-4 text-sm">
                    <a class="hub-link" href="{{ route('take-home-inspections.show', $receipt) }}">View report</a>
                    <a class="hub-link" href="{{ route('take-home-inspections.pdf', $receipt) }}">Download PDF</a>
                </p>
            </div>
        @endif

        <section class="hub-panel" aria-labelledby="history-heading">
            <div class="hub-panel__header">
                <h2 id="history-heading" class="text-lg font-bold">My inspections</h2>
                @if($canViewAll)
                    <a class="hub-link text-sm" href="{{ url('/admin/take-home-vehicle-inspections') }}">All inspections (Fleet)</a>
                @endif
            </div>
            <div class="p-4">
                @if($inspections->isEmpty())
                    <div class="py-6 text-center">
                        <p class="font-semibold">You have not submitted a take-home inspection yet.</p>
                        <p class="mt-1 text-sm text-hub-muted">Start one with New inspection. It takes about five minutes, including six photos.</p>
                    </div>
                @else
                    <ul class="thvi-history">
                        @foreach($inspections as $inspection)
                            <li class="thvi-history__item">
                                <div>
                                    <p class="font-bold tabular-nums">{{ $inspection->submitted_at->clone()->timezone('America/New_York')->format('M j, Y') }}</p>
                                    <p class="text-sm text-hub-muted">{{ $inspection->submitted_at->clone()->timezone('America/New_York')->format('g:i A T') }} · {{ $inspection->reference }}</p>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $inspection->vehicleLabel() }}</p>
                                    <p class="text-sm text-hub-muted">Driver: {{ $inspection->driver_name }}</p>
                                </div>
                                <div>
                                    @if($inspection->acceptable_for_use)
                                        <span class="hub-tag hub-tag--success">Acceptable</span>
                                    @else
                                        <span class="hub-tag hub-tag--danger">Not acceptable</span>
                                    @endif
                                    @if($inspection->has_issues)<span class="hub-tag hub-tag--danger">Issues</span>@endif
                                    @if($inspection->has_not_checked)<span class="hub-tag hub-tag--warning">Not checked</span>@endif
                                </div>
                                <div class="thvi-history__actions">
                                    <a class="hub-btn hub-btn--secondary hub-btn--compact" href="{{ route('take-home-inspections.show', $inspection) }}">View</a>
                                    <a class="hub-btn hub-btn--secondary hub-btn--compact" href="{{ route('take-home-inspections.pdf', $inspection) }}">PDF</a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4">{{ $inspections->links() }}</div>
                @endif
            </div>
        </section>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
