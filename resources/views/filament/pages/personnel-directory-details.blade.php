<dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    @foreach($fields as $key => $label)
        <div>
            <dt class="text-sm font-medium text-gray-500">{{ $label }}</dt>
            <dd class="mt-1 break-words text-sm text-gray-950">{{ ($key === 'email_match_status' ? $emailStatus : $source->value($record, $key)) ?: 'Not recorded' }}</dd>
        </div>
    @endforeach
</dl>
