<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Reports · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900">
    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-14">
        <a href="{{ url('/') }}" class="text-sm font-semibold text-red-700">← MBFD Hub</a>
        <div class="mt-6 flex items-center justify-between gap-4">
            <h1 class="text-2xl font-bold">My Reports</h1>
            <a href="{{ route('hub-support.create') }}" class="rounded-lg bg-red-700 px-4 py-3 text-sm font-semibold text-white">Report an Issue</a>
        </div>
        <div class="mt-6 space-y-3">
            @forelse($reports as $report)
                <a href="{{ route('hub-support.show', $report) }}" class="block rounded-xl border border-neutral-200 bg-white p-5 hover:border-red-300">
                    <span class="block font-semibold">{{ $report->generated_title }}</span>
                    <span class="mt-2 block text-sm text-neutral-600">{{ $report->status->memberLabel() }} · {{ $report->created_at->timezone('America/New_York')->diffForHumans() }}</span>
                </a>
            @empty
                <p class="rounded-xl border border-neutral-200 bg-white p-6 text-neutral-600">No reports yet.</p>
            @endforelse
        </div>
        <div class="mt-6">{{ $reports->links() }}</div>
    </main>
</body>
</html>
