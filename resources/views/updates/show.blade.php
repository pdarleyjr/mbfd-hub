<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $departmentUpdate->title }} | MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-800 antialiased">
    <header class="border-b border-slate-700/50 bg-slate-850">
        <div class="mx-auto flex min-h-16 max-w-4xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center gap-3 rounded-lg text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <img src="/images/mbfd_logo-256.png" alt="" class="h-10 w-10 object-contain">
                <span class="font-heading font-semibold">MBFD Support Hub</span>
            </a>
            <a href="{{ route('updates.index') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-700">All updates</a>
        </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        @include('updates._card', ['update' => $departmentUpdate, 'compact' => false])
    </main>
</body>
</html>
