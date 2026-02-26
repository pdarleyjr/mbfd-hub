<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 — Server Error | MBFD Hub</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: #0F172A;
            color: #F1F5F9;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 2rem;
        }
        .error-container { text-align: center; max-width: 480px; }
        .logo { width: 96px; height: 96px; margin: 0 auto 2rem;
            animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; } 50% { opacity: 0.6; }
        }
        .error-code {
            font-size: 6rem; font-weight: 800; line-height: 1;
            color: #B91C1C; margin-bottom: 0.5rem;
            text-shadow: 0 0 40px rgba(185,28,28,0.4);
        }
        .error-title {
            font-size: 1.5rem; font-weight: 700; color: #F8FAFC;
            margin-bottom: 0.75rem;
        }
        .error-message {
            color: #94A3B8; font-size: 1rem; line-height: 1.6;
            margin-bottom: 2rem;
        }
        .divider {
            width: 48px; height: 3px;
            background: linear-gradient(90deg, #B91C1C, transparent);
            margin: 1.5rem auto; border-radius: 2px;
        }
        .alert-box {
            background: rgba(127,29,29,0.3);
            border: 1px solid rgba(185,28,28,0.4);
            border-radius: 8px; padding: 1rem;
            color: #FCA5A5; font-size: 0.875rem;
            margin-bottom: 1.5rem; text-align: left;
        }
        .btn-home {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: #B91C1C; color: #fff;
            padding: 0.75rem 1.75rem; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.9375rem;
            transition: background 0.15s; margin-right: 0.75rem;
        }
        .btn-home:hover { background: #991B1B; }
        .btn-refresh {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: #1E293B; color: #94A3B8;
            padding: 0.75rem 1.75rem; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.9375rem;
            border: 1px solid #334155; transition: background 0.15s;
        }
        .btn-refresh:hover { background: #334155; color: #F1F5F9; }
        .footer-text {
            margin-top: 3rem; color: #475569; font-size: 0.8125rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <img class="logo" src="/images/mbfd_app_icon_192.png" alt="MBFD Logo">
        <div class="error-code">500</div>
        <div class="divider"></div>
        <div class="error-title">Internal Server Error</div>
        <p class="error-message">
            Something went wrong on our end. Our team has been notified.<br>
            Please try again or contact your administrator.
        </p>
        <div class="alert-box">
            ⚠ If this is an active incident, contact the duty officer directly.
        </div>
        <a href="{{ url('/admin') }}" class="btn-home">← Dashboard</a>
        <a href="javascript:location.reload()" class="btn-refresh">↻ Retry</a>
        <p class="footer-text">Moreau Beach Fire Department &bull; MBFD Hub</p>
    </div>
</body>
</html>
