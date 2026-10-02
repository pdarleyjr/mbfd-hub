<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-hub-ui="2">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#102A43">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <title>Home · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/js/home.js'])
    <style>
        [x-cloak] { display: none !important; }
        .home-layout { display: grid; gap: 32px; align-items: start; }
        @media (min-width: 1024px) {
            .home-layout { grid-template-columns: minmax(0, 8fr) minmax(20rem, 4fr); gap: 32px; }
        }
        .home-today { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 24px; margin-bottom: 24px; border-bottom: 1px solid rgb(var(--hub2-n-200)); }
        @media (max-width: 639.98px) {
            .home-today > * { width: 100%; }
            .home-today .hub-btn { width: 100%; }
        }
        .home-update { position: relative; padding: 16px 0 16px 16px; }
        .home-update--critical { box-shadow: inset 3px 0 0 rgb(var(--hub2-red)); }
        .home-update__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .home-update__title { margin: 8px 0 0; font-size: 15px; line-height: 22px; font-weight: 600; }
        .home-update__title a { color: rgb(var(--hub2-n-900)); text-decoration: none; border-radius: 4px; }
        .home-update__title a:hover { color: rgb(var(--hub2-action)); text-decoration: underline; text-underline-offset: 3px; }
        .update-preview { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; margin: 4px 0 0; color: rgb(var(--hub2-n-600)); font-size: 14px; line-height: 22px; }
        .home-update__footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-top: 4px; }
        .home-feed__counts { display: flex; align-items: center; gap: 24px; padding: 12px 16px; border-bottom: 1px solid rgb(var(--hub2-n-100)); }
        .home-feed__metric { font-size: 20px; line-height: 28px; font-weight: 650; font-variant-numeric: tabular-nums; }
        .home-feed__metric--active { color: rgb(var(--hub2-red-strong)); }
        .home-feed__label { margin: 0; color: rgb(var(--hub2-n-600)); font-size: 12px; line-height: 16px; font-weight: 600; }
        .home-feed__group { margin: 0; padding: 12px 16px 4px; color: rgb(var(--hub2-n-600)); font-size: 11px; line-height: 16px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
        .home-feed__row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 16px; }
        .home-feed__time { width: 44px; flex: none; color: rgb(var(--hub2-n-600)); font-size: 12px; line-height: 20px; }
        .home-feed__unit { padding: 0 6px; border-radius: 4px; background: rgb(var(--hub2-n-50)); color: rgb(var(--hub2-n-800)); font-size: 12px; line-height: 18px; }
        .shimmer-line { position: relative; overflow: hidden; background: rgb(var(--hub-border)); border-radius: 4px; }
        .feed-scroll { scrollbar-width: thin; scrollbar-color: rgb(var(--hub-border)) transparent; }
        .feed-scroll::-webkit-scrollbar { width: 4px; }
        .feed-scroll::-webkit-scrollbar-thumb { background: rgb(var(--hub-border)); border-radius: 2px; }
        .home-footer { border-top: 1px solid rgb(var(--hub2-n-200)); padding-bottom: max(8px, env(safe-area-inset-bottom, 0px)); }
        .home-footer__inner { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; max-width: var(--hub2-content-max); margin: 0 auto; padding: 16px var(--hub2-gutter); color: rgb(var(--hub2-n-600)); font-size: 12px; line-height: 16px; }
    </style>
