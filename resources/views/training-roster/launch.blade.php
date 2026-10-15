<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Classroom links · MBFD</title>
    <style>body{font:18px/1.6 system-ui;color:#102c4c;background:#f5f7f9;margin:0;padding:clamp(20px,5vw,80px)}main{max-width:760px;margin:auto}h1{line-height:1.2}button,a.button{display:inline-block;padding:14px 24px;background:#126239;color:white;border:0;border-radius:8px;text-decoration:none;font:inherit;cursor:pointer}input{box-sizing:border-box;width:100%;padding:12px;font:inherit}form{margin:24px 0}label{display:block}small{color:#48586c}</style>
</head>
<body><main>
    <p>MIAMI BEACH FIRE DEPARTMENT · TRAINING</p>
    <h1>{{ $rosterSession->course_name }}</h1>
    <p>{{ $rosterSession->held_on->format('F j, Y') }}</p>
    @if($kioskUrl)
        <p>This classroom link allows attendance signing for this session. Share it only with participants.</p>
        <p><a class="button" href="{{ $kioskUrl }}" target="_blank" rel="noopener noreferrer">Open roster</a></p>
        <label for="kiosk-link">Classroom sign-in link</label>
        <input id="kiosk-link" value="{{ $kioskUrl }}" readonly onclick="this.select()">
        <small>Copy this link to the classroom touchscreen. It expires after {{ config('training-roster.grant_hours', 12) }} hours. Closing sign-in stops new submissions.</small>
    @elseif($rosterSession->status === 'open')
        <p>Start a shared touchscreen or copy a sign-in link for this class. Members will not need to log in.</p>
        <form method="post" action="{{ route('training.rosters.launch', $rosterSession) }}">@csrf<button>Get classroom sign-in link</button></form>
    @else
        <p>Sign-in is {{ $rosterSession->status }}. New classroom links can only be issued while sign-in is open. You can end existing links below.</p>
    @endif
    <form method="post" action="{{ route('training.rosters.revoke', $rosterSession) }}">@csrf<button style="background:#6c2430">End all classroom links</button></form>
    <p><a href="{{ url('/training/training-rosters/'.$rosterSession->id) }}">Return to Training</a></p>
</main></body>
</html>
