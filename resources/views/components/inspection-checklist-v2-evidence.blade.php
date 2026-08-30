@props(['evidence'])

@if(is_array($evidence))
    <section class="mt-5 rounded-lg border border-sky-200 bg-sky-50 p-4 dark:border-sky-800 dark:bg-sky-900/10">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-semibold text-sky-950 dark:text-sky-100">Checklist v2 evidence</h4>
            @if(filled($evidence['template_id'] ?? null))
                <p class="text-xs text-sky-800 dark:text-sky-200">
                    {{ $evidence['template_id'] }}@if(filled($evidence['template_version'] ?? null)) · {{ $evidence['template_version'] }}@endif
                </p>
            @endif
        </div>

        @if(count($evidence['field_values'] ?? []) > 0)
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sky-200 dark:border-sky-800">
                            <th class="px-2 py-2 text-left font-medium text-sky-900 dark:text-sky-100">Field</th>
                            <th class="px-2 py-2 text-left font-medium text-sky-900 dark:text-sky-100">Reported value</th>
                            <th class="px-2 py-2 text-left font-medium text-sky-900 dark:text-sky-100">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($evidence['field_values'] as $field)
                            @php
                                $value = $field['value'] ?? null;
                                $displayValue = $value === true
                                    ? 'Yes'
                                    : ($value === false ? 'No' : ($value === null || $value === '' ? 'Not reported' : (string) $value));
                            @endphp
                            <tr class="border-b border-sky-100 dark:border-sky-900">
                                <td class="px-2 py-2 font-medium text-gray-900 dark:text-white">{{ $field['name'] ?? $field['id'] ?? 'Unnamed field' }}</td>
                                <td class="px-2 py-2 text-gray-700 dark:text-gray-200">{{ $displayValue }}</td>
                                <td class="px-2 py-2 text-gray-600 dark:text-gray-300">{{ ucfirst((string) ($field['input_type'] ?? 'unknown')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(count($evidence['scheduled_tasks'] ?? []) > 0)
            <div class="mt-4 space-y-3">
                <h5 class="text-sm font-semibold text-sky-950 dark:text-sky-100">Scheduled duties</h5>
                @foreach($evidence['scheduled_tasks'] as $task)
                    <div class="rounded-lg border border-sky-200 bg-white p-3 dark:border-sky-800 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $task['name'] ?? $task['id'] ?? 'Unnamed duty' }}</p>
                                <p class="text-xs text-gray-600 dark:text-gray-300">{{ $task['recurrence_label'] ?? 'Configured recurrence' }}</p>
                            </div>
                            <span class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/30 dark:text-sky-200">{{ $task['status'] ?? 'Reported' }}</span>
                        </div>
                        @if(filled($task['instructions'] ?? null))
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $task['instructions'] }}</p>
                        @endif
                        @if(filled($task['notes'] ?? null))
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Notes: {{ $task['notes'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endif
