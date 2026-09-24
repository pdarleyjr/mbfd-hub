@props([
    'backHref' => url('/'),
    'backLabel' => 'Hub home',
    'maxWidth' => 'max-w-6xl',
])

<header class="border-b border-white/10 bg-hub-header text-white" style="padding-top: max(0px, env(safe-area-inset-top, 0px));">
    <div class="{{ $maxWidth }} mx-auto flex min-h-16 items-center justify-between gap-3 px-4 py-2 sm:px-6">
        <a href="{{ url('/') }}" class="flex min-h-11 min-w-0 items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
            <img src="/images/mbfd_logo-256.png" alt="" class="h-10 w-10 flex-none object-contain" width="40" height="40">
            <span class="min-w-0">
                <span class="block truncate font-heading text-sm font-bold leading-tight sm:text-base">MBFD Support Hub</span>
                <span class="hidden text-xs text-slate-300 sm:block">Enterprise Command Portal</span>
            </span>
        </a>
        <a href="{{ $backHref }}" class="inline-flex min-h-11 flex-none items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-100 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
            {{ $backLabel }}
        </a>
    </div>
</header>
