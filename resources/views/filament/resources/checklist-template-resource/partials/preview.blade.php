@php
    /** @var array<string, mixed>|null $preview */
    $mode ??= 'publish';
    $units = $preview['units'] ?? [];
    $short = fn ($hash) => filled($hash) ? substr((string) $hash, 0, 8) : 'none';
@endphp
<div class="hub-insp hub-insp-stack hub-tpl-preview" data-template-preview="{{ $mode }}">
    @if($preview === null)
        <p class="hub-insp-note">The preview is not available. Close this dialog and try again.</p>
    @else
        @if(($preview['errors'] ?? []) !== [])
            <div class="hub-insp-card hub-insp-card--danger" role="alert" data-preview-errors>
                <h3 class="hub-insp-card__title">{{ $mode === 'revert' ? 'The repository file cannot be published' : 'Fix these before publishing' }}</h3>
                <ul class="hub-tpl-list">
                    @foreach($preview['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if(($preview['warnings'] ?? []) !== [])
            <div class="hub-insp-card hub-insp-card--warning" role="status" data-preview-warnings>
                <h3 class="hub-insp-card__title">Check before {{ $mode === 'revert' ? 'reverting' : 'publishing' }}</h3>
                <ul class="hub-tpl-list">
                    @foreach($preview['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-label="What changes">
            <h3 class="hub-tpl-subtitle">{{ $mode === 'revert' ? 'What changes compared with the published version' : 'What changes compared with the live checklist' }}</h3>
            @include('filament.resources.checklist-template-resource.partials.diff', ['diff' => $preview['diff'] ?? null, 'caption' => null])
        </section>

        <section aria-label="Units affected">
            <h3 class="hub-tpl-subtitle">Units on this checklist ({{ count($units) }})</h3>
            @if($units === [])
                <p class="hub-insp-note">No apparatus uses this checklist right now.</p>
            @else
                <div class="hub-insp-table-wrap">
                    <table data-hub-table class="hub-insp-table">
                        <caption class="sr-only">Each unit, its checklist version before and after, conflicts and open issues</caption>
                        <thead><tr><th scope="col">Unit</th><th scope="col">Checklist version</th><th scope="col">Conflicts</th><th scope="col">Open issues on removed items</th></tr></thead>
                        <tbody>
                            @foreach($units as $unit)
                                <tr data-preview-unit="{{ $unit['id'] }}" @class(['is-finding' => ($unit['conflicts'] ?? []) !== [] || (int) ($unit['open_issues_on_removed_items'] ?? 0) > 0])>
                                    <td><strong>{{ $unit['label'] }}</strong>@if(! ($unit['daily_checkout'] ?? true))<span class="is-value">Reserve, not on Daily Checkout</span>@endif</td>
                                    <td><code>{{ $short($unit['version_before'] ?? null) }}</code> → <code>{{ $short($unit['version_after'] ?? null) }}</code></td>
                                    <td>
                                        @forelse($unit['conflicts'] ?? [] as $conflict)
                                            <span class="is-value">{{ $conflict['reason'] }}</span>
                                        @empty
                                            None
                                        @endforelse
                                    </td>
                                    <td>{{ (int) ($unit['open_issues_on_removed_items'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="hub-insp-note">Conflicts are applied inventory corrections on a unit that this change overrides or makes obsolete. Open issues on removed items stay open; resolve them separately.</p>
            @endif
        </section>

        @if(! ($preview['publishable'] ?? false) && ($preview['errors'] ?? []) === [])
            <p class="hub-tpl-warning" role="status" data-not-publishable>There is nothing to {{ $mode === 'revert' ? 'revert' : 'publish' }}.</p>
        @endif
    @endif
</div>
