<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Report · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900">
    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-14">
        <a href="{{ route('hub-support.index') }}" class="text-sm font-semibold text-red-700">← My Reports</a>
        @if(session('status')) <p class="mt-5 rounded-lg bg-green-50 p-4 text-green-900" role="status">{{ session('status') }}</p> @endif
        <h1 class="mt-6 text-2xl font-bold">{{ $report->generated_title }}</h1>
        <p class="mt-2 text-sm font-semibold text-neutral-600">{{ $report->status->memberLabel() }}</p>
        <p class="mt-1 text-xs text-neutral-500">Reference: {{ $report->ticket_number }}</p>
        <section class="mt-6 rounded-xl border border-neutral-200 bg-white p-5">
            <h2 class="font-semibold">What you told us</h2>
            <p class="mt-3 whitespace-pre-wrap">{{ $report->description }}</p>
            @if($report->attachments->isNotEmpty())
                <ul class="mt-4 space-y-2">
                    @foreach($report->attachments as $attachment)
                        <li><a class="text-red-700 underline" href="{{ route('hub-support.attachments.download', $attachment) }}">{{ $attachment->original_filename }}</a></li>
                    @endforeach
                </ul>
            @endif
        </section>
        @foreach($report->updates as $update)
            <section class="mt-4 rounded-xl border border-neutral-200 bg-white p-5">
                <p class="whitespace-pre-wrap">{{ $update->public_response }}</p>
                <p class="mt-2 text-xs text-neutral-500">{{ $update->created_at->timezone('America/New_York')->format('M j, Y · g:i A') }}</p>
            </section>
        @endforeach
        @if($report->status !== \App\Enums\HubSupportTicketStatus::Closed)
            <form method="post" action="{{ route('hub-support.reply', $report) }}" class="mt-6 rounded-xl border border-neutral-200 bg-white p-5">
                @csrf
                <label for="response" class="block font-semibold">Reply</label>
                <textarea name="response" id="response" required maxlength="5000" rows="4" class="mt-2 w-full rounded-lg border border-neutral-300 p-3 text-base"></textarea>
                @error('response') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                <button type="submit" class="mt-3 rounded-lg bg-red-700 px-5 py-3 font-semibold text-white">Send Reply</button>
            </form>
        @endif
    </main>
</body>
</html>
