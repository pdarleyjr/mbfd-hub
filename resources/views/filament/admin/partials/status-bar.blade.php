{{--
    Enterprise status bar — bottom of admin viewport.

    Browser network state and keyboard help stay available on desktop.
    System details are shown only with the existing admin.system.view capability.
    Queue depth is shown only when Pulse supplies a confirmed count.
--}}
@php
    $user = auth()->user();
    $canViewSystemStatus = $user?->can('admin.system.view') ?? false;
    $canViewQueueStatus = $canViewSystemStatus && ($user?->can('view_queue_status') ?? false);
    $envBadge = match(app()->environment()) {
        'production' => ['label' => 'Production', 'tone' => 'success'],
        'staging' => ['label' => 'Staging', 'tone' => 'warning'],
        default => ['label' => ucfirst(app()->environment()), 'tone' => 'neutral'],
    };
@endphp

<div
    data-admin-status-bar
    x-data="adminStatusBar(@js($canViewSystemStatus), @js($canViewQueueStatus))"
    class="mbfd-admin-status-bar"
>
    <div class="mbfd-admin-status-group">
        <span class="mbfd-admin-network-state" role="status" aria-live="polite">
            <span
                class="mbfd-admin-status-dot"
                :data-state="online ? 'online' : 'offline'"
                aria-hidden="true"
            ></span>
            <span x-text="online ? 'Browser online' : 'Offline'"></span>
        </span>
        @if($canViewSystemStatus)
            <span class="mbfd-admin-status-system" x-show="wsState !== null" x-cloak>
                Live updates: <span x-text="wsState"></span>
            </span>
        @endif
    </div>

    @if($canViewSystemStatus)
        <div class="mbfd-admin-status-group mbfd-admin-status-system">
            @if($canViewQueueStatus)
                <span x-show="queueDepth !== null" x-cloak>Queue: <strong x-text="queueDepth"></strong></span>
            @endif
            <span x-show="buildSha" x-cloak>Build <span x-text="buildSha"></span></span>
            <span class="mbfd-admin-environment" data-tone="{{ $envBadge['tone'] }}">{{ $envBadge['label'] }}</span>
        </div>
    @endif

    <div class="mbfd-admin-status-group">
        <button
            type="button"
            class="mbfd-admin-shortcuts-button"
            @click="window.dispatchEvent(new CustomEvent('open-admin-shortcuts-help', { detail: { trigger: $event.currentTarget } }))"
        >
            Keyboard shortcuts
        </button>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminStatusBar', (canViewSystemStatus = false, canViewQueueStatus = false) => ({
            online: navigator.onLine,
            wsState: null,
            queueDepth: null,
            buildSha: '',
            queueTimer: null,
            connection: null,
            connectionListener: null,
            onlineListener: null,
            offlineListener: null,

            init() {
                this.online = navigator.onLine;
                this.onlineListener = () => (this.online = true);
                this.offlineListener = () => (this.online = false);
                window.addEventListener('online', this.onlineListener);
                window.addEventListener('offline', this.offlineListener);

                if (!canViewSystemStatus) return;

                // Detect Reverb WS state via global Echo if available
                if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
                    this.connection = window.Echo.connector.pusher.connection;
                    this.wsState = this.connection.state || 'idle';
                    this.connectionListener = (state) => (this.wsState = state.current);
                    this.connection.bind('state_change', this.connectionListener);
                }

                // Pull build SHA from the /__version endpoint if present
                fetch('/__version', { credentials: 'same-origin' })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((data) => { if (data?.git_sha) this.buildSha = String(data.git_sha).slice(0, 7); })
                    .catch(() => {});

                // Pull queue depth from Pulse JSON endpoint if accessible
                if (canViewQueueStatus) {
                    this.refreshQueue();
                    this.queueTimer = setInterval(() => this.refreshQueue(), 30_000);
                }
            },

            destroy() {
                window.removeEventListener('online', this.onlineListener);
                window.removeEventListener('offline', this.offlineListener);
                if (this.queueTimer) clearInterval(this.queueTimer);
                this.connection?.unbind('state_change', this.connectionListener);
            },

            refreshQueue() {
                if (!canViewQueueStatus) return;
                fetch('/admin/pulse/queues.json', { credentials: 'same-origin' })
                    .then((r) => (r.ok ? r.json() : null))
                    .then((data) => {
                        if (data && typeof data.pending === 'number') {
                            this.queueDepth = String(data.pending);
                        } else {
                            this.queueDepth = null;
                        }
                    })
                    .catch(() => { this.queueDepth = null; });
            },
        }));
    });
</script>
