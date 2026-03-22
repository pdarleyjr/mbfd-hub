<x-filament-panels::page>
    @if($navItem && $navItem->type === 'iframe')
        <div class="w-full" style="height: calc(100vh - 10rem);">
            <iframe
                src="{{ $navItem->url }}"
                class="w-full h-full rounded-lg border border-gray-200 dark:border-gray-700"
                referrerpolicy="no-referrer"
                sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                loading="lazy"
                title="{{ $navItem->label }}"
            ></iframe>
        </div>
    @elseif($navItem && $navItem->type === 'api_table')
        <div class="overflow-x-auto">
            @if(count($tableData) > 0)
                <table class="w-full text-sm text-left border border-gray-200 dark:border-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            @foreach($tableFields as $field)
                                <th class="px-4 py-2 font-medium text-gray-700 dark:text-gray-300">
                                    {{ $field['name'] ?? 'Field' }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tableData as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                @foreach($tableFields as $field)
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-400">
                                        @php
                                            $key = 'field_' . $field['id'];
                                            $value = $row[$key] ?? $row[$field['name']] ?? '';
                                            if (is_array($value)) $value = json_encode($value);
                                        @endphp
                                        {{ $value }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="text-center py-8 text-gray-500">
                    No data available.
                </div>
            @endif
        </div>
    @else
        <div class="text-center py-8 text-gray-500">
            Unable to load content.
        </div>
    @endif
</x-filament-panels::page>
