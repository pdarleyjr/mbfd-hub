@props(['title', 'eyebrow' => null, 'description' => null])
<div {{ $attributes->class(['hub-page-header']) }}>
    <div>
        @if($eyebrow)<p class="hub-page-header__eyebrow">{{ $eyebrow }}</p>@endif
        <h1 class="hub-h1">{{ $title }}</h1>
        @if($description)<p class="mt-2 text-sm text-hub-muted">{{ $description }}</p>@endif
    </div>
    @if($slot->isNotEmpty())<div class="hub-page-header__actions">{{ $slot }}</div>@endif
</div>
