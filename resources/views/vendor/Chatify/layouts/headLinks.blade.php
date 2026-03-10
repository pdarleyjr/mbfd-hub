{{-- jQuery must be loaded first --}}
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

{{-- NProgress loading bar --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.css" integrity="sha512-42kB2ZiQ7N6NbcVqzB8IZWnhxtMlBuqVLPj5eOd9xVkdDQnrF/DsRmEYfq8FMKp5jB1Eul6e1D3n2f1TRBVLGg==" crossorigin="anonymous" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.js" integrity="sha512-j6RjS+DcT0XxXHhIFIAf13tOY3n3D2xd5pLJ0TdOSdMLi6S9tF8VJ5Y8zMLSHwJ5Rh6FtCW0X7AcUDfFaH6Nng==" crossorigin="anonymous"></script>

{{-- Font Awesome --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" />

{{-- Meta tags --}}
<meta name="url" content="{{ url('').'/'.config('chatify.routes.prefix') }}" data-user="{{ Auth::user()->id }}">
<meta name="messenger-theme" content="{{ Auth::user()->messenger_color ??config('chatify.colors.default') }}">
<meta name="messenger-color" content="{{ Auth::user()->messenger_color ?? config('chatify.colors.default') }}">
<meta name="csrf-token" content="{{ csrf_token() }}">

{{-- Chatify CSS files --}}
<link rel="stylesheet" href="{{ asset('css/chatify/style.css') }}">
<link rel="stylesheet" href="{{ asset('css/chatify-fixes.css') }}">
<link rel="stylesheet" href="{{ asset('css/chatify/chatify-composer-fix.css') }}">

{{-- CRITICAL CSS FIXES for mobile composer visibility --}}
<style>
    /* FIX: Force chat container to use full height without overflowing Filament layout */
    .messenger {
        height: calc(100vh - 64px) !important; /* Account for Filament header */
        max-height: calc(100vh - 64px) !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }
    
    /* Make message area scrollable */
    .m-body.messages-container {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        min-height: 0 !important; /* Critical for flex children to shrink */
    }
    
    /* Keep contacts list scrollable */
    .m-body.contacts-container {
        flex: 1 1 auto !important;
        overflow-y: auto !important;
        min-height: 0 !important;
    }
    
    /* Pin composer to bottom - always visible */
    .messenger-sendCard {
        position: sticky !important;
        bottom: 0 !important;
        flex-shrink: 0 !important;
        z-index: 999 !important;
        background: white !important;
        padding-bottom: env(safe-area-inset-bottom) !important;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
    }
    
    /* Fix mobile input text visibility */
    .messenger-sendCard textarea,
    .messenger-sendCard input[type="text"] {
        color: #1f2937 !important;
        background-color: #ffffff !important;
        -webkit-text-fill-color: #1f2937 !important;
    }
    
    .messenger-sendCard textarea::placeholder,
    .messenger-sendCard input::placeholder {
        color: #9ca3af !important;
        opacity: 1 !important;
    }
    
    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .messenger-sendCard {
            background: #1f2937 !important;
        }
        .messenger-sendCard textarea,
        .messenger-sendCard input[type="text"] {
            color: #f3f4f6 !important;
            background-color: #374151 !important;
            -webkit-text-fill-color: #f3f4f6 !important;
        }
    }
</style>
