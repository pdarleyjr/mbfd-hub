<!--
    Chatify Head Links
    CSS files and meta tags for Chatify messenger
-->

<!-- jQuery must be loaded first -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

<!-- NProgress loading bar -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.css" integrity="sha512-42kB2ZiQ7N6NbcVqzB8IZWnhxtMlBuqVLPj5eOd9xVkdDQnrF/DsRmEYfq8FMKp5jB1Eul6e1D3n2f1TRBVLGg==" crossorigin="anonymous" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.js" integrity="sha512-j6RjS+DcT0XxXHhIFIAf13tOY3n3D2xd5pLJ0TdOSdMLi6S9tF8VJ5Y8zMLSHwJ5Rh6FtCW0X7AcUDfFaH6Nng==" crossorigin="anonymous"></script>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" />

{{-- Chatify Default Styles --}}
<link rel="stylesheet" href="{{ asset('css/chatify/style.css') }}">

{{-- Chatify UI Fixes - Ensures composer is always visible --}}
<link rel="stylesheet" href="{{ asset('css/chatify-fixes.css') }}">

{{-- CRITICAL FIX: Force composer to bottom of viewport --}}
<link rel="stylesheet" href="{{ asset('css/chatify/chatify-composer-fix.css') }}">

{{-- Mobile viewport meta for proper mobile rendering --}}
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

{{-- iOS PWA meta tags --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

{{-- Theme color for mobile browsers --}}
<meta name="theme-color" content="#3b82f6" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1f2937" media="(prefers-color-scheme: dark)">

{{-- Prevent unwanted behaviors on mobile --}}
<style>
    /* Prevent pull-to-refresh on mobile for better chat experience */
    #messenger {
        overscroll-behavior-y: none;
        -webkit-overscroll-behavior-y: none;
    }
</style>
