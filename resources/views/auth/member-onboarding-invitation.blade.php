<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="referrer" content="no-referrer">
    <title>Set up MBFD Hub</title>
    @include('auth.partials.city-email-style')
</head>
<body>
<main class="identity-narrow">
    <div class="identity-strip"><img src="/images/mbfd_logo-256.png" alt="" width="32" height="32">Miami Beach Fire Department</div>
    <h1>Set up your MBFD Hub account</h1>
    <p>Continue to confirm your account and choose your private password.</p>
    <form id="invitation-form" method="POST" action="{{ route('member-onboarding.invitation.redeem') }}">
        @csrf
        <input id="invitation-token" name="token" type="hidden">
        <button type="submit">Continue</button>
    </form>
    <p id="invitation-error" class="error" role="alert" hidden>This setup link is invalid. Request another private link or contact MBFD Hub support to check your account and email on file.</p>
    <a class="help" href="{{ route('password.request') }}">Request another private link</a>
    <a class="help" href="{{ route('login') }}">Already have a password? Sign in</a>
</main>
<script>
    const token = window.location.hash.slice(1);
    history.replaceState(null, '', window.location.pathname);
    const field = document.getElementById('invitation-token');
    const form = document.getElementById('invitation-form');
    if (!/^[a-f0-9]{64}$/.test(token)) {
        form.remove();
        document.getElementById('invitation-error').hidden = false;
    } else {
        field.value = token;
    }
</script>
</body>
</html>
