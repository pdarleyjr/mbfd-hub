{{-- Admin PWA wiring. Shared install and notch metas come from the global HEAD_END hook (components.hub.head). --}}
@auth
    <meta name="mbfd-admin-authenticated" content="true">
@endauth

@vite(['resources/js/admin-pwa/main.ts'])
