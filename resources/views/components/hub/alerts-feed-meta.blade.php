{{-- Signed-in pages without a Filament bell read new in-app alerts from here (resources/js/pwa/foreground-alerts.ts).
     Filament panels ignore it and use the bell's own poll. Absent for guests and until the route exists, so nothing polls a 404.
     Kept out of components/hub/head.blade.php so that file stays plain markup (tests render it as static HTML). --}}
@if (auth()->check() && \Illuminate\Support\Facades\Route::has('api.me.alerts'))
<meta name="mbfd-alerts-feed" content="{{ route('api.me.alerts', absolute: false) }}">
@endif
