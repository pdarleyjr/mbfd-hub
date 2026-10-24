{{-- Filament sections, badges and actions only. Layout utilities, no colour literals. --}}
<x-filament-panels::page>
    @include('filament.admin.partials.ems-styles')
    <div class="space-y-6" data-testid="inventory-request">
        <x-filament::section>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $request->number }}</h2>
                    <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">
                        {{ $request->target_label }}@if (filled($request->event_assignment)): {{ $request->event_assignment }}@endif
                    </p>
                </div>
                <x-filament::badge :color="$statusColor" size="lg">{{ $statusLabel }}</x-filament::badge>
            </div>

            <dl class="ems-cols ems-cols-4 mt-4 text-sm">
                <div>
                    <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Member</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $request->submitter_name }}@if (filled($request->submitter_rank)) <span class="text-gray-600 dark:text-gray-400">· {{ $request->submitter_rank }}</span>@endif</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Unit</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $request->target_label }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Submitted (Eastern)</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $submittedAt }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">Last activity (Eastern)</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $lastActivity }}</dd>
                </div>
            </dl>

            @if (filled($request->member_note))
                <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Note from the member</p>
                    <p class="mt-1 whitespace-pre-line text-gray-950 dark:text-white">{{ $request->member_note }}</p>
                </div>
            @endif
            @if ($request->status === 'closed' && filled($request->close_reason))
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">Closed:</span> {{ $request->close_reason }}</p>
            @endif
            @if ($request->status === 'cancelled')
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300"><span class="font-medium">The member cancelled this request.</span> @if (filled($request->cancel_reason)) {{ $request->cancel_reason }} @endif Nothing can be changed on it.</p>
            @endif
        </x-filament::section>

        @forelse ($groups as $group)
            <x-filament::section :heading="$group['heading']" wire:key="group-{{ $loop->index }}">
                <div class="ems-colhead border-b border-gray-200 pb-2 text-xs font-medium text-gray-600 dark:border-white/10 dark:text-gray-400">
                    <div class="ems-head-item">Item</div>
                    <div class="ems-head-qty">Requested</div>
                    <div class="ems-head-qty">Prepared</div>
                    <div class="ems-head-qty-small">Issued</div>
                    <div class="ems-head-state">Status</div>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($group['lines'] as $line)
                        <li class="py-3" wire:key="line-{{ $line['id'] }}">
                            <div class="ems-line">
                                <div class="ems-item min-w-0">
                                    <p class="font-medium text-gray-950 dark:text-white">
                                        {{ $line['label'] }}@if (filled($line['variant_label'])) <span class="font-normal text-gray-600 dark:text-gray-400">({{ $line['variant_label'] }})</span>@endif
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                                        @if (filled($line['min_text']))<span>Bag minimum: {{ $line['min_text'] }}</span>@endif
                                        @if ($line['refrigerated'])<x-filament::badge size="sm" color="info">Refrigerated</x-filament::badge>@endif
                                        @if ($line['controlled_substance'])<x-filament::badge size="sm" color="warning">Controlled: recorded only</x-filament::badge>@endif
                                    </p>
                                </div>
                                <div class="ems-qty text-sm">
                                    <span class="ems-mobile-label">Requested</span>
                                    {{ $line['response_type'] === 'yes_no' ? ($line['requested_flag'] ? 'Yes' : 'No') : $line['requested_qty'] }}@if ($line['response_type'] === 'quantity' && filled($line['unit']) && $line['unit'] !== 'each') {{ $line['unit'] }}@endif
                                </div>
                                <div class="ems-qty text-sm">
                                    <span class="ems-mobile-label">Prepared</span>
                                    {{ $line['prepared_qty'] ?? '' }}@if ($line['prepared_qty'] === null)<span class="text-gray-500">-</span>@endif
                                </div>
                                <div class="ems-qty-small text-sm">
                                    <span class="ems-mobile-label">Issued</span>
                                    {{ $line['issued_qty'] }}
                                </div>
                                <div class="ems-state">
                                    <x-filament::badge :color="\App\Filament\Support\EmsLabels::LINE_STATUS_COLOR[$line['line_status']] ?? 'gray'">{{ $line['line_status_label'] }}</x-filament::badge>
                                    @if ($canAct && $open && $line['can_not_issue'])
                                        {{ ($this->notIssuedAction)(['line' => $line['id']]) }}
                                    @endif
                                </div>
                            </div>

                            @if ($line['line_status'] === 'not_issued' && filled($line['not_issued_reason']))
                                <p class="mt-1.5 text-xs text-gray-700 dark:text-gray-300">Not issued: {{ $line['not_issued_reason'] }}</p>
                            @endif
                            @foreach ($line['issuances'] as $issuance)
                                <p class="mt-1.5 text-xs text-gray-700 dark:text-gray-300" wire:key="issuance-{{ $issuance['event_id'] }}">
                                    Issued {{ $issuance['quantity'] }}@if ($issuance['corrected']) (corrected from {{ $issuance['original'] }})@endif on {{ $issuance['at'] }} by {{ $issuance['by'] }}.
                                    @if ($issuance['stock'])
                                        <span class="font-medium">{{ $issuance['stock'] }}.</span>
                                    @endif
                                </p>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @empty
            <x-filament::section heading="Items">
                <p class="text-sm text-gray-600 dark:text-gray-400">This request has no items. The member sent a note only.</p>
            </x-filament::section>
        @endforelse

        <div class="ems-cols ems-split">
            <x-filament::section heading="History" description="Newest first. History is never edited; a correction is a new entry.">
                <ol class="space-y-3" data-testid="request-history">
                    @foreach ($timeline as $event)
                        <li class="text-sm" wire:key="event-{{ $event['id'] }}">
                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $event['at'] }} · {{ $event['actor_name'] }}@if (filled($event['actor_rank'])), {{ $event['actor_rank'] }}@endif · {{ $event['actor_context_label'] }}</p>
                            <p class="text-gray-950 dark:text-white">{{ $event['sentence'] }}</p>
                            @if ($event['stock'])
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $event['stock'] }}</p>
                            @endif
                            @if ($event['from_status'] && $event['to_status'])
                                <p class="text-xs text-gray-600 dark:text-gray-400">Status: {{ \App\Filament\Support\EmsLabels::status($event['from_status']) }} to {{ \App\Filament\Support\EmsLabels::status($event['to_status']) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>

            <x-filament::section heading="Messages" description="Between the member and the EMS supply room.">
                @if ($messages === [])
                    <p class="text-sm text-gray-600 dark:text-gray-400">No messages yet.</p>
                @else
                    <ul class="space-y-3" data-testid="request-messages">
                        @foreach ($messages as $message)
                            <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10" wire:key="message-{{ $message['id'] }}">
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ $message['at'] }} · {{ $message['author_name'] }}@if (filled($message['author_rank'])), {{ $message['author_rank'] }}@endif · {{ $message['author_context_label'] }}</p>
                                <p class="mt-1 whitespace-pre-line text-gray-950 dark:text-white">{{ $message['body'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
