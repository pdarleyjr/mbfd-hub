<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#102A43">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <title>MBFD Support Hub | Enterprise Command Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" as="style">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: var(--hub-font-sans); }
        [x-cloak] { display: none !important; }
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes incidentIn { from { opacity: 0; transform: translateX(-6px); } to { opacity: 1; transform: translateX(0); } }
        .stagger-item { opacity: 0; animation: fadeSlideUp 0.3s cubic-bezier(0.25, 0.1, 0.25, 1) forwards; }
        .stagger-item:nth-child(1) { animation-delay: 0ms; }
        .stagger-item:nth-child(2) { animation-delay: 80ms; }
        .stagger-item:nth-child(3) { animation-delay: 160ms; }
        .home-layout { display: grid; gap: 1.5rem; align-items: start; }
        @media (min-width: 1024px) {
            .home-layout { grid-template-columns: minmax(0, 3fr) minmax(22.5rem, 2fr); gap: 2rem; }
        }
        .update-preview {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            overflow: hidden;
        }
        /* PulsePoint call feed */
        .incident-row { animation: incidentIn 0.25s cubic-bezier(0,0,0.2,1) forwards; }
        .incident-row:nth-child(1) { animation-delay: 0ms; }
        .incident-row:nth-child(2) { animation-delay: 50ms; }
        .incident-row:nth-child(3) { animation-delay: 100ms; }
        .incident-row:nth-child(4) { animation-delay: 150ms; }
        .incident-row:nth-child(5) { animation-delay: 200ms; }
        .shimmer-line { position: relative; overflow: hidden; background: #e7e5e3; border-radius: 4px; }
        .shimmer-line::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.6) 50%, transparent 100%); animation: shimmer 1.4s infinite; }
        .feed-scroll { scrollbar-width: thin; scrollbar-color: #e7e5e3 transparent; }
        .feed-scroll::-webkit-scrollbar { width: 4px; }
        .feed-scroll::-webkit-scrollbar-thumb { background: #e7e5e3; border-radius: 2px; }
        @media (prefers-reduced-motion: reduce) {
            .shimmer-line::after { animation: none; }
            .stagger-item, .incident-row { opacity: 1; animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-hub-canvas text-hub-ink antialiased">
    @php
        $currentUser = auth('web')->user();
        $showAdminPanel = $applicationStates['admin']['allowed'] ?? false;
        $showMediaControl = $applicationStates['media_control']['allowed'] ?? false;
        $showEmployeePortal = $currentUser instanceof \App\Models\User
            && $currentUser->employeeProfile()->exists();
        $showWorkgroups = $currentUser instanceof \App\Models\User
            && app(\App\Support\Workgroups\WorkgroupAccess::class)->canEnterPanel($currentUser);
        $quickAccessItems = [
            [
                'title' => 'Station / Vehicles / Equipment',
                'description' => 'Apparatus checkout, vehicle inspections, station inventory, and station requests',
                'href' => url('/daily/stations'),
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 9 2 2 4-4',
                'external' => false,
                'visible' => $showEmployeePortal,
            ],
            [
                'title' => 'Employee Portal',
                'description' => 'View assigned gear, track requests, and request approved uniform items',
                'href' => url('/employee'),
                'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z',
                'external' => false,
                'visible' => $showEmployeePortal,
            ],
            [
                'title' => 'ICS Forms',
                'description' => 'ICS 214 & F-ROC reports',
                'href' => url('/employee/forms'),
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 0a3 3 0 0 1 6 0m-6 0a3 3 0 0 0 6 0M9 12h6m-6 4h4',
                'external' => false,
                'visible' => $showEmployeePortal,
            ],
            [
                'title' => 'Workgroup Dashboard',
                'description' => 'Evaluations & reviews',
                'href' => url('/workgroups'),
                'icon' => 'M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2Zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z',
                'external' => false,
                'visible' => $showWorkgroups,
            ],
            [
                'title' => 'Pump Panel',
                'description' => 'Training simulator',
                'href' => 'https://pdarleyjr.github.io/puc-sim-manual-ui/',
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7Z',
                'external' => true,
                'visible' => true,
            ],
            [
                'title' => 'Videos',
                'description' => 'Training videos, support services content, and live media',
                'href' => 'https://videos.mbfdhub.com',
                'icon' => 'm15 10 4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2Z',
                'external' => true,
                'visible' => true,
            ],
            [
                'title' => 'Media Control',
                'description' => 'Videowall controls, displays, and classroom media management',
                'href' => 'https://media.mbfdhub.com/api/auth/hub/start',
                'icon' => 'M9.75 17 9 20l-.75.75h7.5L15 20l-.75-3M3 13h18M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
                'external' => true,
                'visible' => $showMediaControl,
            ],
        ];
    @endphp

    <!-- Compact Header Shell -->
    <header class="sticky top-0 z-50 flex h-16 items-center justify-between border-b border-white/10 bg-hub-header px-4 lg:px-6" style="padding-top: max(0px, env(safe-area-inset-top, 0px));">
        <!-- Left: Logo + Title -->
        <div class="flex items-center gap-3">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" class="h-10 w-10 object-contain" width="40" height="40">
            <div class="hidden sm:block">
                <h1 class="text-white font-semibold text-base leading-tight font-heading">MBFD Support Hub</h1>
                <p class="text-xs text-slate-300">Enterprise Command Portal</p>
            </div>
        </div>

        <!-- Right: Utility Actions -->
        <div class="flex items-center gap-2" x-data="{ accountOpen: false }" @keydown.escape.window="accountOpen = false">
            @if($showAdminPanel)
                <a href="{{ url('/admin') }}" data-important-target class="flex min-h-[44px] items-center gap-2 rounded-lg bg-hub-red px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-hub-red-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-hub-header sm:px-4">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065Z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path></svg>
                    <span>Admin Panel</span>
                </a>
            @endif
            <div class="relative">
                <button type="button" @click="accountOpen = !accountOpen" :aria-expanded="accountOpen.toString()" aria-haspopup="menu" class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-white hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-hub-red" aria-hidden="true">{{ strtoupper(substr((string) $currentUser?->name, 0, 1)) }}</span>
                    <span class="hidden max-w-40 truncate sm:inline">{{ $currentUser?->display_name ?: $currentUser?->name }}</span>
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
                </button>
                <div x-cloak x-show="accountOpen" @click.outside="accountOpen = false" role="menu" class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-hub-border bg-hub-surface py-1 text-hub-ink shadow-xl">
                    <div class="border-b border-hub-border-soft px-4 py-3"><p class="text-xs font-bold uppercase tracking-wide text-hub-red-strong">MBFD Identity</p><p class="mt-1 truncate text-sm text-hub-muted">Employee ID {{ $currentUser?->employee_id ?: 'not linked' }}</p></div>
                    <a role="menuitem" href="{{ route('account.show') }}" class="flex min-h-11 items-center px-4 py-2 text-sm font-semibold hover:bg-hub-surface-muted focus:bg-hub-surface-muted focus:outline-none">My account</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button role="menuitem" type="submit" class="flex min-h-11 w-full items-center px-4 py-2 text-left text-sm font-semibold text-red-800 hover:bg-red-50 focus:bg-red-50 focus:outline-none">Sign out</button></form>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="home-layout">
            <div data-home-column="primary" class="min-w-0 space-y-6">
                <section data-home-section="department-updates" aria-labelledby="department-updates-heading" class="min-w-0 overflow-hidden rounded-xl border border-hub-border bg-hub-surface shadow-card">
                    <div class="flex items-center justify-between gap-3 border-b border-hub-border bg-hub-surface px-4 py-3.5 sm:px-5">
                        <h2 id="department-updates-heading" class="flex items-center gap-2 font-heading text-lg font-semibold text-hub-ink">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-hub-red-strong" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 0 1-3.417.592l-2.147-6.15M18 13a3 3 0 1 0 0-6M5.436 13.683A4.001 4.001 0 0 1 7 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.998 3.998 0 0 1-1.564-.317Z"></path></svg>
                            </span>
                            Department Updates
                        </h2>
                        <a href="{{ route('updates.index') }}" data-important-target class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-hub-blue hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">
                            View All
                        </a>
                    </div>
                    <div class="divide-y divide-hub-border-soft">
                        @forelse($departmentUpdates as $update)
                            @php
                                $prioritySurface = match ($update->priority) {
                                    \App\Enums\DepartmentUpdatePriority::Critical => 'border-l-red-600',
                                    \App\Enums\DepartmentUpdatePriority::Important => 'border-l-amber-500',
                                    default => 'border-l-blue-500',
                                };
                                $priorityBadge = match ($update->priority) {
                                    \App\Enums\DepartmentUpdatePriority::Critical => 'bg-red-50 text-red-700',
                                    \App\Enums\DepartmentUpdatePriority::Important => 'bg-amber-50 text-amber-800',
                                    default => 'bg-blue-50 text-blue-700',
                                };
                            @endphp
                            <article data-department-update class="border-l-4 {{ $prioritySurface }} px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $priorityBadge }}">{{ $update->priority->label() }}</span>
                                    <span class="rounded-full bg-hub-surface-muted px-2 py-0.5 text-xs font-semibold text-hub-muted">{{ $update->category->label() }}</span>
                                    @if($update->is_pinned)
                                        <span class="text-xs font-semibold text-hub-muted">Pinned</span>
                                    @endif
                                </div>
                                <h3 class="mt-2 font-heading text-base font-bold leading-snug text-hub-ink">
                                    <a href="{{ route('updates.show', $update) }}" class="rounded-sm hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">{{ $update->title }}</a>
                                </h3>
                                <p class="update-preview mt-1.5 text-sm leading-relaxed text-hub-muted">{{ $update->excerpt(180) }}</p>
                                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-hub-muted">
                                        <time datetime="{{ $update->publish_at?->toIso8601String() }}">{{ $update->publish_at?->timezone('America/New_York')->format('M j · g:i A') }}</time>
                                        @if($update->author?->name)<span aria-hidden="true"> · </span>{{ $update->author->name }}@endif
                                    </p>
                                    <a href="{{ route('updates.show', $update) }}" data-important-target class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">Read update</a>
                                </div>
                            </article>
                        @empty
                            <div class="px-5 py-8 text-center">
                                <svg class="mx-auto h-8 w-8 text-hub-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>
                                <p class="mt-2 font-heading text-sm font-semibold text-hub-ink-secondary">No current department updates</p>
                                <p class="mt-1 text-xs text-hub-muted">Published notices will appear here.</p>
                            </div>
                        @endforelse
                    </div>
                </section>

            <section data-home-section="quick-access" aria-labelledby="quick-access-heading" class="min-w-0">
                <h2 id="quick-access-heading" class="mb-4 flex items-center gap-2 font-heading text-lg font-semibold text-hub-ink">
                    <svg class="h-5 w-5 text-hub-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    Quick Access
                </h2>
                <div class="space-y-3">
                    @foreach($quickAccessItems as $item)
                        @continue(! $item['visible'])
                        <a
                            href="{{ $item['href'] }}"
                            @if($item['external']) target="_blank" rel="noopener noreferrer" @endif
                            data-quick-access-card
                            data-important-target
                            class="stagger-item group block min-h-[76px] overflow-hidden rounded-xl border border-hub-border bg-hub-surface shadow-card transition-[border-color,box-shadow] duration-200 hover:border-hub-blue hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2"
                        >
                            <span class="flex min-h-[76px]">
                                <span class="flex items-center gap-3 sm:gap-4 px-3 py-3 sm:px-4 flex-1 min-w-0">
                                    <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-lg bg-blue-50 text-hub-blue" aria-hidden="true">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"></path></svg>
                                    </span>
                                    <span class="flex-1 min-w-0">
                                        <span class="block font-heading text-sm font-semibold leading-tight text-hub-ink group-hover:text-hub-blue sm:text-base">{{ $item['title'] }}</span>
                                        <span class="mt-1 block text-sm leading-snug text-hub-muted">{{ $item['description'] }}</span>
                                    </span>
                                    @if($item['external'])
                                        <svg class="h-5 w-5 flex-shrink-0 text-hub-muted-soft transition-colors group-hover:text-hub-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                    @else
                                        <svg class="h-5 w-5 flex-shrink-0 text-hub-muted-soft transition-colors group-hover:text-hub-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"></path></svg>
                                    @endif
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
            </div>

            <!-- PulsePoint Live Call Feed -->
            <div
                x-data="pulsePointFeed()"
                x-init="init()"
                data-home-column="incidents"
                class="min-w-0 overflow-hidden rounded-xl border border-hub-border bg-hub-surface shadow-card"
                aria-label="MBFD Live Incident Feed"
                aria-live="polite"
                aria-atomic="false"
            >
                <!-- Card Header -->
                <div class="flex items-center justify-between gap-3 bg-hub-header px-5 py-3.5">
                    <div class="flex items-center gap-3">
                        <!-- Shield icon -->
                        <div class="w-8 h-8 bg-red-600 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-white font-semibold text-sm leading-tight font-heading">MBFD Live Incidents</h2>
                            <p class="text-slate-400 text-xs">Miami Beach Fire — Agency X1012</p>
                        </div>
                    </div>
                    <!-- Live badge + last-updated -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span x-show="!error" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-red-900/40 text-red-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                            Live
                        </span>
                        <span x-show="error" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-amber-900/30 text-amber-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                            Offline
                        </span>
                    </div>
                </div>

                <!-- Active Count Bar -->
                <div class="flex items-center justify-between border-b border-hub-border bg-hub-surface-muted px-5 py-2.5">
                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <div class="font-heading font-bold text-xl text-red-600 leading-none" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : activeCount"></div>
                            <div class="mt-0.5 text-xs text-hub-muted">Active</div>
                        </div>
                        <div class="h-8 w-px bg-hub-border"></div>
                        <div class="text-center">
                            <div class="font-heading text-xl font-bold leading-none text-hub-muted-soft" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : recentCount"></div>
                            <div class="mt-0.5 text-xs text-hub-muted">Recent</div>
                        </div>
                    </div>
                    <span class="text-xs text-hub-muted-soft" x-text="lastUpdated" style="font-variant-numeric: tabular-nums;"></span>
                </div>

                <!-- Incident List -->
                <div class="feed-scroll overflow-y-auto" style="max-height: 320px; min-height: 160px;">

                    <!-- Shimmer loading state -->
                    <template x-if="loading">
                        <div class="px-5 py-3 space-y-3">
                            <template x-for="n in [1,2,3]" :key="n">
                                <div class="flex items-start gap-3">
                                    <div class="shimmer-line h-3 w-10 mt-1 flex-shrink-0"></div>
                                    <div class="flex-1 space-y-1.5">
                                        <div class="shimmer-line h-3 w-3/4"></div>
                                        <div class="shimmer-line h-2.5 w-1/2"></div>
                                    </div>
                                    <div class="shimmer-line h-4 w-12 rounded-full flex-shrink-0"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Error state -->
                    <template x-if="!loading && error">
                        <div class="flex flex-col items-center justify-center py-10 px-5 text-center">
                            <svg class="mb-2 h-8 w-8 text-hub-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <p class="text-sm font-medium text-hub-muted">Monitoring Unavailable</p>
                            <p class="mt-1 text-xs text-hub-muted-soft">Check back shortly</p>
                        </div>
                    </template>

                    <!-- Active incidents -->
                    <template x-if="!loading && !error && activeIncidents.length > 0">
                        <div>
                            <div class="px-5 pt-2.5 pb-1">
                                <span class="text-xs font-semibold text-red-600 uppercase tracking-wider">Active Calls</span>
                            </div>
                            <template x-for="(inc, idx) in activeIncidents.slice(0,8)" :key="inc.id">
                                <div class="incident-row border-b border-hub-border-soft px-5 py-2.5 transition-colors duration-150 last:border-0 hover:bg-hub-surface-muted">
                                    <div class="flex items-start gap-3">
                                        <!-- Time -->
                                        <span class="mt-0.5 w-11 flex-shrink-0 text-xs leading-tight text-hub-muted-soft" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <!-- Details -->
                                        <div class="flex-1 min-w-0">
                                            <p class="truncate text-sm font-semibold leading-tight text-hub-ink" x-text="inc.callType"></p>
                                            <p class="mt-0.5 truncate text-xs leading-snug text-hub-muted" x-text="inc.address"></p>
                                            <!-- Units -->
                                            <div x-show="inc.units && inc.units.length > 0" class="flex flex-wrap gap-1 mt-1.5">
                                                <template x-for="unit in inc.units.slice(0,4)" :key="unit.id">
                                                    <span class="rounded bg-hub-surface-muted px-1.5 py-0.5 text-xs font-medium leading-none text-hub-muted" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="unit.id"></span>
                                                </template>
                                                <span x-show="inc.units.length > 4" class="text-xs text-hub-muted-soft" x-text="'+' + (inc.units.length - 4) + ' more'"></span>
                                            </div>
                                        </div>
                                        <!-- Status badge -->
                                        <span class="flex-shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-700 leading-snug">Active</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Recent incidents (shown when no actives, or as secondary section) -->
                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length > 0">
                        <div>
                            <div class="px-5 pt-2.5 pb-1">
                                <span class="text-xs font-semibold uppercase tracking-wider text-hub-muted-soft">Recent Calls</span>
                            </div>
                            <template x-for="(inc, idx) in recentIncidents.slice(0,5)" :key="inc.id">
                                <div class="incident-row border-b border-hub-border-soft px-5 py-2.5 transition-colors duration-150 last:border-0 hover:bg-hub-surface-muted">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 w-11 flex-shrink-0 text-xs leading-tight text-hub-muted-soft" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="truncate text-sm font-medium leading-tight text-hub-muted" x-text="inc.callType"></p>
                                            <p class="mt-0.5 truncate text-xs leading-snug text-hub-muted-soft" x-text="inc.address"></p>
                                        </div>
                                        <span class="flex-shrink-0 rounded-full bg-hub-surface-muted px-2 py-0.5 text-xs font-medium leading-snug text-hub-muted">Cleared</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Empty state — no incidents at all -->
                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length === 0">
                        <div class="flex flex-col items-center justify-center py-10 px-5 text-center">
                            <svg class="mb-2 h-8 w-8 text-hub-border" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-sm font-medium text-hub-muted-soft">No Active Incidents</p>
                            <p class="mt-1 text-xs text-hub-muted-soft">All units available</p>
                        </div>
                    </template>
                </div>

                <!-- Footer: refresh hint -->
                <div class="flex items-center justify-between border-t border-hub-border-soft bg-hub-surface-muted px-5 py-2">
                    <span class="text-xs text-hub-muted-soft">Auto-refreshes every 30 s</span>
                    <a href="https://web.pulsepoint.org/?agency=X1012" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 text-xs text-hub-muted transition-colors duration-150 hover:text-hub-blue">
                        PulsePoint
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                </div>
            </div>

        </div>
    </main>
    
    <!-- Minimal Footer -->
    <footer class="mt-8 border-t border-hub-border bg-hub-surface/80" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs font-medium text-hub-muted">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <div class="flex items-center gap-3 text-xs text-hub-muted">
                <a href="{{ url('/security-standards') }}" class="rounded-sm transition-colors hover:text-hub-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Security &amp; Standards</a>
                <span aria-hidden="true">&bull;</span>
                <span>Secured System</span>
                <span aria-hidden="true">&bull;</span>
                <span>Support Services Division</span>
            </div>
        </div>
    </footer>

    <script>
    function pulsePointFeed() {
        return {
            loading: true,
            error: false,
            activeIncidents: [],
            recentIncidents: [],
            lastUpdated: '',
            _timer: null,

            get activeCount() { return this.activeIncidents.length; },
            get recentCount() { return this.recentIncidents.length; },

            init() {
                this.fetchData();
                this._timer = setInterval(() => this.fetchData(), 30000);
            },

            destroy() {
                if (this._timer) clearInterval(this._timer);
            },

            async fetchData() {
                try {
                    const resp = await fetch('/api/incidents', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        signal: AbortSignal.timeout(12000)
                    });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const data = await resp.json();
                    if (data.error && !data.active) throw new Error(data.error);
                    this.activeIncidents = data.active || [];
                    this.recentIncidents = data.recent || [];
                    this.error = false;
                    this.lastUpdated = 'Updated ' + this.timeAgo(data.fetchedAt);
                } catch (e) {
                    this.error = true;
                    this.lastUpdated = 'Update failed';
                } finally {
                    this.loading = false;
                }
            },

            formatTime(isoStr) {
                if (!isoStr) return '--:--';
                try {
                    const d = new Date(isoStr);
                    return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'America/New_York' });
                } catch { return '--:--'; }
            },

            timeAgo(isoStr) {
                if (!isoStr) return 'just now';
                const diff = Math.floor((Date.now() - new Date(isoStr).getTime()) / 1000);
                if (diff < 60) return 'just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                return Math.floor(diff / 3600) + 'h ago';
            }
        };
    }
    </script>
    @include('components.hub-support-widget')
</body>
</html>
