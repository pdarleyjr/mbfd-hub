<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Reports · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-hub-canvas text-hub-ink">
    <x-hub-header back-href="{{ url('/') }}" back-label="Hub home" max-width="max-w-3xl" />
    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
        <div class="mt-6 flex items-center justify-between gap-4">
            <h1 class="text-2xl font-bold">My Reports</h1>
            <a href="{{ route('hub-support.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-hub-red px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-hub-red-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Report an Issue</a>
        </div>
        <div class="mt-6 space-y-3">
            @forelse($reports as $report)
                <a href="{{ route('hub-support.show', $report) }}" class="block rounded-xl border border-hub-border bg-hub-surface p-5 shadow-sm transition-colors hover:border-hub-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">
                    <span class="block font-semibold">{{ $report->generated_title }}</span>
                    <span class="mt-2 block text-sm text-hub-muted">{{ $report->status->memberLabel() }} · {{ $report->created_at->timezone('America/New_York')->diffForHumans() }}</span>
                </a>
            @empty
                <p class="rounded-xl border border-hub-border bg-hub-surface p-6 text-hub-muted shadow-sm">No reports yet.</p>
            @endforelse
        </div>
        <div class="mt-6">{{ $reports->links() }}</div>
    </main>
</body>
</html>
