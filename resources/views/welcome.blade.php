<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-hub-ui="2">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <title>Home · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body>
    @php
        $currentUser = auth('web')->user();
        $showMediaControl = $applicationStates['media_control']['allowed'] ?? false;
        $showEmployeePortal = $currentUser instanceof \App\Models\User
            && $currentUser->employeeProfile()->exists();
        $showWorkgroups = $currentUser instanceof \App\Models\User
            && app(\App\Support\Workgroups\WorkgroupAccess::class)->canEnterPanel($currentUser);
        $now = now(\App\Support\HubTimezone::NAME);
        $greeting = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
        $displayName = (string) ($currentUser?->display_name ?: $currentUser?->name);
        $firstName = trim((string) strtok($displayName, ' '));
        // Fleet tools only for super administrators and members with the Fleet manage switch on.
        $showFleetOperations = $currentUser instanceof \App\Models\User && $currentUser->canManageFleetSwaps();
        $hubNavigation = \App\Support\HubShellNavigation::forUser($currentUser, $applicationStates);
        // Profile-less accounts cannot open the Employee Portal (where Forms links the inspection),
        // so the shell navigation hands them the inspection as their own entry.
        $takeHomeFallback = collect($hubNavigation['moreNavigation'])->firstWhere('key', 'take-home-inspection');
        $quickAccessItems = [
            [
                'title' => 'Station / Vehicles / Equipment',
                'description' => 'Station forms, Vehicle Checkouts, Video Conferencing',
                'href' => url('/daily/stations'),
                'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2m-6 9 2 2 4-4',
                'external' => false,
                'visible' => $showEmployeePortal,
                'domain' => 'operations',
            ],
            [
                'title' => 'Take Home Inspection',
                'description' => 'Complete a take-home vehicle inspection.',
                'href' => url($takeHomeFallback['href'] ?? '/'),
                'icon' => 'M8 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm12 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM3 5h11v10H3zM14 9h4l3 3v3h-7',
                'external' => false,
                'visible' => $takeHomeFallback !== null,
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
        ];
        // One expandable card holds the less-used destinations. Each keeps its own visibility rule.
        $additionalLinks = collect([
            [
                'title' => 'Fleet operations',
                'description' => 'Apparatus, defects, service tickets, shop work, and preventative maintenance',
                'href' => url('/admin/apparatuses'),
                'icon' => 'M8 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm12 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM3 5h11v10H3zM14 9h4l3 3v3h-7',
                'external' => false,
                'visible' => $showFleetOperations,
                'domain' => 'operations',
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
                'href' => 'https://pump-panel.pages.dev/',
                'icon' => 'M13 10V3L4 14h7v7l9-11h-7Z',
                'external' => true,
                'visible' => true,
                'domain' => 'training',
            ],
            [
                'title' => 'Vehicle Placement Simulator',
                'description' => 'Training simulator',
                'href' => 'https://firesim.mbfdhub.com',
                'icon' => 'M8 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm12 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM3 5h11v10H3zM14 9h4l3 3v3h-7',
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
        ])->filter(fn (array $item): bool => $item['visible'])->values();    @endphp

    <a href="#main-content" class="hub-skip-link">Skip to main content</a>

    <x-hub.shell module="Home" :navigation="$hubNavigation" />

    <main id="main-content" class="hub-page">
        <section class="home-today" aria-labelledby="home-greeting">
            <div>
                <p class="hub-page-header__eyebrow"><time datetime="{{ $now->toDateString() }}">{{ $now->format('l, F j') }}</time></p>
                <h1 id="home-greeting" class="hub-h1">{{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}</h1>
            </div>
            @if($showEmployeePortal)
                <div class="hub-page-header__actions">
                    <a href="{{ url('/daily/vehicle-inspections') }}" data-important-target class="hub-btn hub-btn--primary hub-btn--field">
                        <x-heroicon-o-truck class="hub-btn__icon" aria-hidden="true" />
                        Vehicle Checkout
                    </a>
                    {{-- Same audience as the Employee Portal, narrowed to accounts My Messages will open for (no dead button). --}}
                    @if(app(\App\Services\Security\EmployeeLogisticsMessagingAccess::class)->canUseMemberInbox($currentUser))
                        <a href="{{ url('/employee/messages?compose=1') }}" data-important-target data-home-message-logistics class="hub-btn hub-btn--secondary hub-btn--field">
                            <x-heroicon-o-chat-bubble-bottom-center-text class="hub-btn__icon" aria-hidden="true" />
                            Message Logistics
                        </a>
                    @endif
                </div>
            @endif
        </section>

        <x-hub.install-card />

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
                                    <p class="hub-caption home-update__stamp">
                                        <time datetime="{{ $update->publish_at?->toIso8601String() }}">{{ $update->publish_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j · g:i A') }}</time>
                                        @if($update->attribution())<span aria-hidden="true"> · </span>Posted by {{ $update->attribution() }}@endif
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

            </div>

            <aside data-home-column="rail" class="min-w-0" aria-label="Quick access">
                <section data-home-section="quick-access" aria-labelledby="quick-access-heading" class="hub-section min-w-0">
                    <div class="hub-section__header">
                        <h2 id="quick-access-heading" class="hub-h2">Quick Access</h2>
                    </div>
                    <div class="hub-tiles hub-tiles--rail">
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

                        @if($additionalLinks->isNotEmpty())
                            <details data-quick-access-card data-additional-links class="hub-tile hub-tile--programs hub-additional bg-hub-surface">
                                <summary class="hub-additional__summary">
                                    <svg class="hub-tile__glyph" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"></path></svg>
                                    <span class="hub-tile__body">
                                        <span class="hub-tile__title">Additional Links</span>
                                        <span class="hub-tile__description">Fleet, workgroups, training, and media tools</span>
                                    </span>
                                    <svg class="hub-tile__affordance hub-additional__chevron" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                </summary>
                                <ul class="hub-additional__list" aria-label="Additional links">
                                    @foreach($additionalLinks as $item)
                                        <li>
                                            <a
                                                href="{{ $item['href'] }}"
                                                @if($item['external']) target="_blank" rel="noopener noreferrer" @endif
                                                data-additional-link
                                                data-important-target
                                                class="hub-additional__link hub-tile--{{ $item['domain'] }}"
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
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </main>

    <footer class="home-footer">
        <div class="home-footer__inner">
            <p>&copy; {{ date('Y') }} Miami Beach Fire Department · Support Services Division</p>
            <a href="{{ url('/security-standards') }}" class="hub-link">Security &amp; Standards</a>
        </div>
    </footer>

    @include('components.hub-support-widget')
</body>
</html>
