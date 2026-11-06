<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My Report · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header back-href="{{ route('hub-support.index') }}" back-label="My Reports" max-width="max-w-3xl" />
    <main id="main" class="hub-page__main mx-auto max-w-3xl break-words px-4 py-6 sm:px-6 sm:py-8">
        @if(session('status')) <p class="mb-4 rounded-lg border border-hub-success/30 bg-hub-success/10 px-4 py-3 text-sm font-semibold text-hub-success" role="status">{{ session('status') }}</p> @endif
        <h1 class="hub-h1">{{ $report->generated_title }}</h1>
        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-hub-ink-secondary">
            <span class="hub-tag">{{ $report->status->memberLabel() }}</span>
            <span class="tabular-nums">Reference: {{ $report->ticket_number }}</span>
        </p>
        @if($report->isArchived())
            <p class="hub-callout mt-4 px-4 py-3 text-sm">This report is archived. Its history is available here; support must restore it before the conversation continues.</p>
        @endif
        <section class="mt-4 hub-panel p-4 sm:p-5">
            <h2 class="hub-h3">What you told us</h2>
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
            <section class="mt-4 rounded-xl border border-hub-success/30 bg-hub-success/10 p-4 sm:p-5">
                <p class="font-semibold text-hub-success">What we found</p>
                <p class="mt-2 whitespace-pre-wrap text-hub-ink">{{ $report->resolution_summary }}</p>
            </section>
        @endif
        @foreach($report->updates as $update)
            <section class="mt-4 hub-panel p-4 sm:p-5">
                <p class="text-sm font-semibold">{{ $update->changed_by_user_id === $report->reported_by_user_id ? 'You' : 'Support team' }} · {{ $update->status->memberLabel() }}</p>
                <p class="mt-2 whitespace-pre-wrap">{{ $update->public_response ?: ($update->metadata['resolution_summary'] ?? $update->status->memberLabel()) }}</p>
                <p class="mt-2 text-xs text-hub-ink-secondary">{{ $update->created_at->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y · g:i A') }}</p>
            </section>
        @endforeach
        @if(! $report->isArchived())
            @if($report->status === \App\Enums\HubSupportTicketStatus::Resolved)
                <form method="post" action="{{ route('hub-support.reply', $report) }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="action" value="confirm_fixed">
                    <input type="hidden" name="client_action_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="expected_update_id" value="{{ $latestUpdateId }}">
                    <button type="submit" class="hub-btn hub-btn--primary">It's fixed — close my report</button>
                </form>
            @endif
            <form method="post" action="{{ route('hub-support.reply', $report) }}" class="mt-4 hub-panel p-4 sm:p-5">
                @csrf
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="client_action_id" value="{{ old('client_action_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="expected_update_id" value="{{ $latestUpdateId }}">
                <label for="response" class="block font-semibold">{{ in_array($report->status, [\App\Enums\HubSupportTicketStatus::Resolved, \App\Enums\HubSupportTicketStatus::Closed], true) ? 'Still having trouble?' : 'Reply' }}</label>
                @if(in_array($report->status, [\App\Enums\HubSupportTicketStatus::Resolved, \App\Enums\HubSupportTicketStatus::Closed], true))
                    <p class="mt-2 text-sm text-hub-ink-secondary">Tell us what still happens. Sending a reply reopens this report.</p>
                @endif
                <textarea name="response" id="response" required maxlength="5000" rows="4" @error('response') aria-invalid="true" aria-describedby="response-error" @enderror class="mt-2 w-full rounded-lg border border-hub-border-strong p-3 text-base focus:border-hub-blue focus:outline-none focus:ring-2 focus:ring-hub-focus">{{ old('response') }}</textarea>
                @error('response') <p id="response-error" role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
                <button type="submit" class="hub-btn hub-btn--primary mt-3">Send Reply</button>
            </form>
        @endif
    </main>
</body>
</html>
