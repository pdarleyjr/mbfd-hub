<div class="w-full">
    @if(in_array(strtolower($fileType ?? ''), ['pdf']))
        <iframe 
            src="{{ $url }}" 
            class="w-full border-0 rounded-lg"
            style="height: 75vh; min-height: 500px;"
            title="{{ $filename }}"
        ></iframe>
    @elseif(in_array(strtolower($fileType ?? ''), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']))
        <div class="flex justify-center p-4">
            <img src="{{ $url }}" alt="{{ $filename }}" class="max-w-full max-h-[75vh] rounded-lg shadow-sm" />
        </div>
    @else
        <div class="p-8 text-center">
            <div class="text-gray-400 mb-4">
                <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <p class="text-gray-500 mb-4">Preview not available for this file type ({{ $fileType }}).</p>
            <a href="{{ $url }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download File
            </a>
        </div>
    @endif
</div>