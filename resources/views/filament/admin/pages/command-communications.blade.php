<x-filament-panels::page>
    <div class="mx-auto w-full max-w-4xl space-y-6" wire:poll.30s.visible="poll">
        <p class="text-sm text-gray-600">
            A private conversation between 300 Command and Hub administrators. Messages are a permanent record and cannot be edited or deleted.
        </p>

        <x-filament::section>
            <x-slot name="heading">New message</x-slot>
            <form wire:submit="submit" class="space-y-4">
                {{ $this->form }}
                <div class="flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="min-h-[44px] min-w-[44px]" wire:loading.attr="disabled" wire:target="submit">
                        Post message
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <div>
            <label for="command-comm-search" class="sr-only">Search the conversation</label>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input id="command-comm-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Search subjects and messages" autocomplete="off" class="min-h-[44px]" />
            </x-filament::input.wrapper>
        </div>

        @if ($searching)
            <section aria-labelledby="command-comm-results" class="space-y-3">
                <h2 id="command-comm-results" class="text-sm font-semibold text-gray-900">
                    {{ $results->count() }} {{ \Illuminate\Support\Str::plural('result', $results->count()) }} for &ldquo;{{ trim($search) }}&rdquo;
                </h2>
                @forelse ($results as $post)
                    @include('filament.admin.pages.command-communications.post', ['post' => $post])
                @empty
                    <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600">No messages match that search.</p>
                @endforelse
            </section>
        @else
            @if ($pinned->isNotEmpty())
                <section aria-labelledby="command-comm-pinned" class="space-y-3">
                    <h2 id="command-comm-pinned" class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                        <x-filament::icon icon="heroicon-m-bookmark" class="h-4 w-4 text-gray-500" />
                        Pinned
                    </h2>
                    @foreach ($pinned as $post)
                        @include('filament.admin.pages.command-communications.post', ['post' => $post, 'pinnedList' => true])
                    @endforeach
                </section>
            @endif

            <section aria-labelledby="command-comm-feed" class="space-y-3">
                <h2 id="command-comm-feed" class="text-sm font-semibold text-gray-900">Conversation</h2>
                @forelse ($posts as $post)
                    @include('filament.admin.pages.command-communications.post', ['post' => $post])
                @empty
                    <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600">No messages yet. Post the first one above.</p>
                @endforelse

                @if ($hasMore)
                    <div class="flex justify-center">
                        <x-filament::button color="gray" wire:click="loadMore" class="min-h-[44px]" wire:loading.attr="disabled" wire:target="loadMore">
                            Load older messages
                        </x-filament::button>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
