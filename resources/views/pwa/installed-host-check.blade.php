<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title>MBFD Hub</title>
<noscript><meta http-equiv="refresh" content="0;url={{ $target }}"></noscript>
<script>
    // Only page script can tell an installed window from a browser tab. An installed window stays on this host, and a
    // tab moves to the primary host as it always has. If the cookie will not stick, moving on is what prevents a reload loop.
    (function () {
        var target = @json($target);
        try {
            var installed = navigator.standalone === true || ['standalone', 'window-controls-overlay', 'minimal-ui'].some(function (mode) { return matchMedia('(display-mode: ' + mode + ')').matches; });
            if (installed) {
                document.cookie = 'mbfd_installed_host=1; Path=/; Max-Age=34560000; SameSite=Lax; Secure';
                if (document.cookie.indexOf('mbfd_installed_host=1') !== -1) { location.replace(location.href); return; }
            }
        } catch (_) {}
        location.replace(target);
    })();
</script>
</head>
<body></body>
</html>
