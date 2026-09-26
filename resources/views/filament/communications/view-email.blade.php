<x-filament-panels::page>
    @php
        $email = $this->getRecord();
        $outbound = $email instanceof \App\Models\OutboundEmail;
        $details = [
            'From' => $email->from_address,
            'To' => $outbound ? implode(', ', $email->to_recipients ?? []) : $email->to_address,
            'CC' => implode(', ', $outbound ? ($email->cc_recipients ?? []) : app(\App\Services\Communications\EmailConversation::class)->headerAddresses($email->safe_headers['cc'] ?? [])),
            'Reply-To' => $outbound ? $email->reply_to : ($email->safe_headers['reply-to'] ?? null),
            'Source' => $outbound ? \App\Services\Communications\EmailConversation::sourceLabel($email->source_type) : 'Inbox',
            'Initiated by' => $outbound ? $email->initiatedBy?->name : null,
            'Provider message ID' => $email->provider_message_id,
            'Created' => $email->created_at?->format('M j, Y g:i A T'),
            'Provider accepted' => $outbound ? $email->accepted_at?->format('M j, Y g:i A T') : null,
            'Delivered' => $outbound ? $email->delivered_at?->format('M j, Y g:i A T') : null,
            'Failed' => $outbound ? $email->failed_at?->format('M j, Y g:i A T') : null,
        ];
    @endphp
    <x-filament::section>
        <x-slot name="heading">{{ $email->subject ?: '(No subject)' }}</x-slot>
        @if($outbound)
            <div class="mb-4"><x-filament::badge :color="$email->deliveryStatusColor()" :icon="$email->deliveryStatusIcon()">{{ $email->deliveryStatusLabel() }}</x-filament::badge></div>
        @endif
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach($details as $label => $value)
                @if(filled($value))
                    <div class="min-w-0"><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-words text-sm text-gray-950 dark:text-white">{{ $value }}</dd></div>
                @endif
            @endforeach
        </dl>
        @if($outbound && filled($email->failure_reason))
            <p class="mt-4 text-sm">{{ $email->failure_reason }}</p>
        @endif
    </x-filament::section>
    <x-filament::section heading="Message">
        <div class="whitespace-pre-wrap break-words text-sm leading-relaxed">{{ $email->text_body ?? strip_tags($outbound ? ($email->html_body ?? '') : ($email->sanitized_html_body ?? '')) }}</div>
    </x-filament::section>
    @if(filled($email->attachment_metadata))
        <x-filament::section heading="Attachments">
            <ul class="space-y-2 text-sm">
                @foreach($email->attachment_metadata as $index => $attachment)
                    <li class="break-words">{{ $attachment['filename'] }} · {{ number_format(($attachment['size'] ?? 0) / 1024, 1) }} KB
                        @if(app(\App\Services\Communications\EmailConversation::class)->canRespond($email) && array_key_exists($index, app(\App\Services\Communications\EmailConversation::class)->attachmentOptions($email)))
                            <x-filament::button size="sm" color="gray" wire:click="downloadAttachment({{ $index }})" icon="heroicon-o-arrow-down-tray">Download</x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm text-gray-500">Forward opens a selection of available private attachments. Attachments are never included automatically.</p>
        </x-filament::section>
    @endif
    @if($outbound)
        <x-filament::section heading="Recipient delivery">
            <p class="mb-4 text-sm text-gray-500">Delivered confirms the recipient mail server accepted the message. It does not confirm the recipient opened it.</p>
            <ul class="space-y-4">
                @forelse($email->recipients()->where('recipient_class', '!=', 'bcc')->get() as $recipient)
                    <li class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="break-words font-medium">{{ $recipient->address }} <span class="text-xs uppercase">{{ $recipient->recipient_class }}</span></div>
                        <p class="mt-1 text-sm">{{ (new \App\Models\OutboundEmail(['status' => $recipient->status]))->deliveryStatusLabel() }} · {{ $recipient->last_event_at?->format('M j, Y g:i A T') ?? 'No provider event yet' }}</p>
                        @if($recipient->failure_code || $recipient->failure_detail)<p class="mt-2 break-words text-sm">{{ $recipient->failure_code }} {{ $recipient->failure_detail }}</p>@endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500">Per-recipient evidence is not available yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Delivery timeline">
            <ul class="space-y-3 text-sm">
                @forelse($email->deliveryEvents()->with('recipient')->whereHas('recipient', fn ($query) => $query->where('recipient_class', '!=', 'bcc'))->reorder('occurred_at', 'desc')->limit(50)->get() as $event)
                    <li class="break-words">
                        <p class="font-medium">{{ \App\Models\OutboundEmail::statusLabel($event->status) }} · {{ $event->recipient?->address }}</p>
                        <p class="text-gray-500">{{ $event->occurred_at?->format('M j, Y g:i A T') }}</p>
                        @if($event->failure_code || $event->failure_detail)
                            <p>{{ $event->failure_code }} {{ \Illuminate\Support\Str::limit($event->failure_detail, 500) }}</p>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500">No provider delivery events recorded.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
    @php
        $parent = $email->parent_outbound_email_id ? \App\Models\OutboundEmail::find($email->parent_outbound_email_id) : null;
        $inboundParent = $outbound && $email->parent_inbound_email_id ? \App\Models\InboundEmail::find($email->parent_inbound_email_id) : null;
        $replies = $outbound ? \App\Models\InboundEmail::where('parent_outbound_email_id', $email->id)->latest('received_at')->limit(25)->get() : collect();
        $sentReplies = \App\Models\OutboundEmail::where($outbound ? 'parent_outbound_email_id' : 'parent_inbound_email_id', $email->id)->latest()->limit(25)->get();
    @endphp
    @if($parent || $inboundParent || $replies->isNotEmpty() || $sentReplies->isNotEmpty())
        <x-filament::section heading="Related messages">
            <ul class="space-y-3 text-sm">
                @foreach(collect([$parent, $inboundParent])->filter()->concat($replies)->concat($sentReplies) as $related)
                    <li><x-filament::link :href="$related instanceof \App\Models\OutboundEmail ? \App\Filament\Resources\OutboundEmailResource::getUrl('view', ['record' => $related]) : \App\Filament\Resources\InboundEmailResource::getUrl('view', ['record' => $related])">{{ $related->subject ?: '(No subject)' }} · {{ $related->created_at?->format('M j, Y') }}</x-filament::link></li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
