@php
    /** @var array<string, mixed> $detail member-safe projection (InventoryCorrectionProjection::memberDetail) */
    $tone = match ($detail['status']) {
        'submitted' => 'warning',
        'clarification_requested' => 'info',
        'applied' => 'success',
        default => 'neutral',
    };
    $target = is_array($detail['target']) ? $detail['target'] : [];
    $labels = ['to_compartment_id' => 'Moved to', 'to_location_text' => 'Where it is now', 'name' => 'Name', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'description' => 'Description', 'quantity' => 'Quantity'];
    $titles = [
        'submitted' => 'You reported this',
        'clarification_requested' => 'Logistics asked a question',
        'member_replied' => 'You replied',
        'logistics_replied' => 'Logistics replied',
        'approved' => 'Approved',
        'applied' => 'Applied to the checklist',
        'rejected' => 'Rejected',
        'reversed' => 'Change reversed',
        'joined' => 'Same change reported',
        'merged_into' => 'Combined with another request',
        'closed' => 'Closed, no change needed',
        'withdrawn' => 'Withdrawn',
    ];
    $mine = $detail['reported_by_viewer'] ?? true;
    $entryTitle = fn (array $entry): string => match (true) {
        $entry['type'] === 'submitted' && ! ($entry['mine'] ?? true) => 'Reported by another member',
        $entry['type'] === 'member_replied' && ! ($entry['mine'] ?? true) => 'Another member replied',
        default => $titles[$entry['type']] ?? 'Update',
    };
