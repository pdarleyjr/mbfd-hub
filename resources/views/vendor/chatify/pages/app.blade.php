{{--
    Chatify Messenger Main Page
    Custom layout that ensures message composer is always visible
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    {{-- Include Chatify head links (CSS and meta tags) --}}
    @include('vendor.chatify.layouts.headLinks')
    
    {{-- Page title --}}
    <title>{{ config('chatify.name', 'Messenger') }} - {{ config('app.name') }}</title>
    
    {{-- Additional inline styles for immediate effect --}}
    <style>
        /* Critical CSS - applied immediately before external CSS loads */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        
        #messenger {
            height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .messenger-messagingView {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }
        
        .messenger-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .messenger-sendCard {
            flex-shrink: 0;
            position: sticky;
            bottom: 0;
            z-index: 100;
            background: #fff;
            border-top: 1px solid #e5e7eb;
        }
        
        /* CRITICAL: Ensure text input is always visible */
        .messenger-sendCard input,
        .messenger-sendCard textarea {
            color: #1f2937 !important;
            background-color: #ffffff !important;
            -webkit-text-fill-color: #1f2937 !important;
        }
    </style>
</head>
<body data-chatify-page class="chatify-page">
    {{-- Messenger Container --}}
    <div id="messenger" class="messenger">
        {{-- Main Messaging View --}}
        <div class="messenger-messagingView" data-view="{{ Route::currentRouteName() == 'chatify' ? '1' : '0' }}">
            {{-- Header Section --}}
            <div class="messenger-header">
                {{-- Header content will be populated by Chatify JS --}}
                @yield('header')
            </div>
            
            {{-- Messages Body (Scrollable Area) --}}
            <div class="messenger-body" data-view="{{ Route::currentRouteName() == 'chatify' ? '1' : '0' }}">
                @yield('content')
            </div>
            
            {{-- Message Composer / Send Card (Fixed at bottom) --}}
            <div class="messenger-sendCard">
                @yield('sendForm')
            </div>
        </div>
        
        {{-- Sidebar / List View (if applicable) --}}
        @yield('listView')
    </div>
    
    {{-- Include Footer Scripts --}}
    @include('vendor.chatify.layouts.footerLinks')
    
    {{-- Additional scripts section --}}
    @yield('scripts')
</body>
</html>
