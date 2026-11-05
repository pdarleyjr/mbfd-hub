{{-- Supply room screen tab (docs/ems/supply-room-kiosk.md section 7). Filament sections, badges and actions only. --}}
<x-filament::section heading="Supply room screens"
    description="The touchscreen in the locked supply room. Members type their employee ID and take supplies without signing in; each withdrawal comes off the master inventory at once.">
    @if ($canManageScreens)
        <div class="mb-3">{{ $this->pairScreenAction }}</div>
    @endif
    @if ($screens === [])
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-screens">No screen is paired yet. Use Pair supply room screen and open the link on the supply room tablet.</p>
    @else
        <div class="ems-tablewrap">
            <table data-hub-table class="ems-medium w-full text-left text-sm" data-testid="kiosk-screens">
                <caption class="sr-only">Supply room screens</caption>
                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">Screen</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium">Last checked in (Eastern)</th>
                        <th scope="col" class="px-3 py-2 font-medium">Paired</th>
                        <th scope="col" class="py-2 pl-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($screens as $screen)
                        <tr wire:key="screen-{{ $screen['id'] }}">
                            <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $screen['name'] }}</td>
                            <td class="px-3 py-2">
                                <x-filament::badge size="sm" :color="match ($screen['state']) { 'online' => 'success', 'offline' => 'danger', 'waiting' => 'warning', default => 'gray' }">{{ $screen['state_label'] }}</x-filament::badge>
                            </td>
                            <td class="px-3 py-2">{{ $screen['last_seen'] ?? 'Never' }}</td>
                            <td class="px-3 py-2">{{ $screen['paired_at'] ?? 'Not yet' }} <span class="block text-xs text-gray-600 dark:text-gray-400">Link made by {{ $screen['paired_by'] }}</span></td>
                            <td class="py-2 pl-3 text-right">@if ($canManageScreens && $screen['state'] !== 'revoked'){{ ($this->revokeScreenAction)(['screen' => $screen['id']]) }}@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>

<x-filament::section heading="Withdrawals at the screen" description="Newest first. Each one already came off the master inventory. A line marked count due was taken while the inventory showed the shelf empty.">
    @if ($canManageScreens && $search_words_waiting > 0)
        <div class="mb-3 flex items-center gap-2">
            <x-filament::badge color="info">{{ $search_words_waiting }} search {{ $search_words_waiting === 1 ? 'word' : 'words' }} to review</x-filament::badge>
            {{ $this->searchWordsAction }}
        </div>
    @endif
    @if ($withdrawals === [])
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="no-withdrawals">No withdrawals at the screen yet.</p>
    @else
        <div class="ems-tablewrap">
            <table data-hub-table class="ems-medium w-full text-left text-sm" data-testid="kiosk-withdrawals">
                <caption class="sr-only">Supply room screen withdrawals, newest first</caption>
                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-medium">When (Eastern)</th>
                        <th scope="col" class="px-3 py-2 font-medium">Member</th>
                        <th scope="col" class="px-3 py-2 font-medium">Unit</th>
                        <th scope="col" class="px-3 py-2 font-medium">Items</th>
                        <th scope="col" class="px-3 py-2 font-medium">Number</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($withdrawals as $withdrawal)
                        <tr wire:key="withdrawal-{{ $withdrawal['uuid'] }}">
                            <td class="py-2 pr-3">{{ $withdrawal['taken_at'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $withdrawal['employee_name'] }}
                                @if ($withdrawal['status'] === 'undone') <x-filament::badge size="sm" color="gray">Undone</x-filament::badge>@endif
                            </td>
                            <td class="px-3 py-2">{{ $withdrawal['unit_label'] ?? 'None given' }}</td>
                            <td class="px-3 py-2">
                                <ul>
                                    @foreach ($withdrawal['lines'] as $line)
                                        <li>{{ $line['name'] }}: {{ $line['quantity'] }} {{ $line['unit_label'] }}@if ($line['count_due']) <x-filament::badge size="sm" color="danger">Count due</x-filament::badge>@endif</li>
                                    @endforeach
                                    @if ($withdrawal['note'])<li class="text-gray-600 dark:text-gray-400">Note: {{ $withdrawal['note'] }}</li>@endif
                                </ul>
                            </td>
                            <td class="px-3 py-2">{{ $withdrawal['number'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
