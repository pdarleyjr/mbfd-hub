@php
    $statusField = $statusField ?? null;
    $priorityField = $priorityField ?? null;
    $dangerStatuses = $dangerStatuses ?? [];
    $subtitle = $subtitle ?? null;
@endphp

<div class="rounded-lg border" style="border-color: #E8E5E0;">
    {{-- Section Header --}}
    <div class="flex items-center justify-between px-4 py-2.5" style="background-color: #FAFAF8; border-bottom: 1px solid #E8E5E0;">
        <div class="flex items-center gap-2">
            <x-dynamic-component :component="$icon" class="w-4 h-4" style="color: #78716C;" />
            <span class="text-sm font-semibold" style="color: #292524;">{{ $title }}</span>
            @if($subtitle)
                <span class="text-xs" style="color: #A8A29E;">{{ $subtitle }}</span>
            @endif
        </div>
        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-xs font-bold rounded-full
            {{ $count > 0 ? 'text-white' : '' }}"
            style="{{ $count > 0 ? 'background-color: #B91C1C;' : 'background-color: #E8E5E0; color: #78716C;' }}">
            {{ $count }}
        </span>
    </div>

    {{-- Section Content --}}
    @if(count($items) > 0)
        <div class="divide-y" style="border-color: #F0EDE8;">
            {{-- Column Headers (hidden on mobile) --}}
            <div class="hidden md:grid gap-3 px-4 py-2" style="grid-template-columns: repeat({{ count($columns) }}, 1fr) 2rem; background-color: #FAFAF8;">
                @foreach($columns as $colLabel)
                    <span class="text-xs font-medium uppercase tracking-wider" style="color: #A8A29E;">{{ $colLabel }}</span>
                @endforeach
                <span></span>
            </div>

            @foreach($items as $item)
                <a href="{{ $item['url'] ?? '#' }}"
                   class="grid gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 group items-center"
                   style="grid-template-columns: repeat({{ count($columns) }}, 1fr) 2rem;">
                    @foreach($columns as $colKey => $colLabel)
                        <div class="text-sm truncate" style="color: #44403C;">
                            {{-- Mobile label --}}
                            <span class="md:hidden text-xs font-medium block" style="color: #A8A29E;">{{ $colLabel }}</span>

                            @if($statusField && $colKey === $statusField)
                                @php
                                    $statusVal = $item[$colKey] ?? '';
                                    $isDanger = in_array($statusVal, $dangerStatuses) || $statusVal === 'fail';
                                    $isPending = in_array($statusVal, ['pending', 'review_pending'], true);
                                    $isSuccess = in_array($statusVal, ['pass', 'approved', 'fulfilled']);

                                    if ($isDanger) {
                                        $badgeBg = '#FEE2E2'; $badgeColor = '#991B1B';
                                    } elseif ($isPending) {
                                        $badgeBg = '#FEF3C7'; $badgeColor = '#92400E';
                                    } elseif ($isSuccess) {
                                        $badgeBg = '#DCFCE7'; $badgeColor = '#166534';
                                    } else {
                                        $badgeBg = '#F1F0EE'; $badgeColor = '#57534E';
                                    }
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      style="background-color: {{ $badgeBg }}; color: {{ $badgeColor }};">
                                    {{ ucfirst(str_replace('_', ' ', $statusVal)) }}
                                </span>
                            @elseif($priorityField && $colKey === $priorityField)
                                @php
                                    $prioVal = $item[$colKey] ?? 'medium';
                                    $prioBg = match($prioVal) {
                                        'critical' => '#FEE2E2',
                                        'high' => '#FFEDD5',
                                        'low' => '#F1F0EE',
                                        default => '#FEF3C7',
                                    };
                                    $prioColor = match($prioVal) {
                                        'critical' => '#991B1B',
                                        'high' => '#9A3412',
                                        'low' => '#57534E',
                                        default => '#92400E',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      style="background-color: {{ $prioBg }}; color: {{ $prioColor }};">
                                    {{ ucfirst($prioVal) }}
                                </span>
                            @else
                                {{ $item[$colKey] ?? '-' }}
                            @endif
                        </div>
                    @endforeach

                    {{-- Arrow icon --}}
                    <div class="flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                        <x-heroicon-o-chevron-right class="w-4 h-4" style="color: #A8A29E;" />
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="text-center py-6">
            <x-dynamic-component :component="$icon" class="w-8 h-8 mx-auto" style="color: #D4D0CA;" />
            <p class="mt-1.5 text-sm" style="color: #A8A29E;">{{ $emptyMessage }}</p>
        </div>
    @endif
</div>
