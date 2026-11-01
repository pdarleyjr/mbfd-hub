{{--
    Shared head metas for every MBFD Hub page. Standalone views include <x-hub.head />; Filament panels
    receive the same tags from one global HEAD_END hook in AppServiceProvider.
    viewport-fit=cover is what makes env(safe-area-inset-*) padding real on notched phones.
--}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#012766">
<meta name="hub-timezone" content="{{ config('hub.timezone') }}">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MBFD Hub">
{{-- The browser may offer installation before any deferred bundle runs. Keep that one-time offer for
     resources/js/pwa/install-controller.ts, the only code that opens the install dialog. --}}
<script>
    addEventListener('beforeinstallprompt', function (event) { event.preventDefault(); window.__mbfdDeferredInstall = event; });
    addEventListener('appinstalled', function () { window.__mbfdAppInstalled = true; window.__mbfdDeferredInstall = null; });
</script>
{{-- A window installed on the apex host keeps every page on that host: App\Support\Host\HubHosts. Daily's index.html carries the same lines. --}}
<script>
    (function () { try {
        if (location.hostname.indexOf('www.') === 0) return;
        var installed = navigator.standalone === true || ['standalone', 'window-controls-overlay', 'minimal-ui'].some(function (mode) { return matchMedia('(display-mode: ' + mode + ')').matches; });
        if (installed) document.cookie = 'mbfd_installed_host=1; Path=/; Max-Age=34560000; SameSite=Lax; Secure';
    } catch (_) {} })();
</script>
{{-- Release the page was rendered by. resources/js/pwa/update-registrar.ts compares it with /app-release. --}}
<meta name="mbfd-release" content="{{ app(\App\Support\Release\DeployedRelease::class)->short() }}">
<x-hub.alerts-feed-meta />
