<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-hub-ui="2">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Install MBFD Hub</title>
    @vite('resources/css/app.css')
    @vite('resources/js/pwa/install-ui.ts')
</head>
<body data-hub-ui="2">
@php
    $member = auth('web')->user();
    $notificationSettingsUrl = match (true) {
        $member?->canAccessPanel(\Filament\Facades\Filament::getPanel('employee')) === true => \App\Filament\Pages\NotificationSettings::getUrl(panel: 'employee'),
        $member?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) === true => \App\Filament\Pages\NotificationSettings::getUrl(panel: 'admin'),
        default => null,
    };
@endphp
@php
    // Order matters: it is the order of the "Using a different device?" selector.
    $guideTabs = [
        'ios-safari' => ['tab' => 'iPhone or iPad, Safari', 'title' => 'iPhone or iPad in Safari'],
        'ios-other' => ['tab' => 'iPhone or iPad, Chrome or Edge', 'title' => 'iPhone or iPad in Chrome, Edge or another browser'],
        'android-chrome' => ['tab' => 'Android, Chrome or Edge', 'title' => 'Android in Chrome or Edge'],
        'android-samsung' => ['tab' => 'Android, Samsung Internet', 'title' => 'Android in Samsung Internet'],
        'android-firefox' => ['tab' => 'Android, Firefox', 'title' => 'Android in Firefox'],
        'desktop-chromium' => ['tab' => 'Windows, Linux or ChromeOS', 'title' => 'Windows, Linux or ChromeOS in Chrome or Edge'],
        'mac-safari' => ['tab' => 'Mac, Safari', 'title' => 'Mac in Safari'],
        'mac-chromium' => ['tab' => 'Mac, Chrome or Edge', 'title' => 'Mac in Chrome or Edge'],
        'other' => ['tab' => 'Other browser', 'title' => 'Another browser'],
    ];
@endphp

