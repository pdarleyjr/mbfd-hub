<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#991b1b">
    <title>My account · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-100 text-neutral-900 antialiased">
    <header class="border-b border-neutral-800 bg-neutral-900 text-white">
        <div class="mx-auto flex min-h-16 max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <div class="flex items-center gap-3">
                <img src="/images/mbfd_logo-256.png" alt="" class="h-10 w-10 object-contain">
                <div><p class="text-xs font-bold uppercase tracking-[.16em] text-amber-200">MBFD Hub</p><h1 class="text-lg font-semibold">My account</h1></div>
            </div>
            <a href="/" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold hover:bg-neutral-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">Back to Hub</a>
        </div>
    </header>
    <main class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm" aria-labelledby="identity-heading">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 id="identity-heading" class="text-lg font-bold">Identity & security</h2><p class="mt-1 text-sm text-neutral-600">Your department identity and current security readiness.</p></div>
            <dl class="grid gap-px bg-neutral-200 sm:grid-cols-2">
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Name</dt><dd class="mt-1 font-semibold">{{ $user->name }}</dd></div>
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Employee ID</dt><dd class="mt-1 font-mono font-semibold">{{ $user->employee_id ?: 'Not linked' }}</dd></div>
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Password</dt><dd class="mt-1 font-semibold">{{ $localCredentials ? 'Hub local credential' : 'MBFD Identity credential' }}</dd></div>
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Recovery readiness</dt><dd class="mt-1 font-semibold">{{ $maskedRecoveryEmail ? 'Ready · '.$maskedRecoveryEmail : 'Needs administrator review' }}</dd></div>
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Active Hub sessions</dt><dd class="mt-1 font-semibold">{{ $activeSessionCount }}</dd></div>
                <div class="bg-white p-5"><dt class="text-xs font-bold uppercase tracking-wide text-neutral-500">Account status</dt><dd class="mt-1 font-semibold">{{ ucfirst(str_replace('_', ' ', $user->getRawOriginal('account_status'))) }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-3 border-t border-neutral-200 px-5 py-4">
                <a href="{{ $changePasswordUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-red-800 px-4 py-2 text-sm font-bold text-white hover:bg-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2">Change password</a>
                <a href="{{ route('city-email.show') }}" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">City email & recovery</a>
                <a href="/employee" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">Employee Portal</a>
                @if($user->hasCurrentAdminPanelEntitlement())
                    <a href="/admin" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">Admin Panel</a>
                @endif
                @if($enrollmentUrls)
                    <a href="{{ $enrollmentUrls['passkey'] }}" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">Add a passkey</a>
                    <a href="{{ $enrollmentUrls['totp'] }}" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">Add authenticator app</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 px-4 py-2 text-sm font-bold hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-700">Sign out</button></form>
            </div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-white shadow-sm" aria-labelledby="access-heading">
            <div class="border-b border-neutral-200 px-5 py-4"><h2 id="access-heading" class="text-lg font-bold">Application access</h2><p class="mt-1 text-sm text-neutral-600">Only applications currently granted by the Hub are shown as available.</p></div>
            <ul class="divide-y divide-neutral-100">
                @foreach($applications as $key => $application)
                    @php($state = $applicationStates[$key])
                    <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-semibold">{{ $application['label'] }}</h3><p class="text-sm text-neutral-600">{{ $state['status'] }}</p></div>
                        <span class="w-fit rounded-full px-3 py-1 text-xs font-bold {{ $state['allowed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-neutral-100 text-neutral-600' }}">{{ $state['allowed'] ? 'Available' : 'Unavailable' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>
</body>
</html>
