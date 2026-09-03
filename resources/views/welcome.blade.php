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
    <meta name="theme-color" content="#B91C1C">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <title>MBFD Support Hub | Enterprise Command Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" as="style">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Source Sans 3', system-ui, sans-serif; }
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
<body class="antialiased bg-neutral-50 text-neutral-800 min-h-screen">
    @php
        $currentUser = auth('web')->user();
        $showAdminPanel = $currentUser instanceof \App\Models\User
            && $currentUser->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
        $showMediaControl = $currentUser instanceof \App\Models\User
            && $currentUser->hasCurrentMediaControlEntitlement();
        $quickAccessItems = [
            [
                'title' => 'Station / Vehicles / Equipment',
                'description' => 'Apparatus checkout, vehicle inspections, station inventory, and station requests',
                'href' => url('/daily/stations'),
                'accent' => 'bg-purple-500',
                'iconSurface' => 'bg-purple-50',
                'iconColor' => 'text-purple-600',
                'hoverBorder' => 'hover:border-purple-300',
                'hoverText' => 'group-hover:text-purple-700',
                'hoverIcon' => 'group-hover:text-purple-500',
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 9 2 2 4-4',
                'external' => false,
                'visible' => true,
            ],
            [
                'title' => 'Employee Portal',
                'description' => 'View assigned gear, track requests, and request approved uniform items',
                'href' => url('/employee'),
                'accent' => 'bg-emerald-500',
                'iconSurface' => 'bg-emerald-50',
                'iconColor' => 'text-emerald-600',
                'hoverBorder' => 'hover:border-emerald-300',
                'hoverText' => 'group-hover:text-emerald-700',
                'hoverIcon' => 'group-hover:text-emerald-500',
                'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z',
                'external' => false,
                'visible' => true,
            ],
            [
                'title' => 'ICS Forms',
                'description' => 'ICS 214 & F-ROC reports',
                'href' => url('/employee/forms'),
                'accent' => 'bg-blue-500',
                'iconSurface' => 'bg-blue-50',
                'iconColor' => 'text-blue-700',
                'hoverBorder' => 'hover:border-blue-300',
                'hoverText' => 'group-hover:text-blue-700',
                'hoverIcon' => 'group-hover:text-blue-500',
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 0a3 3 0 0 1 6 0m-6 0a3 3 0 0 0 6 0M9 12h6m-6 4h4',
                'external' => false,
                'visible' => true,
            ],
            [
                'title' => 'Workgroup Dashboard',
                'description' => 'Evaluations & reviews',
                'href' => url('/workgroups'),
                'accent' => 'bg-indigo-500',
                'iconSurface' => 'bg-indigo-50',
                'iconColor' => 'text-indigo-600',
                'hoverBorder' => 'hover:border-indigo-300',
                'hoverText' => 'group-hover:text-indigo-700',
                'hoverIcon' => 'group-hover:text-indigo-500',
                'icon' => 'M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2Zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z',
                'external' => false,
                'visible' => true,
            ],
            [
                'title' => 'Pump Panel',
                'description' => 'Training simulator',
                'href' => 'https://pdarleyjr.github.io/puc-sim-manual-ui/',
                'accent' => 'bg-amber-500',
                'iconSurface' => 'bg-amber-50',
                'iconColor' => 'text-amber-600',
                'hoverBorder' => 'hover:border-amber-300',
                'hoverText' => 'group-hover:text-amber-700',
                'hoverIcon' => 'group-hover:text-amber-500',
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7Z',
                'external' => true,
                'visible' => true,
            ],
            [
                'title' => 'Videos',
                'description' => 'Training videos, support services content, and live media',
                'href' => 'https://videos.mbfdhub.com',
                'accent' => 'bg-red-500',
                'iconSurface' => 'bg-red-50',
                'iconColor' => 'text-red-600',
                'hoverBorder' => 'hover:border-red-300',
                'hoverText' => 'group-hover:text-red-700',
                'hoverIcon' => 'group-hover:text-red-500',
                'icon' => 'm15 10 4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2Z',
                'external' => true,
                'visible' => true,
            ],
            [
                'title' => 'Media Control',
                'description' => 'Videowall controls, displays, and classroom media management',
                'href' => 'https://media.mbfdhub.com/api/auth/hub/start',
                'accent' => 'bg-cyan-500',
                'iconSurface' => 'bg-cyan-50',
                'iconColor' => 'text-cyan-700',
                'hoverBorder' => 'hover:border-cyan-300',
                'hoverText' => 'group-hover:text-cyan-700',
                'hoverIcon' => 'group-hover:text-cyan-500',
                'icon' => 'M9.75 17 9 20l-.75.75h7.5L15 20l-.75-3M3 13h18M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
                'external' => true,
                'visible' => $showMediaControl,
            ],
        ];
    @endphp

    <!-- Compact Header Shell -->
    <header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md h-16 flex items-center justify-between px-4 lg:px-6" style="padding-top: max(0px, env(safe-area-inset-top, 0px));">
        <!-- Left: Logo + Title -->
        <div class="flex items-center gap-3">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" class="h-10 w-10 object-contain" width="40" height="40">
            <div class="hidden sm:block">
                <h1 class="text-white font-semibold text-base leading-tight font-heading">MBFD Support Hub</h1>
                <p class="text-slate-400 text-xs">Enterprise Command Portal</p>
            </div>
        </div>

        <!-- Right: Utility Actions -->
        <div class="flex items-center gap-2">
            @if($showAdminPanel)
                <a href="{{ url('/admin') }}" data-important-target class="min-h-[44px] px-3 sm:px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 transition-colors flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065Z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path></svg>
                    <span>Admin Panel</span>
                </a>
            @endif
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="home-layout">
            <section data-home-column="quick-access" aria-labelledby="quick-access-heading" class="min-w-0">
                <h2 id="quick-access-heading" class="text-lg font-semibold text-neutral-900 font-heading flex items-center gap-2 mb-4">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
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
                            class="stagger-item group block min-h-[76px] bg-white rounded-xl border border-neutral-200 shadow-sm {{ $item['hoverBorder'] }} hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 transition-all duration-200 overflow-hidden"
                        >
                            <span class="flex min-h-[76px]">
                                <span class="w-1.5 {{ $item['accent'] }} flex-shrink-0" aria-hidden="true"></span>
                                <span class="flex items-center gap-3 sm:gap-4 px-3 py-3 sm:px-4 flex-1 min-w-0">
                                    <span class="w-11 h-11 rounded-lg {{ $item['iconSurface'] }} {{ $item['iconColor'] }} flex items-center justify-center flex-shrink-0 group-hover:scale-105 transition-transform" aria-hidden="true">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"></path></svg>
                                    </span>
                                    <span class="flex-1 min-w-0">
                                        <span class="block font-semibold text-neutral-900 {{ $item['hoverText'] }} font-heading text-sm sm:text-base leading-tight">{{ $item['title'] }}</span>
                                        <span class="block text-sm text-neutral-600 mt-1 leading-snug">{{ $item['description'] }}</span>
                                    </span>
                                    @if($item['external'])
                                        <svg class="w-5 h-5 text-neutral-400 {{ $item['hoverIcon'] }} transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                    @else
                                        <svg class="w-5 h-5 text-neutral-400 {{ $item['hoverIcon'] }} transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"></path></svg>
                                    @endif
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>

            <!-- PulsePoint Live Call Feed -->
            <div
                x-data="pulsePointFeed()"
                x-init="init()"
                data-home-column="incidents"
                class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden min-w-0"
                aria-label="MBFD Live Incident Feed"
                aria-live="polite"
                aria-atomic="false"
            >
                <!-- Card Header -->
                <div class="bg-[#1e293b] px-5 py-3.5 flex items-center justify-between gap-3">
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
                <div class="px-5 py-2.5 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <div class="font-heading font-bold text-xl text-red-600 leading-none" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : activeCount"></div>
                            <div class="text-xs text-neutral-500 mt-0.5">Active</div>
                        </div>
                        <div class="w-px h-8 bg-neutral-200"></div>
                        <div class="text-center">
                            <div class="font-heading font-bold text-xl text-neutral-400 leading-none" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : recentCount"></div>
                            <div class="text-xs text-neutral-500 mt-0.5">Recent</div>
                        </div>
                    </div>
                    <span class="text-xs text-neutral-400" x-text="lastUpdated" style="font-variant-numeric: tabular-nums;"></span>
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
                            <svg class="w-8 h-8 text-neutral-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <p class="text-sm font-medium text-neutral-500">Monitoring Unavailable</p>
                            <p class="text-xs text-neutral-400 mt-1">Check back shortly</p>
                        </div>
                    </template>

                    <!-- Active incidents -->
                    <template x-if="!loading && !error && activeIncidents.length > 0">
                        <div>
                            <div class="px-5 pt-2.5 pb-1">
                                <span class="text-xs font-semibold text-red-600 uppercase tracking-wider">Active Calls</span>
                            </div>
                            <template x-for="(inc, idx) in activeIncidents.slice(0,8)" :key="inc.id">
                                <div class="incident-row px-5 py-2.5 border-b border-neutral-100 last:border-0 hover:bg-neutral-50 transition-colors duration-150">
                                    <div class="flex items-start gap-3">
                                        <!-- Time -->
                                        <span class="text-xs text-neutral-400 w-11 flex-shrink-0 mt-0.5 leading-tight" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <!-- Details -->
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-neutral-800 leading-tight truncate" x-text="inc.callType"></p>
                                            <p class="text-xs text-neutral-500 mt-0.5 leading-snug truncate" x-text="inc.address"></p>
                                            <!-- Units -->
                                            <div x-show="inc.units && inc.units.length > 0" class="flex flex-wrap gap-1 mt-1.5">
                                                <template x-for="unit in inc.units.slice(0,4)" :key="unit.id">
                                                    <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 font-medium leading-none" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="unit.id"></span>
                                                </template>
                                                <span x-show="inc.units.length > 4" class="text-xs text-neutral-400" x-text="'+' + (inc.units.length - 4) + ' more'"></span>
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
                                <span class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Recent Calls</span>
                            </div>
                            <template x-for="(inc, idx) in recentIncidents.slice(0,5)" :key="inc.id">
                                <div class="incident-row px-5 py-2.5 border-b border-neutral-100 last:border-0 hover:bg-neutral-50 transition-colors duration-150">
                                    <div class="flex items-start gap-3">
                                        <span class="text-xs text-neutral-400 w-11 flex-shrink-0 mt-0.5 leading-tight" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-neutral-500 leading-tight truncate" x-text="inc.callType"></p>
                                            <p class="text-xs text-neutral-400 mt-0.5 leading-snug truncate" x-text="inc.address"></p>
                                        </div>
                                        <span class="flex-shrink-0 text-xs font-medium px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-500 leading-snug">Cleared</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Empty state — no incidents at all -->
                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length === 0">
                        <div class="flex flex-col items-center justify-center py-10 px-5 text-center">
                            <svg class="w-8 h-8 text-neutral-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-sm font-medium text-neutral-400">No Active Incidents</p>
                            <p class="text-xs text-neutral-300 mt-1">All units available</p>
                        </div>
                    </template>
                </div>

                <!-- Footer: refresh hint -->
                <div class="px-5 py-2 border-t border-neutral-100 bg-neutral-50 flex items-center justify-between">
                    <span class="text-xs text-neutral-400">Auto-refreshes every 30 s</span>
                    <a href="https://web.pulsepoint.org/?agency=X1012" target="_blank" rel="noopener noreferrer" class="text-xs text-neutral-400 hover:text-red-600 transition-colors duration-150 flex items-center gap-1">
                        PulsePoint
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                </div>
            </div>

        </div>
    </main>
    
    <!-- Minimal Footer -->
    <footer class="border-t border-neutral-200 bg-white/60 backdrop-blur-sm mt-8" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-neutral-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <div class="flex items-center gap-3 text-xs text-neutral-400">
                <a href="{{ url('/security-standards') }}" class="hover:text-neutral-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50 rounded-sm transition-colors">Security &amp; Standards</a>
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
</body>
</html>
