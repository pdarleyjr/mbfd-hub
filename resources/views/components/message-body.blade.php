@props([
    'text',
    'limit' => 480,
])
{{--
    A readable, safe message body: real paragraphs (blank-line separated), single line breaks kept,
    links via SafeLinkifier (everything escaped), 16px / 1.55 line height / ~70ch, and an explicit
    "Show full message / Show less" for long text. Used by the Command Communications page; the
    visual rules are the mbody-* classes in resources/css/filament/employee-messages.css.
--}}
@php
    $raw = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $paragraphs = array_values(array_filter(
        array_map(static fn (string $part): string => trim($part, "\n"), preg_split('/\n{2,}/', $raw) ?: []),
        static fn (string $part): bool => trim($part) !== '',
    ));
    $long = mb_strlen($raw) > $limit || substr_count($raw, "\n") > 7;
    $uid = 'mbody-'.\Illuminate\Support\Str::random(8);
@endphp
<div {{ $attributes->class('mbody') }} @if ($long) x-data="{ open: false }" @endif data-message-body>
    <div id="{{ $uid }}" @class(['mbody__text', 'mbody__text--clamped' => $long]) @if ($long) x-bind:class="{ 'mbody__text--clamped': ! open }" @endif>
        @foreach ($paragraphs as $paragraph)
            <p>{{ \App\Support\SafeLinkifier::render($paragraph) }}</p>
        @endforeach
    </div>
    @if ($long)
        <button type="button" class="mbody__toggle" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $uid }}" data-message-body-toggle>
            <span x-text="open ? 'Show less' : 'Show full message'">Show full message</span>
        </button>
    @endif
</div>
