{{-- Chatify Head Links - Chatify-specific assets only (Filament provides Alpine/Livewire) --}}
<meta name="id" content="{{ $id ?? 0 }}">
<meta name="messenger-color" content="{{ $messengerColor ?? '#2180f3' }}">
<meta name="messenger-theme" content="{{ $dark_mode ?? 'light' }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="url" content="{{ url('').'/'.config('chatify.routes.prefix') }}" data-user="{{ Auth::user()->id }}">

{{-- Chatify-specific scripts (jQuery, Font Awesome, autosize) --}}
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="{{ asset('js/chatify/font.awesome.min.js') }}"></script>
<script src="{{ asset('js/chatify/autosize.js') }}"></script>
<script src='https://unpkg.com/nprogress@0.2.0/nprogress.js'></script>

{{-- Chatify-specific styles --}}
<link rel='stylesheet' href='https://unpkg.com/nprogress@0.2.0/nprogress.css'/>
<link href="{{ asset('css/chatify/style.css') }}" rel="stylesheet" />
<link href="{{ asset('css/chatify/'.($dark_mode ?? 'light').'.mode.css') }}" rel="stylesheet" />

{{-- Chatify UI fixes --}}
@if(file_exists(public_path('css/chatify-fixes.css')))
<link href="{{ asset('css/chatify-fixes.css') }}" rel="stylesheet" />
@endif

{{-- Messenger primary color --}}
<style>
    :root {
        --primary-color: {{ $messengerColor ?? '#2180f3' }};
    }
</style>
