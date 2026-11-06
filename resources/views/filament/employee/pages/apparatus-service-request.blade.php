<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <p class="hub-portal-intro">Report apparatus repair or service needs. Fleet receives the request immediately. Station personnel can follow the public operational status without exposing employee or mechanic notes.</p>

    <form wire:submit="submit" class="ast-employee-form">
        {{ $this->form }}
        <div class="ast-employee-submit">
            <p>Your authenticated employee identity is recorded privately with the ticket. Review the unit and description before submitting.</p>
            <button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">Submit Service Request</span>
                <span wire:loading wire:target="submit">Submitting…</span>
            </button>
        </div>
    </form>

    @if($myTickets->isNotEmpty())
        {{-- The member's own recent requests, each with its conversation (docs/member-replies.md section 2.3). --}}
        <section class="hub-insp-card mt-6" aria-labelledby="my-service-requests" data-my-service-requests>
            <h2 class="hub-insp-card__title" id="my-service-requests">Your recent service requests</h2>
            <ul class="mt-3 space-y-2">
                @foreach($myTickets as $ticket)
                    <li>
                        <a class="hub-insp-item__text font-semibold text-hub-blue" href="{{ \App\Services\MemberReplies\SubmissionConversationNotifier::memberUrl(\App\Support\MemberReplies\ReplySubject::APPARATUS_SERVICE_TICKET, (int) $ticket->id) }}">{{ $ticket->ticket_number }} · {{ $ticket->unit_designation_snapshot }} · {{ $ticket->title }}</a>
                        <p class="hub-insp-item__sub">{{ str((string) $ticket->status)->replace('_', ' ')->title() }}@if($ticket->current_public_response) · {{ str($ticket->current_public_response)->limit(120) }}@endif</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-panels::page>
