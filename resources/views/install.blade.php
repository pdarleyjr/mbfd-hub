<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#B91C1C">
    <link rel="manifest" href="/manifest.json">
    <title>Install MBFD Hub</title>
    <style>
        :root { color-scheme: light; font-family: "Plus Jakarta Sans", Arial, sans-serif; --ink:#172033; --muted:#526072; --line:rgba(23,32,51,.16); --field:#f6f8fb; --brand:#b91c1c; --navy:#1e293b; }
        * { box-sizing: border-box; }
        body { min-height:100vh; margin:0; padding:24px; display:grid; place-items:center; background:var(--field); color:var(--ink); }
        main { width:min(100%, 38rem); padding:clamp(24px, 6vw, 44px); border:1px solid var(--line); border-radius:16px; background:#fff; }
        .eyebrow { margin:0 0 12px; color:var(--brand); font-size:.78rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
        h1 { margin:0; color:var(--navy); font-size:clamp(1.8rem, 5vw, 2.5rem); letter-spacing:-.04em; }
        p { color:var(--muted); line-height:1.55; }
        button { width:100%; min-height:48px; border:0; border-radius:8px; background:var(--brand); color:#fff; font:inherit; font-weight:800; cursor:pointer; }
        button:focus-visible, a:focus-visible { outline:3px solid #93c5fd; outline-offset:3px; }
        button[hidden] { display:none; }
        section { margin-top:24px; padding-top:20px; border-top:1px solid var(--line); }
        h2 { margin:0 0 8px; font-size:1rem; }
        ol { margin:8px 0 0; padding-left:1.25rem; color:var(--muted); line-height:1.65; }
        a { color:#991b1b; font-weight:700; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Miami Beach Fire Department</p>
    <h1>Install MBFD Hub</h1>
    <p>Use the Hub from your device home screen for faster access to the tools you use on shift.</p>
    <button id="install" type="button" hidden>Install MBFD Hub</button>
    <section>
        <h2>On iPhone or iPad</h2>
        <ol><li>Open this page in Safari.</li><li>Select Share, then Add to Home Screen.</li><li>Choose Add.</li></ol>
    </section>
    <section>
        <h2>On Android or desktop</h2>
        <p>Use the browser’s install option, or choose the button above when it appears.</p>
    </section>
    <p><a href="/">Return to MBFD Hub</a></p>
</main>
<script>
    let installPrompt;
    const installButton = document.getElementById('install');
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        installButton.hidden = false;
    });
    installButton.addEventListener('click', async () => {
        if (!installPrompt) return;
        installPrompt.prompt();
        await installPrompt.userChoice;
        installPrompt = undefined;
        installButton.hidden = true;
    });
</script>
</body>
</html>
