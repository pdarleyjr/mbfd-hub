<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Reports · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-href="{{ url('/') }}" back-label="Hub home" max-width="max-w-3xl" />
    <main id="main" class="hub-page__main mx-auto max-w-3xl break-words px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="My Reports" class="!items-center !mb-4">
            <a href="{{ route('hub-support.create') }}" class="hub-btn hub-btn--primary">
                <x-heroicon-o-plus class="hub-btn__icon" aria-hidden="true" />Report an Issue
            </a>
        </x-hub.page-header>
        @if($reports->isEmpty())
            <div class="hub-panel hub-empty">
                <x-heroicon-o-chat-bubble-left-right class="hub-empty__icon" aria-hidden="true" />
                <p class="hub-empty__title">No reports yet</p>
                <p class="hub-empty__body">When you report an issue, you can follow its progress and reply here.</p>
            </div>
        @else
            <ul class="hub-panel hub-list overflow-hidden">
                @foreach($reports as $report)
                    <li>
                        <a href="{{ route('hub-support.show', $report) }}" class="flex min-h-14 items-center gap-3 px-4 py-3 transition-colors hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-hub-focus">
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold leading-snug [overflow-wrap:anywhere]">{{ $report->generated_title }}</span>
                                <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-hub-ink-secondary">
                                    <span class="hub-tag">{{ $report->status->memberLabel() }}</span>
                                    <span>{{ $report->created_at->timezone(\App\Support\HubTimezone::NAME)->diffForHumans() }}</span>
                                </span>
                            </span>
                            <x-heroicon-m-chevron-right class="h-4 w-4 flex-none text-hub-ink-secondary" aria-hidden="true" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-6">{{ $reports->links() }}</div>
    </main>
</body>
</html>
