@php
    use App\Support\SafeLinkifier;
    use Illuminate\Support\Number;

    $pinnedList = $pinnedList ?? false;
    $isNew = fn ($item) => (int) $item->author_user_id !== (int) $me->getKey()
        && ($cutoff === null || $item->created_at->greaterThan(\Illuminate\Support\Carbon::parse($cutoff)));
    $administration = $post->author_context === 'administration';
@endphp

<article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm" id="{{ $pinnedList ? 'pinned-' : 'post-' }}{{ $post->public_id }}" aria-label="Message from {{ $post->author?->name ?? 'a former member' }}">
    <header class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="text-sm font-semibold text-gray-900">{{ $post->author?->name ?? 'Former member' }}</span>
        @if ($post->author?->employeeProfile?->rank)
            <span class="text-sm text-gray-600">{{ $post->author->employeeProfile->rank }}</span>
        @endif
        <x-filament::badge :color="$administration ? 'primary' : 'gray'" size="sm">{{ $administration ? 'Administration' : '300 Command' }}</x-filament::badge>
        @if ($isNew($post))
            <x-filament::badge color="info" size="sm">New</x-filament::badge>
        @endif
        @if ($post->isPinned())
            <x-filament::badge color="gray" size="sm" icon="heroicon-m-bookmark">Pinned</x-filament::badge>
        @endif
        <time class="ml-auto text-xs text-gray-600" datetime="{{ $post->created_at->toIso8601String() }}" title="{{ $post->created_at->format('M j, Y g:i A') }}">
            {{ $post->created_at->diffForHumans() }}
        </time>
    </header>

    @if ($post->subject)
        <h3 class="mt-2 text-base font-semibold text-gray-900">{{ $post->subject }}</h3>
    @endif

    @if ($post->body !== '')
        <div class="mt-2 break-words text-sm text-gray-900">{{ SafeLinkifier::render($post->body) }}</div>
    @endif

    @include('filament.admin.pages.command-communications.attachments', ['attachments' => $post->attachments])

    <footer class="mt-3 flex flex-wrap items-center gap-2">
        <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-uturn-left" class="min-h-[44px] min-w-[44px]" wire:click="startReply('{{ $post->public_id }}')">
            Reply
        </x-filament::button>
        <x-filament::button color="gray" size="sm" :icon="$post->isPinned() ? 'heroicon-o-x-mark' : 'heroicon-o-bookmark'" class="min-h-[44px] min-w-[44px]" wire:click="togglePin('{{ $post->public_id }}')">
            {{ $post->isPinned() ? 'Unpin' : 'Pin' }}
        </x-filament::button>
    </footer>

    @if ($post->replies->isNotEmpty())
        <div class="mt-3 space-y-2" role="list" aria-label="Replies">
            @foreach ($post->replies as $reply)
                @php $replyAdmin = $reply->author_context === 'administration'; @endphp
                <div role="listitem" class="rounded-lg bg-gray-50 p-3">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-sm font-semibold text-gray-900">{{ $reply->author?->name ?? 'Former member' }}</span>
                        @if ($reply->author?->employeeProfile?->rank)
                            <span class="text-sm text-gray-600">{{ $reply->author->employeeProfile->rank }}</span>
                        @endif
                        <x-filament::badge :color="$replyAdmin ? 'primary' : 'gray'" size="sm">{{ $replyAdmin ? 'Administration' : '300 Command' }}</x-filament::badge>
                        @if ($isNew($reply))
                            <x-filament::badge color="info" size="sm">New</x-filament::badge>
                        @endif
                        <time class="ml-auto text-xs text-gray-600" datetime="{{ $reply->created_at->toIso8601String() }}" title="{{ $reply->created_at->format('M j, Y g:i A') }}">
                            {{ $reply->created_at->diffForHumans() }}
                        </time>
                    </div>
                    @if ($reply->body !== '')
                        <div class="mt-1 break-words text-sm text-gray-900">{{ SafeLinkifier::render($reply->body) }}</div>
                    @endif
                    @include('filament.admin.pages.command-communications.attachments', ['attachments' => $reply->attachments])
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->replyingTo === $post->public_id && ! $pinnedList)
        <form wire:submit="sendReply" class="mt-3 space-y-2">
            <label for="reply-{{ $post->public_id }}" class="text-sm font-medium text-gray-900">Reply to {{ $post->author?->name ?? 'this message' }}</label>
            <x-filament::input.wrapper>
                <textarea id="reply-{{ $post->public_id }}" wire:model="replyBody" rows="3" maxlength="10000" class="block w-full border-none bg-transparent px-3 py-2 text-sm text-gray-900 focus:ring-0"></textarea>
            </x-filament::input.wrapper>
            @error('replyBody')
                <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
            @enderror
            <div class="flex gap-2">
                <x-filament::button type="submit" size="sm" class="min-h-[44px] min-w-[44px]" wire:loading.attr="disabled" wire:target="sendReply">Send reply</x-filament::button>
                <x-filament::button type="button" color="gray" size="sm" class="min-h-[44px] min-w-[44px]" wire:click="cancelReply">Cancel</x-filament::button>
            </div>
        </form>
    @endif
</article>
