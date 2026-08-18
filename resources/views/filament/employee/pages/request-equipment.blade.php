<x-filament-panels::page>
    <div class="pr-page-grid">
        <section class="pr-card pr-form-card">
            <header class="pr-card-header">
                <div class="pr-eyebrow">PERSONALLY ISSUED UNIFORMS</div>
                <h2>Build your uniform request</h2>
                <p>Select only department workwear. Structural firefighting PPE is handled by an authorized officer through the Personnel Equipment Request workflow.</p>
            </header>
            <form wire:submit="submit" class="pr-card-body">
                {{ $this->form }}
                <button type="submit" wire:loading.attr="disabled" class="pr-primary-action">
                    <span wire:loading.remove wire:target="submit">Submit Uniform Request</span>
                    <span wire:loading wire:target="submit">Submitting…</span>
                </button>
            </form>
        </section>

        <aside class="pr-card">
            <header class="pr-card-header pr-compact-header">
                <div><div class="pr-eyebrow">CHAIN OF CUSTODY</div><h2>Recent uniform requests</h2></div>
                <a href="/employee/my-requests">View all</a>
            </header>
            @forelse($recentRequests as $request)
                <a href="/employee/my-requests/{{ $request->public_id }}" class="pr-request-row">
                    <span><strong>{{ $request->request_number }}</strong><small>{{ $request->items()->count() }} item(s) · {{ $request->created_at->format('M j, Y') }}</small></span>
                    <span class="pr-status">{{ $request->status->label() }}</span>
                </a>
            @empty
                <div class="pr-empty">No uniform requests yet. Your submitted requests will appear here.</div>
            @endforelse
        </aside>
    </div>

    <style>
        .pr-page-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(18rem,.65fr);gap:1rem;align-items:start}.pr-card{overflow:hidden;border:1px solid #e7e5e4;border-radius:1rem;background:#fff;box-shadow:0 1px 2px rgb(15 23 42/.04)}.pr-card-header{padding:1.5rem;border-bottom:1px solid #e7e5e4;background:#fafaf9}.pr-card-header h2{margin:.35rem 0 0;font-size:1.2rem;font-weight:800;color:#0f172a}.pr-card-header p{max-width:46rem;margin:.5rem 0 0;color:#57534e;font-size:.875rem;line-height:1.6}.pr-eyebrow{font-size:.7rem;font-weight:800;letter-spacing:.12em;color:#1d4ed8}.pr-card-body{padding:1.5rem}.pr-primary-action{display:inline-flex;min-height:3rem;align-items:center;justify-content:center;margin-top:1.25rem;padding:.75rem 1.25rem;border-radius:.75rem;background:#c2410c;color:#fff;font-weight:800;box-shadow:0 1px 2px rgb(0 0 0/.12)}.pr-primary-action:hover{background:#9a3412}.pr-primary-action:focus-visible{outline:3px solid #2563eb;outline-offset:3px}.pr-primary-action:disabled{opacity:.6}.pr-compact-header{display:flex;align-items:center;justify-content:space-between;gap:1rem}.pr-compact-header a{display:inline-flex;min-height:3rem;align-items:center;color:#1d4ed8;font-size:.85rem;font-weight:700}.pr-request-row{display:flex;min-height:4.5rem;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.25rem;border-bottom:1px solid #f5f5f4;text-decoration:none}.pr-request-row:hover{background:#fafaf9}.pr-request-row strong{display:block;color:#0f172a;font-size:.875rem}.pr-request-row small{display:block;margin-top:.25rem;color:#78716c}.pr-status{flex-shrink:0;border-radius:999px;background:#dbeafe;padding:.3rem .6rem;color:#1e3a8a;font-size:.7rem;font-weight:800}.pr-empty{padding:2rem 1.25rem;color:#78716c;font-size:.875rem;line-height:1.6}@media(max-width:900px){.pr-page-grid{grid-template-columns:1fr}}@media(max-width:640px){.pr-card-header,.pr-card-body{padding:1rem}.pr-primary-action{width:100%}}
    </style>
</x-filament-panels::page>
