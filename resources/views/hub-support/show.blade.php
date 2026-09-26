<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Report · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-hub-canvas text-hub-ink">
    <x-hub-header back-href="{{ route('hub-support.index') }}" back-label="My Reports" max-width="max-w-3xl" />
    <main class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
        @if(session('status')) <p class="rounded-lg border border-hub-success/30 bg-hub-success/10 p-4 text-hub-success" role="status">{{ session('status') }}</p> @endif
        <h1 class="mt-6 text-2xl font-bold">{{ $report->generated_title }}</h1>
        <p class="mt-2 text-sm font-semibold text-hub-muted">{{ $report->status->memberLabel() }}</p>
        <p class="mt-1 text-xs text-hub-muted">Reference: {{ $report->ticket_number }}</p>
        <section class="mt-6 rounded-xl border border-hub-border bg-hub-surface p-5 shadow-sm">
            <h2 class="font-semibold">What you told us</h2>
            <p class="mt-3 whitespace-pre-wrap">{{ $report->description }}</p>
            @if($report->attachments->isNotEmpty())
                <ul class="mt-4 space-y-2">
                    @foreach($report->attachments as $attachment)
                        <li><a class="text-hub-blue underline" href="{{ route('hub-support.attachments.download', $attachment) }}">{{ $attachment->original_filename }}</a></li>
                    @endforeach
                </ul>
            @endif
        </section>
        @if(in_array($report->status, [\App\Enums\HubSupportTicketStatus::Resolved, \App\Enums\HubSupportTicketStatus::Closed], true) && filled($report->resolution_summary))
            <section class="mt-4 rounded-xl border border-hub-success/30 bg-hub-success/10 p-5">
                <p class="font-semibold text-hub-success">What we found</p>
                <p class="mt-2 whitespace-pre-wrap text-hub-ink">{{ $report->resolution_summary }}</p>
            </section>
        @endif
        @foreach($report->updates as $update)
            <section class="mt-4 rounded-xl border border-hub-border bg-hub-surface p-5 shadow-sm">
                <p class="whitespace-pre-wrap">{{ $update->public_response }}</p>
                <p class="mt-2 text-xs text-hub-muted">{{ $update->created_at->timezone('America/New_York')->format('M j, Y · g:i A') }}</p>
            </section>
        @endforeach
        @if($report->status !== \App\Enums\HubSupportTicketStatus::Closed)
            <form method="post" action="{{ route('hub-support.reply', $report) }}" class="mt-6 rounded-xl border border-hub-border bg-hub-surface p-5 shadow-sm">
                @csrf
                <label for="response" class="block font-semibold">Reply</label>
                <textarea name="response" id="response" required maxlength="5000" rows="4" class="mt-2 w-full rounded-lg border border-hub-border-strong p-3 text-base focus:border-hub-blue focus:outline-none focus:ring-2 focus:ring-hub-focus"></textarea>
                @error('response') <p role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
                <button type="submit" class="mt-3 min-h-11 rounded-lg bg-hub-red px-5 py-3 font-semibold text-white hover:bg-hub-red-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Send Reply</button>
            </form>
        @endif
    </main>
</body>
</html>
