<x-filament-panels::page>
    <div class="ppe-heading">
        <div class="ppe-mark" aria-hidden="true">PPE</div>
        <div><span>OFFICER-AUTHORIZED WORKFLOW</span><h2>Replace personally issued firefighting equipment</h2><p>This request is for an individual member. Station inventory and repair requests remain in the Stations workspace.</p></div>
    </div>

    <form wire:submit="submit" class="ppe-form">
        {{ $this->form }}
        <div class="ppe-submit-bar">
            <p>Submission records the authenticated officer, beneficiary, station, all items, and the private signature.</p>
            <button type="submit" wire:loading.attr="disabled"><span wire:loading.remove wire:target="submit">Sign & Submit Request</span><span wire:loading wire:target="submit">Submitting…</span></button>
        </div>
    </form>

    <style>
        .ppe-heading{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;padding:1.25rem 1.4rem;border-radius:1rem;background:#0f2742;color:#fff}.ppe-mark{display:flex;width:3.5rem;height:3.5rem;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #fbbf24;border-radius:50%;color:#fbbf24;font-size:.75rem;font-weight:900;letter-spacing:.08em}.ppe-heading span{font-size:.68rem;font-weight:800;letter-spacing:.12em;color:#93c5fd}.ppe-heading h2{margin:.25rem 0 0;font-size:1.2rem;font-weight:800}.ppe-heading p{margin:.35rem 0 0;color:#cbd5e1;font-size:.85rem}.ppe-form{padding:1.25rem;border:1px solid #e7e5e4;border-radius:1rem;background:#fff}.ppe-submit-bar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #e7e5e4}.ppe-submit-bar p{max-width:38rem;margin:0;color:#57534e;font-size:.8rem;line-height:1.5}.ppe-submit-bar button{display:inline-flex;min-height:3rem;align-items:center;justify-content:center;flex-shrink:0;border-radius:.75rem;background:#c2410c;padding:.75rem 1.15rem;color:#fff;font-weight:800}.ppe-submit-bar button:hover{background:#9a3412}.ppe-submit-bar button:focus-visible{outline:3px solid #2563eb;outline-offset:3px}.ppe-notice{border:1px solid #fbbf24;border-left-width:4px;border-radius:.75rem;background:#fffbeb;padding:1rem;color:#78350f}.ppe-notice p{margin:.35rem 0 0;font-size:.85rem;line-height:1.55}.ppe-review{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}.ppe-review>div{padding:.85rem;border:1px solid #e7e5e4;border-radius:.75rem;background:#fafaf9}.ppe-review span,.ppe-review strong{display:block}.ppe-review span{color:#78716c;font-size:.7rem;font-weight:800;text-transform:uppercase}.ppe-review strong{margin-top:.25rem;color:#0f172a;font-size:.85rem}.ppe-review-items{grid-column:1/-1}.ppe-review-items ul{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem;margin:.65rem 0 0;padding:0;list-style:none}.ppe-review-items li{padding:.65rem;border-radius:.6rem;background:#eff6ff}.ppe-review-items li span{margin-top:.2rem;color:#475569;font-size:.68rem}.ppe-review>p{grid-column:1/-1;margin:0;color:#57534e;font-size:.8rem;line-height:1.5}@media(max-width:640px){.ppe-heading{align-items:flex-start}.ppe-form{padding:1rem}.ppe-submit-bar{align-items:stretch;flex-direction:column}.ppe-submit-bar button{width:100%}.ppe-review{grid-template-columns:1fr}.ppe-review-items ul{grid-template-columns:1fr}}
    </style>
</x-filament-panels::page>
