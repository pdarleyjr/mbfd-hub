<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Set up MBFD Hub</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { box-sizing: border-box; margin: 0; min-height: 100vh; padding: 1rem; display: grid; place-items: center; background: #f1f5f9; color: #172033; }
        main { box-sizing: border-box; width: min(100%, 31rem); padding: clamp(1.25rem, 6vw, 2rem); border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 .5rem; font-size: clamp(1.5rem, 6vw, 1.9rem); }
        p { margin: 0 0 1rem; color: #475569; line-height: 1.45; }
        button { box-sizing: border-box; width: 100%; min-height: 44px; margin-top: 1rem; padding: .8rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        button:focus-visible { outline: 3px solid #bfdbfe; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <h1>Set up your MBFD Hub account</h1>
    <p>Continue to confirm your account and choose your private password.</p>
    <form id="invitation-form" method="POST" action="{{ route('member-onboarding.invitation.redeem') }}">
        @csrf
        <input id="invitation-token" name="token" type="hidden">
        <button type="submit">Continue</button>
    </form>
</main>
<script>
    const token = window.location.hash.slice(1);
    const field = document.getElementById('invitation-token');
    const form = document.getElementById('invitation-form');
    if (!/^[a-f0-9]{64}$/.test(token)) {
        form.remove();
        document.querySelector('main').insertAdjacentHTML('beforeend', '<p>This setup link is invalid. Contact MBFD Hub support for a new invitation.</p>');
    } else {
        field.value = token;
        history.replaceState(null, '', window.location.pathname);
    }
</script>
</body>
</html>
