<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department Updates | MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-800 antialiased">
    <header class="border-b border-slate-700/50 bg-slate-850">
        <div class="mx-auto flex min-h-16 max-w-5xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center gap-3 rounded-lg text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <img src="/images/mbfd_logo-256.png" alt="" class="h-10 w-10 object-contain">
                <span class="font-heading font-semibold">MBFD Support Hub</span>
            </a>
            <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-700">Hub home</a>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <div class="mb-6">
            <p class="text-sm font-semibold uppercase tracking-wider text-red-700">Member communications</p>
            <h1 class="mt-1 font-heading text-3xl font-bold text-neutral-900">Department Updates</h1>
            <p class="mt-2 text-neutral-600">Published operational notices and member information from MBFD.</p>
        </div>
        <div class="space-y-4">
            @forelse($departmentUpdates as $update)
                @include('updates._card', ['update' => $update, 'compact' => true])
            @empty
                <div class="rounded-xl border border-neutral-200 bg-white p-8 text-center shadow-sm">
                    <p class="font-heading font-semibold text-neutral-800">No published department updates</p>
                </div>
            @endforelse
        </div>
        <div class="mt-8">{{ $departmentUpdates->links() }}</div>
    </main>
</body>
</html>
