{{--
    Enterprise status bar — bottom of admin viewport.

    Layout:
      [WS state]   [queue depth]   [user · role · env]

    Only renders for users on a desktop (gated by CSS via `desktop:flex`
    + `hidden`). Mobile/tablet never see it — they get the existing
    Filament chrome unchanged.

    Connection state polls /admin/health (or a lightweight ping) every 15s.
    Queue depth is fed by Pulse when available; falls back to a static
    placeholder if Pulse is not authorized.
--}}
@php
    $user = auth()->user();
    $envBadge = match(app()->environment()) {
        'production' => ['label' => 'PROD', 'classes' => 'bg-emerald-600 text-white'],
        'staging' => ['label' => 'STAGING', 'classes' => 'bg-amber-500 text-white'],
        default => ['label' => strtoupper(app()->environment()), 'classes' => 'bg-slate-500 text-white'],
    };
@endphp

<div
    data-admin-status-bar
    x-data="adminStatusBar()"
    x-init="init()"
    class="hidden desktop:flex fixed bottom-0 left-0 right-0 z-30 h-7 items-center justify-between border-t border-slate-200 bg-slate-50 px-3 text-[11px] text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"
    style="font-feature-settings: 'tnum';"
>
    <div class="flex items-center gap-4">
        <span class="flex items-center gap-1.5">
            <span
                class="inline-block h-2 w-2 rounded-full"
                :class="online ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'"
                aria-hidden="true"
            ></span>
            <span x-text="online ? 'Connected' : 'Offline'"></span>
        </span>
        <span class="hidden lg:inline">
            WS: <span x-text="wsState" class="font-medium"></span>
        </span>
    </div>

    <div class="flex items-center gap-4">
        <span class="hidden xl:inline">
            Queue: <span x-text="queueDepth" class="font-medium"></span>
        </span>
        <span class="hidden lg:inline">
            <span class="text-slate-400">Build</span>
            <span class="font-mono" x-text="buildSha"></span>
        </span>
    </div>

    <div class="flex items-center gap-3">
        @if($user)
            <span>
                {{ $user->name }}
                @if(method_exists($user, 'getRoleNames'))
                    <span class="ml-1 text-slate-400">·</span>
                    <span class="ml-1 text-slate-500">{{ $user->getRoleNames()->first() ?? 'user' }}</span>
                @endif
            </span>
        @endif
        <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wider {{ $envBadge['classes'] }}">
            {{ $envBadge['label'] }}
        </span>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminStatusBar', () => ({
            online: navigator.onLine,
            wsState: 'idle',
            queueDepth: '—',
            buildSha: '',

            init() {
                this.online = navigator.onLine;
                window.addEventListener('online', () => (this.online = true));
                window.addEventListener('offline', () => (this.online = false));

                // Detect Reverb WS state via global Echo if available
                if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
                    const conn = window.Echo.connector.pusher.connection;
                    this.wsState = conn.state || 'idle';
                    conn.bind('state_change', (s) => (this.wsState = s.current));
                }

                // Pull build SHA from the /__version endpoint if present
                fetch('/__version', { credentials: 'same-origin' })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((data) => { if (data?.git_sha) this.buildSha = String(data.git_sha).slice(0, 7); })
                    .catch(() => {});

                // Pull queue depth from Pulse JSON endpoint if accessible
                this.refreshQueue();
                setInterval(() => this.refreshQueue(), 30_000);
            },

            refreshQueue() {
                // Best-effort: if /admin/pulse is forbidden for the user, swallow silently
                fetch('/admin/pulse/queues.json', { credentials: 'same-origin' })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((data) => {
                        if (data && typeof data.pending === 'number') {
                            this.queueDepth = String(data.pending);
                        }
                    })
                    .catch(() => {});
            },
        }));
    });
</script>
