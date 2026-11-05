<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-300">This is how it reads. Members never see this page; automated answers use it as background only after it is published.</p>
    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $title }}</h3>
    <div class="prose max-w-none text-sm text-gray-900 dark:prose-invert">{!! $html !!}</div>
    @if (! empty($checks))
        <div class="border-t border-gray-200 pt-3 dark:border-white/10">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Would it be picked for these messages?</h4>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($checks as $check)
                    <li class="{{ $check['ok'] ? 'text-success-700 dark:text-success-400' : 'text-warning-700 dark:text-warning-400' }}">
                        <span class="font-semibold">{{ $check['ok'] ? 'Yes' : 'Not yet' }}:</span> &ldquo;{{ $check['phrase'] }}&rdquo; &mdash; {{ $check['note'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
