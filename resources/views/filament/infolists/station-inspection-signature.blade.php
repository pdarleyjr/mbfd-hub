@php
    $signature = $getState();
    $validSignature = is_string($signature)
        && str_starts_with(strtolower($signature), 'data:image/')
        && \App\Support\Security\Base64Image::decode($signature) !== null;
@endphp

@if ($validSignature)
    <img src="{{ $signature }}" alt="Inspection signature" class="max-w-full rounded border border-gray-200 p-2" style="max-width: 400px" />
@else
    <p class="text-sm text-gray-500">{{ filled($signature) ? 'Signature unavailable' : 'No signature' }}</p>
@endif
