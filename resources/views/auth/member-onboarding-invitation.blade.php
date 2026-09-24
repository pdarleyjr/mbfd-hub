<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Set up MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main>
    <div class="identity-strip">Miami Beach Fire Department</div>
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
