{{-- "By form": requests, requested quantity and issued quantity for each of the four medical inventory forms. Requests, not consumption. --}}
@if ($rows !== [] && ! collect($sections)->contains(fn ($section): bool => $section->heading === 'By form'))
    <x-filament::section heading="By form" description="Requests sent on each medical inventory form, and the quantities asked for and issued. These are requests, not supplies used on calls." wire:key="section-by-form">
        <div class="ems-tablewrap">
            <table data-hub-table class="w-full text-left text-sm" data-testid="stats-by-form">
                <caption class="sr-only">By form</caption>
                <thead class="border-b border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium first:pl-0">Form</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Requests</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Requested quantity</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Issued quantity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row" class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $row['title'] ?? $row['family'] ?? 'Form' }}</th>
                            <td class="px-3 py-2 text-right">{{ number_format((int) ($row['requests'] ?? 0)) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((int) ($row['qty_requested'] ?? 0)) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format((int) ($row['qty_issued'] ?? 0)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif
