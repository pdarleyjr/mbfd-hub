{{--
    Rows grouped by DashboardIssue stage. A count is shown only when the sources report an exact one
    for that stage and no filter narrows the list; otherwise the group just lists what is loaded.
    Expects: $id, $title, $note, $icon, $groups (list of [key, label, icon, tone, rows, count|null]).
--}}
<section class="mbfd-dash-card mbfd-dash-queue" aria-labelledby="{{ $id }}-title" data-card="{{ $id }}">
    <header class="mbfd-dash-card__head">
        <span class="mbfd-dash-card__icon" data-tone="neutral" aria-hidden="true"><x-filament::icon :icon="$icon" class="mbfd-dash-card__svg" /></span>
        <div class="mbfd-dash-card__titles">
            <h2 id="{{ $id }}-title" class="mbfd-dash-card__title">{{ $title }}</h2>
            @if (filled($note ?? null))
                <p class="mbfd-dash-card__note">{{ $note }}</p>
            @endif
        </div>
    </header>
    <div class="mbfd-dash-queue__groups">
        @foreach ($groups as $group)
            <div class="mbfd-dash-queue__group" data-stage="{{ $group['key'] }}" data-tone="{{ $group['tone'] }}">
                <h3 class="mbfd-dash-queue__head">
                    <x-filament::icon :icon="$group['icon']" class="mbfd-dash-queue__icon" />
                    <span>{{ $group['label'] }}</span>
                    @if ($group['count'] !== null)
                        <span class="mbfd-dash-queue__count" aria-label="{{ $group['count'] }} {{ $group['label'] }}">{{ number_format($group['count']) }}</span>
                    @endif
                </h3>
                @if (count($group['rows']) === 0)
                    <p class="mbfd-dash-queue__empty">None right now.</p>
                @else
                    <ul class="mbfd-dash-list mbfd-dash-list--compact" role="list">
                        @foreach ($group['rows'] as $row)
                            @include('filament.dashboards.partials.row', ['row' => $row, 'compact' => true, 'scope' => $id.'-'.$group['key']])
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</section>
