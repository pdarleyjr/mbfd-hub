<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBFD Daily Checkout</title>
    <!-- PWA iOS Meta Tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <link rel="apple-touch-icon" href="/images/mbfd_app_icon_192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#B91C1C">
    <style>
    #splash-screen {
      position: fixed; inset: 0; z-index: 9999;
      background: #1E293B;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      transition: opacity 0.4s ease;
    }
    #splash-screen.fade-out { opacity: 0; pointer-events: none; }
    #splash-logo { width: 120px; height: 120px; margin-bottom: 1.5rem; }
    #splash-title { color: #F8FAFC; font-size: 1.5rem; font-weight: 700; font-family: system-ui, -apple-system, sans-serif; }
    #splash-subtitle { color: #94A3B8; font-size: 0.875rem; margin-top: 0.5rem; font-family: system-ui, sans-serif; }
    .splash-spinner { width: 40px; height: 40px; border: 3px solid #334155; border-top-color: #B91C1C; border-radius: 50%; animation: spin 0.8s linear infinite; margin-top: 2rem; }
    @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    @viteReactRefresh
    @vite(['resources/js/daily-checkout/src/main.tsx', 'resources/js/daily-checkout/src/index.css'])
</head>
<body>
    <div id="splash-screen">
      <img id="splash-logo" src="/images/mbfd_app_icon_192.png" alt="MBFD">
      <div id="splash-title">MBFD Hub</div>
      <div id="splash-subtitle">Daily Checkout</div>
      <div class="splash-spinner"></div>
    </div>
    <script>
      window.__hideSplash = function() {
        var s = document.getElementById('splash-screen');
        if (s) { s.classList.add('fade-out'); setTimeout(function(){ s.remove(); }, 450); }
      };
      // Fallback: hide after 5s even if React fails
      setTimeout(window.__hideSplash, 5000);
    </script>
    <div id="root"></div>
</body>
</html>