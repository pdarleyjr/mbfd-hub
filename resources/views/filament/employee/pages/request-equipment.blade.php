<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
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
</x-filament-panels::page>
