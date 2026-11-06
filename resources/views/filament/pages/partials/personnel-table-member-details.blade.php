<div class="space-y-5">
    @include('filament.pages.personnel-directory-details')
    @if (count($trackingColumns) > 0)
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($trackingColumns as $trackingColumn)
                @php($trackingValue = $trackingValues[$trackingColumn['id']] ?? ($trackingColumn['type'] === 'checkbox' ? false : null))
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $trackingColumn['label'] }}</dt>
                    @if ($trackingColumn['type'] === 'link' && ($trackingLink = \App\Services\Personnel\PersonnelTableService::safeLink($trackingValue)) !== null)
                        <dd class="mt-1 break-words text-sm"><a href="{{ $trackingLink }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline dark:text-primary-400">{{ $trackingLink }}</a></dd>
                    @else
                        <dd class="mt-1 whitespace-pre-wrap break-words text-sm text-gray-950 dark:text-white">{{ is_bool($trackingValue) ? ($trackingValue ? 'Checked' : 'Unchecked') : (is_array($trackingValue) ? implode(', ', $trackingValue) : ($trackingValue ?? 'Not recorded')) }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @endif
</div>
