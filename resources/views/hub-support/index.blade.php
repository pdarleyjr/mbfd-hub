<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Reports · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-href="{{ url('/') }}" back-label="Hub home" max-width="max-w-3xl" />
    <main id="main" class="hub-page__main mx-auto max-w-3xl break-words px-4 py-8 sm:py-12">
        <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-bold">My Reports</h1>
            <a href="{{ route('hub-support.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-hub-blue-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Report an Issue</a>
        </div>
        <div class="mt-6 space-y-3">
            @forelse($reports as $report)
                <a href="{{ route('hub-support.show', $report) }}" class="block hub-panel p-5 shadow-sm transition-colors hover:border-hub-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">
                    <span class="block font-semibold">{{ $report->generated_title }}</span>
                    <span class="mt-2 block text-sm text-hub-muted">{{ $report->status->memberLabel() }} · {{ $report->created_at->timezone('America/New_York')->diffForHumans() }}</span>
                </a>
            @empty
                <div class="hub-empty">
                    <p class="hub-empty__title">No reports yet</p>
                    <p class="hub-empty__body">When you report an issue, you can follow its progress and reply here.</p>
                </div>
            @endforelse
        </div>
        <div class="mt-6">{{ $reports->links() }}</div>
    </main>
</body>
</html>
