<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Department Updates | MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header max-width="max-w-5xl" />
    <main id="main" class="hub-page__main mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Department Updates" />
        <div class="space-y-3">
            @forelse($departmentUpdates as $update)
                @include('updates._card', ['update' => $update, 'compact' => true, 'headingTag' => 'h2'])
            @empty
                <div class="hub-panel hub-empty">
                    <x-heroicon-o-megaphone class="hub-empty__icon" aria-hidden="true" />
                    <p class="hub-empty__title">No published department updates</p>
                </div>
            @endforelse
        </div>
        <div class="mt-8">{{ $departmentUpdates->links() }}</div>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
