<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102A43">
    <link rel="manifest" href="/manifest.json">
    <title>Install MBFD Hub</title>
    <style>
        :root { color-scheme: light; font-family: "Plus Jakarta Sans", Arial, sans-serif; --ink:#172033; --muted:#526072; --line:#cbd5e1; --field:#f7fafc; --brand:#dc2626; --brand-strong:#b91c1c; --navy:#102a43; --blue:#1e4e8c; }
        * { box-sizing: border-box; }
        body { min-height:100vh; margin:0; padding:24px; display:grid; place-items:center; background:var(--field); color:var(--ink); }
        main { width:min(100%, 38rem); padding:clamp(24px, 6vw, 44px); border:1px solid var(--line); border-radius:16px; background:#fff; box-shadow:0 1rem 3rem rgba(16,42,67,.12); }
        .eyebrow { margin:0 0 12px; color:var(--navy); font-size:.78rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
        h1 { margin:0; color:var(--navy); font-size:clamp(1.8rem, 5vw, 2.5rem); letter-spacing:-.04em; }
        p { color:var(--muted); line-height:1.55; }
        button { width:100%; min-height:48px; border:0; border-radius:8px; background:var(--brand); color:#fff; font:inherit; font-weight:800; cursor:pointer; }
        button:hover { background:var(--brand-strong); }
        button:focus-visible, a:focus-visible { outline:3px solid #60a5fa; outline-offset:3px; }
        button[hidden] { display:none; }
        section { margin-top:24px; padding-top:20px; border-top:1px solid var(--line); }
        h2 { margin:0 0 8px; font-size:1rem; }
        ol { margin:8px 0 0; padding-left:1.25rem; color:var(--muted); line-height:1.65; }
        a { color:var(--blue); font-weight:700; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Miami Beach Fire Department</p>
    <h1>Install MBFD Hub</h1>
    <p id="install-introduction">Use the Hub from your device home screen for faster access to the tools you use on shift.</p>
    <p id="already-installed" role="status" hidden>MBFD Hub is already running as an installed app.</p>
    <button id="install" type="button" hidden>Install MBFD Hub</button>
    <section id="install-guidance">
        <h2 id="install-guidance-heading">Install on this device</h2>
        <ol id="install-guidance-steps"></ol>
    </section>
    <p><a href="/">Return to MBFD Hub</a></p>
</main>
<script>
    let installPrompt;
    const installButton = document.getElementById('install');
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const guidanceSteps = document.getElementById('install-guidance-steps');
    const guidanceHeading = document.getElementById('install-guidance-heading');
    const userAgent = navigator.userAgent;

    const instructions = /iPad|iPhone|iPod/.test(userAgent)
        ? ['Open this page in Safari.', 'Select Share, then Add to Home Screen.', 'Choose Add.']
        : /Macintosh/.test(userAgent) && /Safari/.test(userAgent) && !/Chrome|Chromium/.test(userAgent)
            ? ['Open the Share menu in Safari.', 'Choose Add to Dock.', 'Confirm Add.']
            : ['Use your browser’s install option.', 'Choose the button above when it becomes available.', 'If no button appears, your browser does not offer an install prompt.'];

    guidanceSteps.replaceChildren(...instructions.map((instruction) => {
        const item = document.createElement('li');
        item.textContent = instruction;
        return item;
    }));

    if (standalone) {
        document.getElementById('already-installed').hidden = false;
        document.getElementById('install-introduction').hidden = true;
        document.getElementById('install-guidance').hidden = true;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        if (standalone) return;
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
