<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department Updates | MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header max-width="max-w-5xl" />
    <main id="main" class="hub-page__main mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <div class="mb-6">
            <p class="text-sm font-semibold uppercase tracking-wider text-hub-blue">Member communications</p>
            <h1 class="mt-1 font-heading text-3xl font-bold text-hub-ink">Department Updates</h1>
            <p class="mt-2 text-hub-muted">Published operational notices and member information from MBFD.</p>
        </div>
        <div class="space-y-4">
            @forelse($departmentUpdates as $update)
                @include('updates._card', ['update' => $update, 'compact' => true, 'headingTag' => 'h2'])
            @empty
                <div class="hub-panel p-8 text-center shadow-card">
                    <p class="font-heading font-semibold text-hub-ink">No published department updates</p>
                </div>
            @endforelse
        </div>
        <div class="mt-8">{{ $departmentUpdates->links() }}</div>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
