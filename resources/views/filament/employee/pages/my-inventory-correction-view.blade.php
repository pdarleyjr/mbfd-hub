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
        'rejected' => 'Not approved',
        'reversed' => 'Change reversed',
    ];
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
                <p class="hub-insp-note">Logistics will review it. Keep recording what you see on each Daily Checkout until it is approved.</p>
            @endif
            @if(filled($detail['decision_reason']))
                <p class="hub-insp-item__sub">Logistics' note</p>
                <p class="hub-insp-item__text">{{ $detail['decision_reason'] }}</p>
            @endif
        </section>

        <section class="hub-insp-card" aria-labelledby="mic-report">
            <h3 class="hub-insp-card__title" id="mic-report">What you reported</h3>
            <dl class="hub-insp-fields">
                <div><dt>Checklist said</dt><dd>{{ $target['name'] ?? 'Not on the checklist' }}@if(isset($target['expectedQuantity'])) (quantity {{ $target['expectedQuantity'] }})@endif</dd></div>
                @foreach($detail['proposal'] as $key => $value)
                    @continue($key === 'to_compartment_id')
                    <div><dt>{{ $labels[$key] ?? $key }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if(filled($detail['note']))
                <p class="hub-insp-item__sub">Your note</p>
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
                            <p class="hub-insp-timeline__title">{{ $titles[$entry['type']] ?? 'Update' }}</p>
                        </div>
                        <p class="hub-insp-item__sub">{{ $entry['at']?->timezone('America/New_York')->format('M j, Y g:i A') }} · {{ $entry['from'] }}</p>
                        @if(filled($entry['body']))
                            <p class="hub-insp-item__text">{{ $entry['body'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            <form class="hub-insp-reply" wire:submit="sendReply">
                <label for="mic-reply">{{ $detail['needs_reply'] ? 'Your answer to Logistics' : 'Message Logistics about this correction' }}</label>
                <textarea id="mic-reply" wire:model="reply" rows="3" maxlength="2000"
                    @error('reply') aria-invalid="true" aria-describedby="mic-reply-error" @enderror></textarea>
                @error('reply')
                    <p id="mic-reply-error" role="alert">{{ $message }}</p>
                @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="sendReply">Send reply</button>
            </form>
        </section>
    </div>
</x-filament-panels::page>
