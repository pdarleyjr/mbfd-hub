@extends('filament-chatify::page')

@ section('content')
@include('vendor.chatify.layouts.headLinks')
<div class="messenger">
    {{-- LeftSide --}}
    <div class="messenger-listView">
        {{-- Header and search --}}
        <div class="m-header">
            <nav>
                <a href="#"><i class="fas fa-inbox"></i> <span class="messenger-headTitle">MESSAGES</span> </a>
                {{-- header buttons --}}
                <nav class="m-header-right">
                    <a href="#"><i class="fas fa-cog settings-btn"></i></a>
                    <a href="#" class="listView-x"><i class="fas fa-times"></i></a>
                </nav>
            </nav>
            {{-- Search input --}}
            <input type="text" class="messenger-search" placeholder="Search" />
            {{-- Tabs --}}
            <div class="messenger-listView-tabs">
                <a href="#" class="active-tab" data-view="users">
                    <span class="fas fa-clock" title="Recent"></span>
                </a>
                <a href="#" data-view="search">
                    <span class="fas fa-search" title="Search"></span>
                </a>
            </div>
        </div>
        {{-- tabs and lists --}}
        <div class="m-body contacts-container">
            <div class="show messenger-tab users-tab app-scroll" data-view="users">
                {{-- Users list --}}
                <p class="messenger-title"><span>Favorites</span></p>
                <div class="favorites-section">
                    {!! $favorites !!}
                </div>
                <p class="messenger-title"><span>All Messages</span></p>
                <div class="listOfContacts" style="width: 100%;height: calc(100% - 200px);position: relative;"></div>
            </div>
            <div class="messenger-tab search-tab app-scroll" data-view="search">
                {{-- Search tab --}}
                <p class="messenger-title"><span>Search</span></p>
                <div class="search-records">
                    <p class="message-hint center-el"><span>Type to search..</span></p>
                </div>
            </div>
        </div>
    </div>

    {{-- RightSide --}}
    <div class="messenger-messagingView">
        {{-- header --}}
        <div class="m-header m-header-messaging">
            <nav class="chatify-d-flex chatify-justify-content-between chatify-align-items-center">
                {{-- header back button, avatar and user name --}}
                <div class="chatify-d-flex chatify-justify-content-between chatify-align-items-center">
                    <a href="#" class="show-listView"><i class="fas fa-arrow-left"></i></a>
                    <div class="avatar av-s header-avatar" style="margin: 0px 10px; margin-top: -5px; margin-bottom: -5px;">
                    </div>
                    <a href="#" class="user-name">{{ config('chatify.name') }}</a>
                </div>
                {{-- header buttons --}}
                <nav class="m-header-right">
                    <a href="#" class="add-to-favorite"><i class="fas fa-star"></i></a>
                    <a href="/"><i class="fas fa-home"></i></a>
                    <a href="#" class="show-infoSide"><i class="fas fa-info-circle"></i></a>
                </nav>
            </nav>
            {{-- Internet connection --}}
            <div class="internet-connection">
                <span class="ic-connected">Connected</span>
                <span class="ic-connecting">Connecting...</span>
                <span class="ic-noConnection">No internet access</span>
            </div>
        </div>

        {{-- Messaging area --}}
        <div class="m-body messages-container app-scroll">
            <div class="messages">
                <p class="message-hint center-el"><span>Please select a chat to start messaging</span></p>
            </div>
            {{-- Typing indicator --}}
            <div class="typing-indicator">
                <div class="typingWrapper">
                    <div class="typingCircle"></div>
                    <div class="typingCircle"></div>
                    <div class="typingCircle"></div>
                </div>
            </div>
            {{-- Send Message Form --}}
            @include('vendor.chatify.layouts.sendForm')
        </div>
    </div>

    {{-- InfoSide --}}
    <div class="messenger-infoView app-scroll">
        {{-- nav --}}
        <nav>
            <p>User Details</p>
            <a href="#"><i class="fas fa-times"></i></a>
        </nav>
        {!! view('vendor.chatify.layouts.info')->render() !!}
    </div>
</div>
@include('vendor.chatify.layouts.modals')
@include('vendor.chatify.layouts.footerLinks')
@endsection