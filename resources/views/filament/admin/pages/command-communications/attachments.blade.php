@php use Illuminate\Support\Number; @endphp
@if ($attachments->isNotEmpty())
    <ul class="mt-3 flex flex-wrap gap-2" aria-label="Attachments">
        @foreach ($attachments as $attachment)
            @php $url = route('command-communications.attachment', ['public_id' => $attachment->public_id], false); @endphp
            <li>
                @if ($attachment->isImage())
                    <a href="{{ $url }}?inline=1" target="_blank" rel="noopener noreferrer" data-hub-viewer="image" data-hub-viewer-title="{{ $attachment->original_filename }}" data-hub-viewer-download="{{ $url }}" class="block min-h-[44px] min-w-[44px] overflow-hidden rounded-lg border border-gray-200" aria-label="Open image {{ $attachment->original_filename }}">
                        <img src="{{ $url }}?inline=1" alt="{{ $attachment->original_filename }}" loading="lazy" class="h-24 w-24 object-cover">
                    </a>
                @else
                    <a href="{{ $url }}" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 hover:bg-gray-50" download>
                        <x-filament::icon icon="heroicon-o-document" class="h-4 w-4 shrink-0 text-gray-600" />
                        <span class="max-w-[16rem] truncate">{{ $attachment->original_filename }}</span>
                        <span class="text-xs text-gray-600">{{ Number::fileSize($attachment->byte_size) }}</span>
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
@endif