@endphp
<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee" data-inventory-correction-member="{{ $detail['id'] }}">
    <div class="hub-insp hub-insp-stack">
        <section class="hub-insp-card" aria-labelledby="mic-title">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="mic-title">{{ $detail['item'] }}@if($detail['compartment']) <small>· {{ $detail['compartment'] }}</small>@endif</h2>
                    <p class="hub-insp-sub">{{ $detail['reference'] }} · {{ $detail['apparatus'] }} · {{ $detail['reason'] }}</p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $tone }}" data-status="{{ $detail['status'] }}">{{ $detail['status_label'] }}</span>
            </div>
            @if($detail['needs_reply'])
                <p class="hub-insp-callout" role="status">Logistics asked you a question. Reply below so they can finish reviewing your correction.</p>
            @elseif($detail['status'] === 'applied')
                <p class="hub-insp-callout" role="status">The Daily Checkout checklist now reflects this correction{{ count($detail['applied_to']) > 0 ? ' on '.implode(', ', $detail['applied_to']) : '' }}.</p>
            @elseif($detail['status'] === 'submitted')
                <p class="hub-insp-note">Waiting for Logistics to approve or reject it. Keep recording what you see on each Daily Checkout until it is approved.</p>
            @elseif($detail['status'] === 'rejected')
                <p class="hub-insp-callout" role="status" data-rejected>Logistics rejected this request. The equipment list was not changed.{{ blank($detail['decision_reason']) ? ' They did not add a message.' : '' }} You can report it again from a Daily Checkout if something is still wrong.</p>
            @elseif($detail['status'] === 'merged' && $detail['combined_into'])
                <p class="hub-insp-callout" role="status" data-combined>Another member already reported this change, so this request was combined with <a href="{{ \App\Filament\Employee\Pages\MyInventoryCorrectionView::getUrl(['correction' => $detail['combined_into']['id']], panel: 'employee') }}">{{ $detail['combined_into']['reference'] }}</a>. You will be told the decision there.</p>
            @elseif($detail['status'] === 'closed')
                <p class="hub-insp-callout" role="status" data-closed>Logistics closed this request without changing the equipment list because no change was needed (for example, it was already corrected). This is not a rejection. You can report it again from a Daily Checkout if something is still wrong.</p>
            @elseif($detail['status'] === 'withdrawn')
                <p class="hub-insp-callout" role="status" data-withdrawn>You withdrew this request. Logistics will not review it and the equipment list was not changed. You can report it again from a Daily Checkout if needed.</p>
            @elseif($detail['status'] === 'reversed')
                <p class="hub-insp-callout" role="status" data-reversed>Logistics approved this correction earlier and has since reversed it, so the Daily Checkout checklist is back to how it was. Their reason is below. You can report it again from a Daily Checkout if something is still wrong.</p>
            @endif
            @if(($detail['members'] ?? 1) > 1 && $detail['status'] !== 'merged')
                <p class="hub-insp-note" data-members="{{ $detail['members'] }}">{{ $detail['members'] }} members reported this change. Logistics decides it once for everyone.</p>
            @endif
            @if(filled($detail['decision_reason']) && $detail['status'] !== 'merged')
                <p class="hub-insp-item__sub">Logistics' note</p>
                <p class="hub-insp-item__text">{{ $detail['decision_reason'] }}</p>
            @endif
        </section>

        <section class="hub-insp-card" aria-labelledby="mic-report">
            <h3 class="hub-insp-card__title" id="mic-report">{{ $mine ? 'What you reported' : 'What was reported' }}</h3>
            <dl class="hub-insp-fields">
                <div><dt>Checklist said</dt><dd>{{ $target['name'] ?? 'Not on the checklist' }}@if(isset($target['expectedQuantity'])) (quantity {{ $target['expectedQuantity'] }})@endif</dd></div>
                @foreach($detail['proposal'] as $key => $value)
                    @continue($key === 'to_compartment_id')
                    <div><dt>{{ $labels[$key] ?? $key }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if(filled($detail['note']))
                <p class="hub-insp-item__sub">{{ $mine ? 'Your note' : 'Note from the first member to report it' }}</p>
                <p class="hub-insp-item__text">{{ $detail['note'] }}</p>
            @endif
            @if($detail['modified'])
                <p class="hub-insp-note">Logistics adjusted the details before approving. Your original report is kept.</p>
            @endif
        </section>

        <section id="thread" class="hub-insp-card hub-insp-anchor" aria-labelledby="mic-thread">
            <h3 class="hub-insp-card__title" id="mic-thread">Updates</h3>
            <ol class="hub-insp-timeline">
                @foreach($detail['thread'] as $entry)
                    <li class="hub-insp-timeline__item" data-event="{{ $entry['type'] }}">
                        <div class="hub-insp-timeline__head">
                            <p class="hub-insp-timeline__title">{{ $entryTitle($entry) }}</p>
                        </div>
                        <p class="hub-insp-item__sub">{{ $entry['at']?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }} · {{ $entry['from'] }}</p>
                        @if(filled($entry['body']))
                            <p class="hub-insp-item__text">{{ $entry['body'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if($detail['can_reply'] ?? true)
            <form class="hub-insp-reply" wire:submit="sendReply">
                <label for="mic-reply">{{ $detail['needs_reply'] ? 'Your answer to Logistics' : 'Message Logistics about this correction' }}</label>
                <textarea id="mic-reply" wire:model="reply" rows="3" maxlength="2000"
                    @error('reply') aria-invalid="true" aria-describedby="mic-reply-error" @enderror></textarea>
                @error('reply')
                    <p id="mic-reply-error" role="alert">{{ $message }}</p>
                @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="sendReply">Send reply</button>
            </form>
            @endif
            @if(! ($detail['can_reply'] ?? true) && $detail['status'] !== 'merged')
                <p class="hub-insp-note" data-closed-thread>This request is closed, so replies are off. If something on the truck is still wrong, report it again from the item in a Daily Checkout.</p>
            @endif
            @if($detail['can_withdraw'] ?? false)
                <details class="hub-insp-withdraw" data-withdraw>
                    <summary>Withdraw this request</summary>
                    <p>Use this if you reported it by mistake or it is no longer needed. Logistics will not review it and the checklist stays as it is.</p>
                    <form class="hub-insp-reply" wire:submit="withdraw">
                        <label for="mic-withdraw-note">Reason (optional, shared with Logistics)</label>
                        <textarea id="mic-withdraw-note" wire:model="withdrawNote" rows="2" maxlength="2000"
                            @error('withdrawNote') aria-invalid="true" aria-describedby="mic-withdraw-error" @enderror></textarea>
                        @error('withdrawNote')
                            <p id="mic-withdraw-error" role="alert">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="hub-insp-btn" wire:loading.attr="disabled" wire:target="withdraw">Withdraw request</button>
                    </form>
                </details>
            @elseif(filled($detail['withdraw_refusal'] ?? null) && ($detail['reported_by_viewer'] ?? false))
                <p class="hub-insp-note" data-withdraw-refusal>{{ $detail['withdraw_refusal'] }}</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
