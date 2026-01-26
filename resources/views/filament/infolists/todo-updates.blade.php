<div class="space-y-4">
    @forelse($getRecord()->updates()->orderBy('created_at', 'desc')->get() as $update)
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white text-sm font-semibold">
                        {{ strtoupper(substr($update->username ?? 'U', 0, 1)) }}
                    </div>
                    <div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $update->username ?? 'Unknown' }}</span>
                        <span class="text-gray-500 dark:text-gray-400 text-sm ml-2">{{ $update->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400">{{ $update->created_at->format('M d, Y g:i A') }}</span>
                    @if(auth()->id() === $update->user_id || auth()->user()->role === 'admin')
                        <button 
                            type="button"
                            wire:click="deleteUpdate({{ $update->id }})"
                            wire:confirm="Are you sure you want to delete this update?"
                            class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50 dark:hover:bg-red-900/20"
                            title="Delete update"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    @endif
                </div>
            </div>
            <p class="mt-2 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $update->comment }}</p>
        </div>
    @empty
        <div class="text-center py-6 text-gray-500 dark:text-gray-400">
            <svg class="w-10 h-10 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
            <p class="text-sm">No updates yet</p>
            <p class="text-xs mt-1">Click "Add Update" above to add progress notes</p>
        </div>
    @endforelse
</div>
