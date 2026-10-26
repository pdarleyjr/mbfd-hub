{{--
    Admin dashboard install card. It is the shared Hub card (components/hub/install-card), rendered
    hidden; resources/js/pwa/install-ui.ts (loaded by resources/js/admin-pwa/main.ts) reveals it only
    in a browser that can install MBFD Hub and has not been told "Not now" in the last 30 days.
    Rendered at PAGE_START on the dashboard only, so it never sits over forms or tables.
--}}
<div data-admin-pwa-install-mount>
    <x-hub.install-card />
</div>
