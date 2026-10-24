<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <p class="hub-portal-intro">Replace personally issued firefighting equipment. This request is for an individual member. Station inventory and repair requests remain in the Stations workspace.</p>

    <form wire:submit="submit" class="ppe-form">
        {{ $this->form }}
        <div class="ppe-submit-bar">
            <p>Submission records the authenticated officer, beneficiary, station, all items, and the private signature.</p>
            <button type="submit" wire:loading.attr="disabled"><span wire:loading.remove wire:target="submit">Sign & Submit Request</span><span wire:loading wire:target="submit">Submitting…</span></button>
        </div>
    </form>
</x-filament-panels::page>
