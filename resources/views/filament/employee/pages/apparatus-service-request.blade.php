<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <section class="ast-employee-heading" aria-labelledby="ast-request-heading">
        <div class="ast-employee-mark" aria-hidden="true">AST</div>
        <div>
            <span>AUTHENTICATED FLEET REQUEST</span>
            <h2 id="ast-request-heading">Report apparatus repair or service needs</h2>
            <p>Fleet receives the request immediately. Station personnel can follow the public operational status without exposing employee or mechanic notes.</p>
        </div>
    </section>

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
