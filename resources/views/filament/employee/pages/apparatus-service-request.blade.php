<x-filament-panels::page>
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

    <style>
        .ast-employee-heading{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;padding:1.25rem 1.4rem;border-radius:1rem;background:#0f2742;color:#fff}.ast-employee-mark{display:flex;width:3.5rem;height:3.5rem;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #60a5fa;border-radius:50%;color:#bfdbfe;font:800 .72rem/1 "JetBrains Mono",monospace;letter-spacing:.08em}.ast-employee-heading span{font-size:.68rem;font-weight:800;letter-spacing:.12em;color:#93c5fd}.ast-employee-heading h2{margin:.25rem 0 0;font-size:1.2rem;font-weight:800}.ast-employee-heading p{max-width:48rem;margin:.35rem 0 0;color:#cbd5e1;font-size:.85rem;line-height:1.5}.ast-employee-form{padding:1.25rem;border:1px solid #e7e5e4;border-radius:1rem;background:#fff}.ast-employee-submit{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #e7e5e4}.ast-employee-submit p{max-width:38rem;margin:0;color:#57534e;font-size:.8rem;line-height:1.5}.ast-employee-submit button{display:inline-flex;min-height:3rem;align-items:center;justify-content:center;flex-shrink:0;border-radius:.75rem;background:#c2410c;padding:.75rem 1.15rem;color:#fff;font-weight:800}.ast-employee-submit button:hover{background:#9a3412}.ast-employee-submit button:focus-visible{outline:3px solid #2563eb;outline-offset:3px}.ast-employee-notice{border:1px solid #93c5fd;border-left-width:4px;border-radius:.75rem;background:#eff6ff;padding:1rem;color:#1e3a8a}.ast-employee-notice p{margin:.35rem 0 0;font-size:.85rem;line-height:1.55}@media(max-width:640px){.ast-employee-heading{align-items:flex-start}.ast-employee-form{padding:1rem}.ast-employee-submit{align-items:stretch;flex-direction:column}.ast-employee-submit button{width:100%;min-height:3rem}}
    </style>
</x-filament-panels::page>
