@props(['href', 'label', 'current' => false])
@php($external = str_starts_with($href, 'https://'))
{{-- One destination link for the app switcher and the phone More sheet. An external destination opens in a new tab and says so, in text and with the arrow React draws. --}}
<a role="menuitem" href="{{ $href }}" class="hub-shell-menuitem" @if($external) target="_blank" rel="noopener" @endif @if($current) aria-current="page" @endif>{{ $label }}@if($external)<span class="sr-only"> (opens in a new tab)</span><span class="hub-shell-external-hint" aria-hidden="true">&#8599;</span>@endif</a>