</head>
<body>
    @php
        $currentUser = auth('web')->user();
        $showMediaControl = $applicationStates['media_control']['allowed'] ?? false;
        $showEmployeePortal = $currentUser instanceof \App\Models\User
            && $currentUser->employeeProfile()->exists();
        $showWorkgroups = $currentUser instanceof \App\Models\User
            && app(\App\Support\Workgroups\WorkgroupAccess::class)->canEnterPanel($currentUser);
        $now = now('America/New_York');
        $greeting = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
        $displayName = (string) ($currentUser?->display_name ?: $currentUser?->name);
        $firstName = trim((string) strtok($displayName, ' '));
        $quickAccessItems = [
            [
                'title' => 'Station / Vehicles / Equipment',
                'description' => 'Apparatus checkout, vehicle inspections, station inventory, and station requests',
                'href' => url('/daily/stations'),
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 9 2 2 4-4',
                'external' => false,
                'visible' => $showEmployeePortal,
                'domain' => 'operations',
            ],
            [
                'title' => 'Employee Portal',
                'description' => 'View assigned gear, track requests, and request approved uniform items',
                'href' => url('/employee'),
                'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z',
                'external' => false,
                'visible' => $showEmployeePortal,
                'domain' => 'personnel',
            ],
            [
                'title' => 'ICS Forms',
                'description' => 'ICS 214 & F-ROC reports',
                'href' => url('/employee/forms'),
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 0a3 3 0 0 1 6 0m-6 0a3 3 0 0 0 6 0M9 12h6m-6 4h4',
                'external' => false,
                'visible' => $showEmployeePortal,
                'domain' => 'personnel',
            ],
            [
                'title' => 'Workgroup Dashboard',
                'description' => 'Evaluations & reviews',
                'href' => url('/workgroups'),
                'icon' => 'M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2Zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z',
                'external' => false,
                'visible' => $showWorkgroups,
                'domain' => 'programs',
            ],
            [
                'title' => 'Pump Panel',
                'description' => 'Training simulator',
                'href' => 'https://pdarleyjr.github.io/puc-sim-manual-ui/',
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7Z',
                'external' => true,
                'visible' => true,
                'domain' => 'training',
            ],
            [
                'title' => 'Videos',
                'description' => 'Training videos, support services content, and live media',
                'href' => 'https://videos.mbfdhub.com',
                'icon' => 'm15 10 4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2Z',
                'external' => true,
                'visible' => true,
                'domain' => 'training',
            ],
            [
                'title' => 'Media Control',
                'description' => 'Videowall controls, displays, and classroom media management',
                'href' => 'https://media.mbfdhub.com/api/auth/hub/start',
                'icon' => 'M9.75 17 9 20l-.75.75h7.5L15 20l-.75-3M3 13h18M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
                'external' => true,
                'visible' => $showMediaControl,
                'domain' => 'training',
            ],
        ];
    @endphp

    <a href="#main-content" class="hub-skip-link">Skip to main content</a>

    <x-hub.shell module="Home" :navigation="\App\Support\HubShellNavigation::forUser($currentUser, $applicationStates)" />

    <main id="main-content" class="hub-page">
        <section class="home-today" aria-labelledby="home-greeting">
            <div>
                <p class="hub-page-header__eyebrow"><time datetime="{{ $now->toDateString() }}">{{ $now->format('l, F j') }}</time></p>
                <h1 id="home-greeting" class="hub-h1">{{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}</h1>
            </div>
            @if($showEmployeePortal)
                <div class="hub-page-header__actions">
                    <a href="{{ url('/daily/stations') }}" data-important-target class="hub-btn hub-btn--primary hub-btn--field">
                        <svg class="hub-btn__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        Start Daily Checkout
                    </a>
                </div>
            @endif
        </section>

        <div class="home-layout">
            <div data-home-column="primary" class="min-w-0">
                <section data-home-section="department-updates" aria-labelledby="department-updates-heading" class="hub-section min-w-0">
                    <div class="hub-section__header">
                        <h2 id="department-updates-heading" class="hub-h2">Department Updates</h2>
                        <a href="{{ route('updates.index') }}" data-important-target class="hub-link hub-link--standalone">
                            View all<span class="hub-visually-hidden"> department updates</span>
                            <svg class="hub-tag__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                        </a>
                    </div>
                    <div class="hub-list">
                        @forelse($departmentUpdates as $update)
                            @php
                                [$priorityTone, $priorityIcon] = match ($update->priority) {
                                    \App\Enums\DepartmentUpdatePriority::Critical => ['hub-tag--danger', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z'],
                                    \App\Enums\DepartmentUpdatePriority::Important => ['hub-tag--warning', 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z'],
                                    default => ['hub-tag--info', 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z'],
                                };
                            @endphp
                            <article data-department-update class="home-update {{ $update->priority === \App\Enums\DepartmentUpdatePriority::Critical ? 'home-update--critical' : '' }}">
                                <div class="home-update__meta">
                                    <span class="hub-tag {{ $priorityTone }}">
                                        <svg class="hub-tag__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $priorityIcon }}"/></svg>
                                        {{ $update->priority->label() }}
                                    </span>
                                    <span class="hub-tag">{{ $update->category->label() }}</span>
                                    @if($update->is_pinned)
                                        <span class="hub-caption">Pinned</span>
                                    @endif
                                </div>
                                <h3 class="home-update__title">
                                    <a href="{{ route('updates.show', $update) }}">{{ $update->title }}</a>
                                </h3>
                                <p class="update-preview">{{ $update->excerpt(180) }}</p>
                                <div class="home-update__footer">
                                    <p class="hub-caption" style="margin: 0;">
                                        <time datetime="{{ $update->publish_at?->toIso8601String() }}">{{ $update->publish_at?->timezone('America/New_York')->format('M j · g:i A') }}</time>
                                        @if($update->author?->name)<span aria-hidden="true"> · </span>{{ $update->author->name }}@endif
                                    </p>
                                    <a href="{{ route('updates.show', $update) }}" data-important-target class="hub-link hub-link--standalone">Read update<span class="hub-visually-hidden">: {{ $update->title }}</span></a>
                                </div>
                            </article>
                        @empty
                            <div class="hub-empty">
                                <svg class="hub-empty__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                                <p class="hub-empty__title">No current department updates</p>
                                <p class="hub-empty__body">Published notices will appear here.</p>
                            </div>
                        @endforelse
                    </div>
                </section>

                <section data-home-section="quick-access" aria-labelledby="quick-access-heading" class="hub-section min-w-0">
                    <div class="hub-section__header">
                        <h2 id="quick-access-heading" class="hub-h2">Quick Access</h2>
                    </div>
                    <div class="hub-tiles" style="margin-top: 12px;">
                        @foreach($quickAccessItems as $item)
                            @continue(! $item['visible'])
                            <a
                                href="{{ $item['href'] }}"
                                @if($item['external']) target="_blank" rel="noopener noreferrer" @endif
                                data-quick-access-card
                                data-important-target
                                class="hub-tile hub-tile--{{ $item['domain'] }} bg-hub-surface"
                            >
                                <svg class="hub-tile__glyph" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"></path></svg>
                                <span class="hub-tile__body">
                                    <span class="hub-tile__title">{{ $item['title'] }}</span>
                                    <span class="hub-tile__description">{{ $item['description'] }}</span>
                                </span>
                                @if($item['external'])
                                    <svg class="hub-tile__affordance text-hub-blue" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                    <span class="hub-visually-hidden">(opens in a new tab)</span>
                                @else
                                    <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            </div>

            <section
                x-data="pulsePointFeed()"
                x-init="init()"
                data-home-column="incidents"
                class="hub-panel"
                aria-label="MBFD Incident Feed"
                aria-live="polite"
                aria-atomic="false"
            >
                <div class="hub-panel__header">
                    <h2 class="hub-h3">MBFD Incidents</h2>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span x-cloak x-show="loading" class="hub-tag">Checking</span>
                        <span x-cloak x-show="!loading && !error && !stale" class="hub-tag hub-tag--danger">
                            <span class="hub-status-dot hub-status-dot--live" aria-hidden="true"></span>
                            Live
                        </span>
                        <span x-cloak x-show="!loading && stale" class="hub-tag hub-tag--warning">
                            <svg class="hub-tag__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            Stale
                        </span>
                        <span x-cloak x-show="!loading && error" class="hub-tag hub-tag--warning">
                            <svg class="hub-tag__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                            Unavailable
                        </span>
                    </div>
                </div>

                <div class="home-feed__counts">
                    <div>
                        <div class="home-feed__metric home-feed__metric--active" x-text="loading || error ? '—' : activeCount"></div>
                        <p class="home-feed__label">Active</p>
                    </div>
                    <div>
                        <div class="home-feed__metric" x-text="loading || error ? '—' : recentCount"></div>
                        <p class="home-feed__label">Recent</p>
                    </div>
                    <span class="hub-caption" style="margin-left: auto; text-align: right;" x-text="lastUpdated"></span>
                </div>

                <div class="feed-scroll" style="max-height: 360px; min-height: 160px; overflow-y: auto;">
                    <template x-if="loading">
                        <div style="padding: 12px 16px; display: grid; gap: 12px;">
                            <template x-for="n in [1,2,3]" :key="n">
                                <div style="display: flex; gap: 12px;">
                                    <span class="shimmer-line" style="height: 12px; width: 40px;"></span>
                                    <span style="flex: 1; display: grid; gap: 6px;">
                                        <span class="shimmer-line" style="height: 12px; width: 75%;"></span>
                                        <span class="shimmer-line" style="height: 10px; width: 50%;"></span>
                                    </span>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="!loading && error">
                        <div class="hub-empty">
                            <svg class="hub-empty__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                            <p class="hub-empty__title">Monitoring Unavailable</p>
                            <p class="hub-empty__body">The incident feed will retry automatically.</p>
                        </div>
                    </template>

                    <template x-if="!loading && !error && activeIncidents.length > 0">
                        <div>
                            <h3 class="home-feed__group" style="color: rgb(var(--hub2-red-strong));">Active calls</h3>
                            <ul class="hub-list">
                                <template x-for="(inc, idx) in activeIncidents.slice(0,8)" :key="inc.id">
                                    <li class="home-feed__row">
                                        <span class="home-feed__time hub-mono" x-text="formatTime(inc.receivedAt)"></span>
                                        <div style="flex: 1; min-width: 0;">
                                            <p style="margin: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="inc.callType"></p>
                                            <p class="hub-secondary-text" style="margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="inc.address"></p>
                                            <div x-show="inc.units && inc.units.length > 0" style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px;">
                                                <template x-for="unit in inc.units.slice(0,4)" :key="unit.id">
                                                    <span class="home-feed__unit hub-mono" x-text="unit.id"></span>
                                                </template>
                                                <span x-show="inc.units.length > 4" class="hub-caption" x-text="'+' + (inc.units.length - 4) + ' more'"></span>
                                            </div>
                                        </div>
                                        <span class="hub-tag hub-tag--danger">Active</span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length > 0">
                        <div>
                            <h3 class="home-feed__group">Recent calls</h3>
                            <ul class="hub-list">
                                <template x-for="(inc, idx) in recentIncidents.slice(0,5)" :key="inc.id">
                                    <li class="home-feed__row">
                                        <span class="home-feed__time hub-mono" x-text="formatTime(inc.receivedAt)"></span>
                                        <div style="flex: 1; min-width: 0;">
                                            <p style="margin: 0; font-weight: 500; color: rgb(var(--hub2-n-800)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="inc.callType"></p>
                                            <p class="hub-secondary-text" style="margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="inc.address"></p>
                                        </div>
                                        <span class="hub-tag">Cleared</span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length === 0">
                        <div class="hub-empty">
                            <svg class="hub-empty__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            <p class="hub-empty__title">No incidents listed</p>
                            <p class="hub-empty__body" x-text="stale ? 'In the last confirmed feed' : 'In the current feed'"></p>
                        </div>
                    </template>
                </div>

                <div class="hub-panel__footer">
                    <span class="hub-caption">Auto-refreshes every 30 s</span>
                    <a href="https://web.pulsepoint.org/?agency=X1012" target="_blank" rel="noopener noreferrer" class="hub-link hub-link--standalone" style="font-size: 13px;">
                        PulsePoint<span class="hub-visually-hidden"> (opens in a new tab)</span>
                        <svg class="hub-tag__icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                    </a>
                </div>
            </section>
        </div>
    </main>

    <footer class="home-footer">
        <div class="home-footer__inner">
            <p style="margin: 0;">&copy; {{ date('Y') }} Miami Beach Fire Department · Support Services Division</p>
            <a href="{{ url('/security-standards') }}" class="hub-link" style="font-weight: 500;">Security &amp; Standards</a>
        </div>
    </footer>

    <script>
    function pulsePointFeed() {
        return {
            loading: true,
            error: false,
            stale: false,
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
                    if (data.error || !Array.isArray(data.active) || !Array.isArray(data.recent)) throw new Error('Incident feed unavailable');
                    const staleAsOf = data.stale === true ? new Date(data.staleAsOf) : null;
                    if (staleAsOf && (!data.staleAsOf || Number.isNaN(staleAsOf.getTime()))) throw new Error('Stale feed timestamp unavailable');
                    this.activeIncidents = data.active;
                    this.recentIncidents = data.recent;
                    this.error = false;
                    this.stale = data.stale === true;
                    this.lastUpdated = staleAsOf
                        ? 'Last confirmed ' + staleAsOf.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'America/New_York', timeZoneName: 'short' })
                        : (data.fetchedAt ? 'Updated ' + this.timeAgo(data.fetchedAt) : 'Current feed confirmed');
                } catch (e) {
                    this.error = true;
                    this.stale = false;
                    this.lastUpdated = 'No confirmed update';
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
