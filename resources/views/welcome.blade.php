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
    @vite('resources/css/app.css')
    <style>
        .home-layout { display: grid; gap: 32px; align-items: start; }
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
        </div>
    </main>

    <footer class="home-footer">
        <div class="home-footer__inner">
            <p style="margin: 0;">&copy; {{ date('Y') }} Miami Beach Fire Department · Support Services Division</p>
            <a href="{{ url('/security-standards') }}" class="hub-link" style="font-weight: 500;">Security &amp; Standards</a>
        </div>
    </footer>

    @include('components.hub-support-widget')
</body>
</html>
