<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page Not Found | MBFD Hub</title>
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
        .logo { width: 96px; height: 96px; margin: 0 auto 2rem; }
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
            margin: 1.5rem auto;
            border-radius: 2px;
        }
        .btn-home {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: #B91C1C; color: #fff;
            padding: 0.75rem 1.75rem; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.9375rem;
            transition: background 0.15s;
        }
        .btn-home:hover { background: #991B1B; }
        .footer-text {
            margin-top: 3rem; color: #475569; font-size: 0.8125rem;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <img class="logo" src="/images/mbfd_app_icon_192.png" alt="MBFD Logo">
        <div class="error-code">404</div>
        <div class="divider"></div>
        <div class="error-title">Page Not Found</div>
        <p class="error-message">
            The page you're looking for doesn't exist or has been moved.<br>
            Please check the URL or return to the dashboard.
        </p>
        <a href="{{ url('/admin') }}" class="btn-home">
            ← Return to Dashboard
        </a>
        <p class="footer-text">Miami Beach Fire Department &bull; MBFD Hub</p>
    </div>
</body>
</html>
