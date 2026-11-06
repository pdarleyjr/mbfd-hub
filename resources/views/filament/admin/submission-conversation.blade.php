{{-- Staff view of a submission conversation (SubmissionConversationService::thread). Every row is member-visible. --}}
<div class="space-y-3" data-submission-conversation-staff>
    @forelse($messages as $message)
        <div class="rounded-lg border p-3 {{ $message['from_staff'] ? 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' : 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' }}" data-message-side="{{ $message['from_staff'] ? 'staff' : 'member' }}">
            <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                {{ $message['author'] }} · {{ \Carbon\CarbonImmutable::parse($message['at'])->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}
            </p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-gray-900 dark:text-white">{{ $message['body'] }}</p>
        </div>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-300">No messages yet.</p>
    @endforelse
</div>
