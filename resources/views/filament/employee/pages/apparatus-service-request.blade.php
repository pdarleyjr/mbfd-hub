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
</x-filament-panels::page>
