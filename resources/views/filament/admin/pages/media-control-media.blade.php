<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Connection status --}}
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm" data-testid="mc-status">
            <div class="flex items-center gap-3">
                @if ($reachable)
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500" aria-hidden="true"></span>
                    <p class="text-sm text-gray-800">
                        Connected to Media Control
                        @if ($mcVersion)
                            <span class="text-gray-500">(version {{ $mcVersion }})</span>
                        @endif
                    </p>
                @elseif (! $configured)
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-gray-400" aria-hidden="true"></span>
                    <p class="text-sm text-gray-800">{{ $statusMessage }}</p>
                @else
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                    <p class="text-sm text-gray-800">{{ $statusMessage ?? 'Media Control is not reachable.' }}</p>
                @endif
            </div>
            <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-path" wire:click="refreshStatus">
                Check again
            </x-filament::button>
        </section>

        {{-- Upload --}}
        <section
            class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
            wire:ignore
            x-data="mcMediaUploader(@js($this->uploaderConfig()))"
        >
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900">Upload to Media Control</h2>
                <p class="text-xs text-gray-500">Images (JPEG, PNG, WebP, GIF up to 200 MB) and videos (MP4, WebM, MOV). Files go straight to Media Control.</p>
            </div>

            <label
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-6 py-10 text-center transition"
                :class="dragging ? 'border-primary-500 bg-primary-50' : 'border-gray-300 bg-gray-50 hover:border-gray-400'"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; addFiles($event.dataTransfer.files)"
            >
                <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="h-8 w-8 text-gray-400" />
                <span class="text-sm font-medium text-gray-800">Drop images or videos here, or choose files</span>
                <span class="text-xs text-gray-500">You can pick several at once.</span>
                <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime,.mov"
                       x-on:change="addFiles($event.target.files); $event.target.value = ''">
            </label>

            <template x-if="notice">
                <p class="mt-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800" x-text="notice"></p>
            </template>

            <ul class="mt-4 space-y-3" x-show="files.length > 0">
                <template x-for="entry in files" :key="entry.key">
                    <li class="rounded-lg border border-gray-200 p-4">
                        <div class="flex flex-wrap items-start gap-4">
                            <div class="flex h-16 w-28 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100">
                                <template x-if="entry.previewUrl">
                                    <img :src="entry.previewUrl" alt="" class="h-full w-full object-cover">
                                </template>
                                <template x-if="! entry.previewUrl">
                                    <x-filament::icon icon="heroicon-o-film" class="h-6 w-6 text-gray-400" />
                                </template>
                            </div>

                            <div class="min-w-0 flex-1 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="truncate text-xs text-gray-500">
                                        <span x-text="entry.name"></span> · <span x-text="formatBytes(entry.size)"></span>
                                        <template x-if="entry.width">
                                            <span> · <span x-text="entry.width + '×' + entry.height"></span></span>
                                        </template>
                                    </p>
                                    <span class="text-xs font-medium" :class="statusClass(entry)" x-text="statusText(entry)"></span>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" x-show="entry.status === 'ready' || entry.status === 'error'">
                                    <label class="block text-xs font-medium text-gray-700">Title
                                        <input type="text" maxlength="120" x-model="entry.title"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    </label>
                                    <label class="block text-xs font-medium text-gray-700">Display layout
                                        <select x-model="entry.span"
                                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                            <option value="">Let Media Control decide</option>
                                            <template x-for="(label, value) in config.layouts" :key="value">
                                                <option :value="value" x-text="label" :selected="String(entry.span) === String(value)"></option>
                                            </template>
                                        </select>
                                        <span class="mt-1 block font-normal text-gray-500" x-show="entry.spanHint" x-text="entry.spanHint"></span>
                                    </label>
                                    <label class="block text-xs font-medium text-gray-700">Category (optional)
                                        <input type="text" maxlength="60" list="mc-media-categories" x-model="entry.category"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    </label>
                                    <label class="block text-xs font-medium text-gray-700">Tags (comma separated)
                                        <input type="text" x-model="entry.tags"
                                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    </label>
                                </div>

                                <div x-show="entry.status === 'uploading' || entry.status === 'processing'">
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" :aria-valuenow="entry.progress" aria-valuemin="0" aria-valuemax="100">
                                        <div class="h-full rounded-full bg-primary-600 transition-all" :style="'width: ' + entry.progress + '%'"></div>
                                    </div>
                                </div>

                                <p class="text-sm text-red-700" x-show="entry.error" x-text="entry.error"></p>

                                <div class="flex flex-wrap gap-2">
                                    <x-filament::button size="xs" color="gray" x-show="entry.status === 'uploading' || entry.status === 'requesting'" x-on:click="cancel(entry)">Cancel</x-filament::button>
                                    <x-filament::button size="xs" color="gray" x-show="entry.status === 'error' || entry.status === 'canceled'" x-on:click="retry(entry)">Retry</x-filament::button>
                                    <x-filament::button size="xs" color="gray" x-show="! busy(entry)" x-on:click="remove(entry)">Remove</x-filament::button>
                                </div>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>

            <div class="mt-4 flex items-center gap-3" x-show="files.length > 0">
                <x-filament::button icon="heroicon-o-arrow-up-tray" x-on:click="uploadAll()" x-bind:disabled="running || ! hasPending()">
                    <span x-text="running ? 'Uploading…' : 'Upload'"></span>
                </x-filament::button>
                <p class="text-xs text-gray-500">Keep this page open until each file shows “Available in Media Control”.</p>
            </div>
        </section>

        <datalist id="mc-media-categories">
            @foreach ($this->categories as $category)
                <option value="{{ $category }}"></option>
            @endforeach
        </datalist>

        {{-- Library --}}
        <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
                <h2 class="text-base font-semibold text-gray-900">Media Control library</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="search" placeholder="Search titles" aria-label="Search media"
                           wire:model.live.debounce.500ms="search"
                           class="block w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <select wire:model.live="kind" aria-label="Filter by kind"
                            class="block rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">Images and videos</option>
                        <option value="image">Images</option>
                        <option value="video">Videos</option>
                    </select>
                    <x-filament::icon-button icon="heroicon-o-arrow-path" color="gray" label="Reload library" wire:click="refreshLibrary" />
                </div>
            </div>

            @if ($libraryError)
                <p class="mx-5 mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $libraryError }}</p>
            @endif

            @if (count($items) === 0)
                <p class="px-5 py-10 text-center text-sm text-gray-500">
                    {{ $reachable ? 'No images or videos match.' : 'The library appears here once Media Control is reachable.' }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm" data-testid="mc-library">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3">Media</th>
                                <th class="px-3 py-3">Display layout</th>
                                <th class="px-3 py-3">Category</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Uploaded</th>
                                <th class="px-3 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($items as $item)
                                <tr wire:key="mc-item-{{ $item['id'] }}">
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-12 w-20 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100">
                                                @if ($item['thumbnail_url'])
                                                    <img src="{{ $item['thumbnail_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover">
                                                @else
                                                    <x-filament::icon :icon="$item['kind'] === 'video' ? 'heroicon-o-film' : 'heroicon-o-photo'" class="h-5 w-5 text-gray-400" />
                                                @endif
                                            </div>
                                            <div class="min-w-0">
                                                <p class="truncate font-medium text-gray-900">{{ $item['title'] }}</p>
                                                <p class="text-xs text-gray-500">
                                                    {{ $item['kind'] === 'video' ? 'Video' : 'Image' }}
                                                    @if ($item['width'] && $item['height'])
                                                        · {{ $item['width'] }}×{{ $item['height'] }}
                                                    @endif
                                                    @if ($item['tags'])
                                                        · {{ implode(', ', $item['tags']) }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-gray-700">
                                        {{ $this->layoutLabel($item['display_span']) }}
                                        @if ($item['display_span'] && $item['display_span_source'] && $item['display_span_source'] !== 'explicit')
                                            <span class="block text-xs text-gray-500">detected from {{ $item['display_span_source'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-gray-700">{{ $item['category'] ?? '—' }}</td>
                                    <td class="px-3 py-3">
                                        @php($processing = $item['processing_status'] ?? 'ready')
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-50 text-green-700' => in_array($processing, ['ready', 'complete', 'completed', 'done'], true),
                                            'bg-red-50 text-red-700' => in_array($processing, ['failed', 'error'], true),
                                            'bg-amber-50 text-amber-700' => ! in_array($processing, ['ready', 'complete', 'completed', 'done', 'failed', 'error'], true),
                                        ])>{{ ucfirst($processing) }}</span>
                                        @if ($item['processing_error'])
                                            <span class="block text-xs text-red-700">{{ $item['processing_error'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-xs text-gray-600">
                                        {{ $item['uploaded_via_hub'] ? 'MBFD Hub' : 'Media Control' }}
                                        @if ($item['uploaded_by'])
                                            · {{ $item['uploaded_by'] }}
                                        @endif
                                        @if ($item['created_at'])
                                            <span class="block text-gray-500">{{ \App\Services\MediaControl\MediaControlContent::createdAt($item['created_at'])?->format('M j, Y') ?? 'Time unavailable' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex items-center justify-end gap-1">
                                            {{ ($this->editAction)(['id' => $item['id']]) }}
                                            {{ ($this->archiveAction)(['id' => $item['id']]) }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($nextCursor)
                    <div class="border-t border-gray-200 px-5 py-3 text-center">
                        <x-filament::button color="gray" size="sm" wire:click="loadMore">Show more</x-filament::button>
                    </div>
                @endif
            @endif
        </section>
    </div>

    @include('filament.admin.pages.partials.media-control-uploader-script')
</x-filament-panels::page>
