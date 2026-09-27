@php
    $data = $getRecord()->form_data ?? [];
    $checklist = is_array($data) ? ($data['checklist'] ?? $data) : [];
    $checklist = is_array($checklist) ? $checklist : [];
    $hasCategories = collect($checklist)->contains(fn ($item) => is_array($item) && is_string($item['category'] ?? null));
    $categories = $hasCategories
        ? collect($checklist)->filter(fn ($item) => is_array($item))->groupBy(fn (array $item) => is_string($item['category'] ?? null) ? $item['category'] : 'Other')
        : collect();
    $statusIcons = ['pass' => '✅', 'fail' => '❌', 'na' => '➖'];
@endphp

@if ($checklist === [])
    <p>No checklist data</p>
@elseif ($hasCategories)
    <div class="space-y-4">
        @foreach ($categories as $category => $items)
            <div>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $category }}</h4>
                <div class="space-y-2">
                    @foreach ($items as $item)
                        @php
                            $status = is_string($item['status'] ?? null) ? strtolower($item['status']) : '';
                            $label = is_string($item['label'] ?? null) ? $item['label'] : ($item['id'] ?? 'Checklist item');
                            $notes = is_string($item['failNotes'] ?? null) ? $item['failNotes'] : '';
                            $imagePath = is_string($item['failImage'] ?? null) ? $item['failImage'] : '';
                            $hasStoredImage = $status === 'fail' && preg_match('~^station-inspections/[a-zA-Z0-9/_-]+\.(?:png|jpe?g|webp|gif)$~D', $imagePath) === 1;
                        @endphp
                        <div>
                            <span>{{ $statusIcons[$status] ?? '⬜' }} {{ is_scalar($label) ? $label : 'Checklist item' }}</span>
                            @if ($status === 'fail' && $notes !== '')
                                <p class="ml-6 whitespace-pre-wrap text-sm text-danger-600">Notes: {{ $notes }}</p>
                            @endif
                            @if ($hasStoredImage)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath) }}" alt="Fail photo" class="ml-6 mt-2 max-h-48 max-w-xs rounded border border-danger-200" />
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="space-y-2">
        @foreach ($checklist as $key => $value)
            <div>
                <strong>{{ is_string($key) ? str_replace('_', ' ', ucfirst($key)) : $key }}:</strong>
                {{ is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : '')) }}
            </div>
        @endforeach
    </div>
@endif