{{-- Glyph sprite: the browser controls the steps refer to. Decorative only; each step names the control in words. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <symbol id="ig-share" viewBox="0 0 24 24"><path d="M12 3v12M8.5 6.5 12 3l3.5 3.5M7 10H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="ig-add-home" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 8.5v7M8.5 12h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
        <symbol id="ig-install" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 6.5v6M9.5 10.5l2.5 2.5 2.5-2.5M8 20h8M12 16v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="ig-kebab" viewBox="0 0 24 24"><circle cx="12" cy="5.5" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="18.5" r="1.6" fill="currentColor"/></symbol>
        <symbol id="ig-ellipsis" viewBox="0 0 24 24"><circle cx="5.5" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="18.5" cy="12" r="1.6" fill="currentColor"/></symbol>
        <symbol id="ig-menu" viewBox="0 0 24 24"><path d="M5 7h14M5 12h14M5 17h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
        <symbol id="ig-dock" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 20.5h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M9.5 10.5h5M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
        <symbol id="ig-app" viewBox="0 0 24 24"><rect x="5" y="3.5" width="14" height="17" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 17.5h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
    </defs>
</svg>

<main class="hub-install" data-install-page id="main-content">
    <section class="hub-install__app" aria-labelledby="install-title">
        <img class="hub-install__icon-img" src="/images/mbfd_app_icon_192.png" width="72" height="72" alt="">
        <div class="hub-install__app-text">
            <p class="hub-install__eyebrow">Miami Beach Fire Department</p>
            <h1 id="install-title">MBFD Hub</h1>
            <p class="hub-install__lede">Open inspections, forms, notifications and department tools straight from your device.</p>
        </div>
    </section>

    <p class="hub-install__status" role="status" aria-live="polite" data-install-status>Follow the steps below to add MBFD Hub to your device.</p>

    <button type="button" class="hub-btn hub-btn--primary hub-btn--field hub-install__primary" data-install-primary hidden>
        <svg class="hub-install__glyph-inline" aria-hidden="true" focusable="false"><use href="#ig-install"/></svg>
        Install MBFD Hub
    </button>

    {{-- Shown only after the app is installed or running as an installed app. --}}
    <section class="hub-install__success" data-install-success aria-labelledby="install-success-title" hidden>
        <h2 id="install-success-title" data-install-success-heading tabindex="-1">MBFD Hub is installed</h2>
        <p>Open it from the MBFD Hub icon on your device. It opens in its own window, without browser tabs.</p>
        <div class="hub-install__actions">
            <a class="hub-btn hub-btn--primary" href="{{ url('/') }}">Return to MBFD Hub</a>
            @if ($notificationSettingsUrl)<a class="hub-btn hub-btn--secondary" href="{{ $notificationSettingsUrl }}">Turn on notifications</a>@endif
        </div>
        @if ($notificationSettingsUrl)<p class="hub-install__hint">Notifications are optional. Your device only asks for permission when you turn them on.</p>@endif
        <p class="hub-install__hint"><button type="button" class="hub-install__text-button" data-install-show-guides>Install on another device</button></p>

        <div class="hub-install__after" data-install-after hidden>
            <h3>After installing</h3>
            <div data-after-tip="windows" hidden>
                <p>You can keep MBFD Hub within reach:</p>
                <ul>
                    <li>Pin it: right-click its icon in the taskbar while it is open, then choose Pin to taskbar. Or find it in the Start menu and choose Pin to Start.</li>
                    <li>Desktop shortcut: in the app window, open the ⋯ menu and look for a shortcut option, or use your browser’s apps page (edge://apps or chrome://apps).</li>
                </ul>
            </div>
            <div data-after-tip="macos" hidden>
                <p>To keep MBFD Hub in the Dock, right-click its Dock icon while it is open and choose Options, then Keep in Dock.</p>
            </div>
            <div data-after-tip="linux" hidden>
                <p>Chrome and Edge usually add MBFD Hub to your app launcher. Where it appears depends on your desktop environment, and you may be able to pin it from there.</p>
            </div>
        </div>
    </section>

    <div data-install-setup>
        <section class="hub-install__guides" aria-labelledby="install-steps-title">
            <h2 id="install-steps-title" class="hub-install__section-title">How to install</h2>

            <article class="hub-install__guide" id="guide-ios-safari" data-install-guide="ios-safari" aria-labelledby="guide-ios-safari-h">
                <h3 id="guide-ios-safari-h">{{ $guideTabs['ios-safari']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-share"/></svg></span><span>Tap <strong>Share</strong> in the Safari toolbar.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-add-home"/></svg></span><span>Scroll and tap <strong>Add to Home Screen</strong>.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-app"/></svg></span><span>If you see <strong>Open as Web App</strong>, keep it turned on.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">4</span><span>Tap <strong>Add</strong>, then open MBFD Hub from its new Home Screen icon and sign in.</span></li>
                </ol>
            </article>

            <article class="hub-install__guide" id="guide-ios-other" data-install-guide="ios-other" aria-labelledby="guide-ios-other-h">
                <h3 id="guide-ios-other-h">{{ $guideTabs['ios-other']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-share"/></svg></span><span>Tap <strong>Share</strong> in the address bar or browser menu.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-add-home"/></svg></span><span>Tap <strong>Add to Home Screen</strong>, keep <strong>Open as Web App</strong> turned on if shown, then tap <strong>Add</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Open MBFD Hub from its new Home Screen icon and sign in.</span></li>
                </ol>
                <p class="hub-install__note">Not seeing Add to Home Screen? Open this page in Safari and use the Safari steps instead.</p>
            </article>

            <article class="hub-install__guide" id="guide-android-chrome" data-install-guide="android-chrome" aria-labelledby="guide-android-chrome-h">
                <h3 id="guide-android-chrome-h">{{ $guideTabs['android-chrome']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-kebab"/></svg></span><span>Tap the <strong>⋮ menu</strong> (three dots).</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-install"/></svg></span><span>Tap <strong>Install app</strong>. Depending on the version it may say <strong>Add to Home screen</strong> or <strong>Install and create shortcut</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Confirm with <strong>Install</strong>, then open MBFD Hub from your Home screen or app list.</span></li>
                </ol>
                <p class="hub-install__note">In Edge, open the menu at the bottom of the screen and tap <strong>Add to phone</strong>.</p>
            </article>

            <article class="hub-install__guide" id="guide-android-samsung" data-install-guide="android-samsung" aria-labelledby="guide-android-samsung-h">
                <h3 id="guide-android-samsung-h">{{ $guideTabs['android-samsung']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-menu"/></svg></span><span>Tap the <strong>menu</strong> (three lines).</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-add-home"/></svg></span><span>Tap <strong>Add page to</strong>, then <strong>Home screen</strong>. If you see <strong>Install app</strong>, choose that.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Confirm with <strong>Add</strong>, then open MBFD Hub from your Home screen.</span></li>
                </ol>
            </article>

            <article class="hub-install__guide" id="guide-android-firefox" data-install-guide="android-firefox" aria-labelledby="guide-android-firefox-h">
                <h3 id="guide-android-firefox-h">{{ $guideTabs['android-firefox']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-kebab"/></svg></span><span>Tap the <strong>⋮ menu</strong> (three dots).</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-add-home"/></svg></span><span>Tap <strong>Install</strong> or <strong>Add to Home screen</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Confirm with <strong>Add</strong>, then open MBFD Hub from your Home screen.</span></li>
                </ol>
            </article>

            <article class="hub-install__guide" id="guide-desktop-chromium" data-install-guide="desktop-chromium" aria-labelledby="guide-desktop-chromium-h">
                <h3 id="guide-desktop-chromium-h">{{ $guideTabs['desktop-chromium']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-install"/></svg></span><span>Click the <strong>Install</strong> icon at the right end of the address bar.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-kebab"/></svg></span><span>No icon? Open the <strong>⋮ menu</strong> in Chrome, then <strong>Cast, save, and share</strong> and <strong>Install page as app</strong>. In Edge, open <strong>⋯</strong>, then <strong>Apps</strong> and <strong>Install this site as an app</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Choose <strong>Install</strong>. MBFD Hub opens in its own window.</span></li>
                </ol>
            </article>

            <article class="hub-install__guide" id="guide-mac-safari" data-install-guide="mac-safari" aria-labelledby="guide-mac-safari-h">
                <h3 id="guide-mac-safari-h">{{ $guideTabs['mac-safari']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-dock"/></svg></span><span>In the menu bar, choose <strong>File</strong>, then <strong>Add to Dock</strong>.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-share"/></svg></span><span>You can also click <strong>Share</strong> in the toolbar and choose <strong>Add to Dock</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Click <strong>Add</strong>, then open MBFD Hub from the Dock.</span></li>
                </ol>
                <p class="hub-install__note">Add to Dock needs macOS Sonoma or later. On older versions, use Chrome or Edge.</p>
            </article>

            <article class="hub-install__guide" id="guide-mac-chromium" data-install-guide="mac-chromium" aria-labelledby="guide-mac-chromium-h">
                <h3 id="guide-mac-chromium-h">{{ $guideTabs['mac-chromium']['title'] }}</h3>
                <ol class="hub-install__steps">
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-install"/></svg></span><span>Click the <strong>Install</strong> icon at the right end of the address bar.</span></li>
                    <li><span class="hub-install__glyph"><svg aria-hidden="true" focusable="false"><use href="#ig-kebab"/></svg></span><span>No icon? Open the <strong>⋮ menu</strong> in Chrome, then <strong>Cast, save, and share</strong> and <strong>Install page as app</strong>. In Edge, open <strong>⋯</strong>, then <strong>Apps</strong> and <strong>Install this site as an app</strong>.</span></li>
                    <li><span class="hub-install__glyph hub-install__glyph--num" aria-hidden="true">3</span><span>Choose <strong>Install</strong>. MBFD Hub opens in its own window.</span></li>
                </ol>
            </article>

            <article class="hub-install__guide" id="guide-other" data-install-guide="other" aria-labelledby="guide-other-h">
                <h3 id="guide-other-h">{{ $guideTabs['other']['title'] }}</h3>
                <p>This browser may not offer installing apps. For the smoothest setup, open this page in Safari on iPhone, iPad or Mac, or in Chrome or Edge on other devices, then follow the steps for that browser.</p>
                <p>Opened this from an email or another app? Copy the link and paste it into Safari or Chrome.</p>
                <p><button type="button" class="hub-install__text-button" data-install-copy-link hidden>Copy link</button></p>
                <p class="hub-install__note">You can keep using MBFD Hub in your browser in the meantime.</p>
            </article>
        </section>

        {{-- Only works with JavaScript, so it is hidden until the page is enhanced. --}}
        <details class="hub-install__switcher" data-install-switcher>
            <summary>Using a different device?</summary>
            <div class="hub-install__tabs" role="tablist" aria-label="Choose your device and browser">
                @foreach ($guideTabs as $key => $meta)
                    <button type="button" role="tab" class="hub-install__tab" id="tab-{{ $key }}" data-guide-tab="{{ $key }}"
                            aria-controls="guide-{{ $key }}" aria-selected="false" tabindex="-1">{{ $meta['tab'] }}</button>
                @endforeach
            </div>
        </details>

        <p class="hub-install__return"><a href="{{ url('/') }}">Return to MBFD Hub</a></p>
    </div>
</main>
</body>
</html>
