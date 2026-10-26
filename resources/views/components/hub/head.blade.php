{{--
    Shared head metas for every MBFD Hub page. Standalone views include <x-hub.head />; Filament panels
    receive the same tags from one global HEAD_END hook in AppServiceProvider.
    viewport-fit=cover is what makes env(safe-area-inset-*) padding real on notched phones.
--}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#012766">
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
{{-- Release the page was rendered by. resources/js/pwa/update-registrar.ts compares it with /app-release. --}}
<meta name="mbfd-release" content="{{ app(\App\Support\Release\DeployedRelease::class)->short() }}">
