<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#B91C1C">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <title>MBFD Support Hub | Enterprise Command Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" as="style">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { font-family: 'Source Sans 3', system-ui, sans-serif; }
        .chat-messages { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .chat-messages::-webkit-scrollbar { width: 6px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 3px; }
        .typing-dot { animation: typing 1.4s infinite; }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing { 0%, 60%, 100% { opacity: 0.3; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-4px); } }
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        @keyframes fadeSlideUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes incidentIn { from { opacity: 0; transform: translateX(-6px); } to { opacity: 1; transform: translateX(0); } }
        .loading-bar { position: relative; overflow: hidden; }
        .loading-bar::after { content: ''; position: absolute; top: 0; left: 0; width: 50%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent); animation: shimmer 1.5s infinite; }
        .msg-ai p { margin-bottom: 0.5rem; }
        .msg-ai ul, .msg-ai ol { margin-left: 1.25rem; margin-bottom: 0.5rem; }
        .msg-ai li { margin-bottom: 0.25rem; }
        .msg-ai strong { font-weight: 600; }
        .msg-ai h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem; }
        .stagger-item { opacity: 0; animation: fadeSlideUp 0.3s cubic-bezier(0.25, 0.1, 0.25, 1) forwards; }
        .stagger-item:nth-child(1) { animation-delay: 0ms; }
        .stagger-item:nth-child(2) { animation-delay: 80ms; }
        .stagger-item:nth-child(3) { animation-delay: 160ms; }
        /* PulsePoint call feed */
        .incident-row { animation: incidentIn 0.25s cubic-bezier(0,0,0.2,1) forwards; }
        .incident-row:nth-child(1) { animation-delay: 0ms; }
        .incident-row:nth-child(2) { animation-delay: 50ms; }
        .incident-row:nth-child(3) { animation-delay: 100ms; }
        .incident-row:nth-child(4) { animation-delay: 150ms; }
        .incident-row:nth-child(5) { animation-delay: 200ms; }
        .shimmer-line { position: relative; overflow: hidden; background: #e7e5e3; border-radius: 4px; }
        .shimmer-line::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.6) 50%, transparent 100%); animation: shimmer 1.4s infinite; }
        .feed-scroll { scrollbar-width: thin; scrollbar-color: #e7e5e3 transparent; }
        .feed-scroll::-webkit-scrollbar { width: 4px; }
        .feed-scroll::-webkit-scrollbar-thumb { background: #e7e5e3; border-radius: 2px; }
        @media (prefers-reduced-motion: reduce) {
            .typing-dot, .loading-bar::after, .shimmer-line::after { animation: none; }
            .stagger-item, .incident-row { opacity: 1; animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="antialiased bg-neutral-50 text-neutral-800 min-h-screen">

    <!-- Compact Header Shell -->
    <header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md h-16 flex items-center justify-between px-4 lg:px-6" style="padding-top: max(0px, env(safe-area-inset-top, 0px));">
        <!-- Left: Logo + Title -->
        <div class="flex items-center gap-3">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" class="h-10 w-10 object-contain" width="40" height="40">
            <div class="hidden sm:block">
                <h1 class="text-white font-semibold text-base leading-tight font-heading">MBFD Support Hub</h1>
                <p class="text-slate-400 text-xs">Enterprise Command Portal</p>
            </div>
        </div>

        <!-- Right: Utility Actions -->
        <div class="flex items-center gap-2">
            <a href="{{ url('/admin/login') }}" class="min-h-[44px] px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                <span class="hidden sm:inline">Admin Login</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

        <!-- ============================================================ -->
        <!-- HERO BANNER: Live Call Feed (left) + AI Assistant (right)    -->
        <!-- ============================================================ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8 items-start mb-10">
            <!-- Left: PulsePoint Live Call Feed -->
            <div
                x-data="pulsePointFeed()"
                x-init="init()"
                class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden stagger-item"
                aria-label="MBFD Live Incident Feed"
                aria-live="polite"
                aria-atomic="false"
            >
                <!-- Card Header -->
                <div class="bg-[#1e293b] px-5 py-3.5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <!-- Shield icon -->
                        <div class="w-8 h-8 bg-red-600 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-white font-semibold text-sm leading-tight font-heading">MBFD Live Incidents</h2>
                            <p class="text-slate-400 text-xs">Miami Beach Fire — Agency X1012</p>
                        </div>
                    </div>
                    <!-- Live badge + last-updated -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span x-show="!error" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-red-900/40 text-red-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                            Live
                        </span>
                        <span x-show="error" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-amber-900/30 text-amber-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                            Offline
                        </span>
                    </div>
                </div>

                <!-- Active Count Bar -->
                <div class="px-5 py-2.5 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <div class="font-heading font-bold text-xl text-red-600 leading-none" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : activeCount"></div>
                            <div class="text-xs text-neutral-500 mt-0.5">Active</div>
                        </div>
                        <div class="w-px h-8 bg-neutral-200"></div>
                        <div class="text-center">
                            <div class="font-heading font-bold text-xl text-neutral-400 leading-none" style="font-variant-numeric: tabular-nums;" x-text="loading ? '—' : recentCount"></div>
                            <div class="text-xs text-neutral-500 mt-0.5">Recent</div>
                        </div>
                    </div>
                    <span class="text-xs text-neutral-400" x-text="lastUpdated" style="font-variant-numeric: tabular-nums;"></span>
                </div>

                <!-- Incident List -->
                <div class="feed-scroll overflow-y-auto" style="max-height: 320px; min-height: 160px;">

                    <!-- Shimmer loading state -->
                    <template x-if="loading">
                        <div class="px-5 py-3 space-y-3">
                            <template x-for="n in [1,2,3]" :key="n">
                                <div class="flex items-start gap-3">
                                    <div class="shimmer-line h-3 w-10 mt-1 flex-shrink-0"></div>
                                    <div class="flex-1 space-y-1.5">
                                        <div class="shimmer-line h-3 w-3/4"></div>
                                        <div class="shimmer-line h-2.5 w-1/2"></div>
                                    </div>
                                    <div class="shimmer-line h-4 w-12 rounded-full flex-shrink-0"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Error state -->
                    <template x-if="!loading && error">
                        <div class="flex flex-col items-center justify-center py-10 px-5 text-center">
                            <svg class="w-8 h-8 text-neutral-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <p class="text-sm font-medium text-neutral-500">Monitoring Unavailable</p>
                            <p class="text-xs text-neutral-400 mt-1">Check back shortly</p>
                        </div>
                    </template>

                    <!-- Active incidents -->
                    <template x-if="!loading && !error && activeIncidents.length > 0">
                        <div>
                            <div class="px-5 pt-2.5 pb-1">
                                <span class="text-xs font-semibold text-red-600 uppercase tracking-wider">Active Calls</span>
                            </div>
                            <template x-for="(inc, idx) in activeIncidents.slice(0,8)" :key="inc.id">
                                <div class="incident-row px-5 py-2.5 border-b border-neutral-100 last:border-0 hover:bg-neutral-50 transition-colors duration-150">
                                    <div class="flex items-start gap-3">
                                        <!-- Time -->
                                        <span class="text-xs text-neutral-400 w-11 flex-shrink-0 mt-0.5 leading-tight" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <!-- Details -->
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-neutral-800 leading-tight truncate" x-text="inc.callType"></p>
                                            <p class="text-xs text-neutral-500 mt-0.5 leading-snug truncate" x-text="inc.address"></p>
                                            <!-- Units -->
                                            <div x-show="inc.units && inc.units.length > 0" class="flex flex-wrap gap-1 mt-1.5">
                                                <template x-for="unit in inc.units.slice(0,4)" :key="unit.id">
                                                    <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 font-medium leading-none" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="unit.id"></span>
                                                </template>
                                                <span x-show="inc.units.length > 4" class="text-xs text-neutral-400" x-text="'+' + (inc.units.length - 4) + ' more'"></span>
                                            </div>
                                        </div>
                                        <!-- Status badge -->
                                        <span class="flex-shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full bg-red-50 text-red-700 leading-snug">Active</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Recent incidents (shown when no actives, or as secondary section) -->
                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length > 0">
                        <div>
                            <div class="px-5 pt-2.5 pb-1">
                                <span class="text-xs font-semibold text-neutral-400 uppercase tracking-wider">Recent Calls</span>
                            </div>
                            <template x-for="(inc, idx) in recentIncidents.slice(0,5)" :key="inc.id">
                                <div class="incident-row px-5 py-2.5 border-b border-neutral-100 last:border-0 hover:bg-neutral-50 transition-colors duration-150">
                                    <div class="flex items-start gap-3">
                                        <span class="text-xs text-neutral-400 w-11 flex-shrink-0 mt-0.5 leading-tight" style="font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace;" x-text="formatTime(inc.receivedAt)"></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-neutral-500 leading-tight truncate" x-text="inc.callType"></p>
                                            <p class="text-xs text-neutral-400 mt-0.5 leading-snug truncate" x-text="inc.address"></p>
                                        </div>
                                        <span class="flex-shrink-0 text-xs font-medium px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-500 leading-snug">Cleared</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Empty state — no incidents at all -->
                    <template x-if="!loading && !error && activeIncidents.length === 0 && recentIncidents.length === 0">
                        <div class="flex flex-col items-center justify-center py-10 px-5 text-center">
                            <svg class="w-8 h-8 text-neutral-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-sm font-medium text-neutral-400">No Active Incidents</p>
                            <p class="text-xs text-neutral-300 mt-1">All units available</p>
                        </div>
                    </template>
                </div>

                <!-- Footer: refresh hint -->
                <div class="px-5 py-2 border-t border-neutral-100 bg-neutral-50 flex items-center justify-between">
                    <span class="text-xs text-neutral-400">Auto-refreshes every 30 s</span>
                    <a href="https://web.pulsepoint.org/?agency=X1012" target="_blank" rel="noopener noreferrer" class="text-xs text-neutral-400 hover:text-red-600 transition-colors duration-150 flex items-center gap-1">
                        PulsePoint
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                </div>
            </div>

            <!-- Right: AI Support Assistant Panel -->
            <div>
                @if(env('FEATURE_AI_CHAT', true))
                <section x-data="aiChat()">
                    <div class="bg-white rounded-xl shadow-card border border-neutral-200 overflow-hidden">
                        <!-- Chat Header -->
                        <div class="bg-slate-800 px-5 py-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-red-600 rounded-lg flex items-center justify-center shadow-sm">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold text-sm">MBFD Support Assistant</h3>
                                    <p class="text-slate-400 text-xs">AI-powered SOG & procedures guidance</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="clearConversation()" title="Clear conversation" class="text-slate-400 hover:text-slate-200 transition-colors p-1 rounded" aria-label="Clear conversation">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Messages Area -->
                        <div class="chat-messages h-72 lg:h-80 overflow-y-auto p-4 space-y-3 bg-neutral-50/50" x-ref="chatMessages">
                            <template x-for="(msg, idx) in messages" :key="idx">
                                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                    <div :class="msg.role === 'user'
                                        ? 'bg-red-600 text-white rounded-2xl rounded-br-md px-4 py-2.5 max-w-xs text-sm shadow-sm'
                                        : 'msg-ai bg-white border border-neutral-200 text-neutral-800 rounded-2xl rounded-bl-md px-4 py-2.5 max-w-xs text-sm shadow-sm'">
                                        <span x-html="msg.role === 'user' ? msg.content : renderMarkdown(msg.content)"></span>
                                        <span x-show="msg.streaming" class="inline-block w-1.5 h-4 bg-red-500 ml-0.5 animate-pulse rounded-sm align-text-bottom"></span>
                                    </div>
                                </div>
                            </template>
                            <!-- Typing Indicator -->
                            <div x-show="loading && !messages.some(m => m.streaming)" class="flex justify-start">
                                <div class="bg-white border border-neutral-200 rounded-2xl rounded-bl-md px-4 py-3 shadow-sm">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                    </div>
                                    <p class="text-xs text-neutral-500 mt-1">Searching documents...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Loading Progress Bar -->
                        <div x-show="loading" class="px-4 py-2 bg-neutral-50 border-t border-neutral-200">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-neutral-200 rounded-full loading-bar">
                                    <div class="h-full bg-red-500 rounded-full" style="width: 100%;"></div>
                                </div>
                                <span class="text-xs text-neutral-500 whitespace-nowrap">Analyzing...</span>
                            </div>
                        </div>

                        <!-- Sources -->
                        <div x-show="lastSources.length > 0" class="px-4 py-2 bg-neutral-100/80 border-t border-neutral-200">
                            <p class="text-xs text-neutral-500">
                                <span class="font-medium">Sources:</span>
                                <template x-for="src in lastSources" :key="src">
                                    <span class="inline-block bg-white text-neutral-600 rounded px-1.5 py-0.5 ml-1 text-xs border border-neutral-200" x-text="src"></span>
                                </template>
                            </p>
                        </div>

                        <!-- Input Area -->
                        <div class="p-4 border-t border-neutral-200 bg-white">
                            <form @submit.prevent="sendMessage()" class="flex gap-2">
                                <input
                                    x-model="userInput"
                                    type="text"
                                    placeholder="Ask about SOGs, manuals, procedures..."
                                    aria-label="Type your message to the AI assistant"
                                    class="flex-1 min-h-[44px] bg-neutral-50 border border-neutral-200 rounded-xl px-4 py-2.5 text-sm text-neutral-800 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 transition-all"
                                    :disabled="loading"
                                    x-ref="chatInput"
                                >
                                <button
                                    type="submit"
                                    :disabled="loading || !userInput.trim()"
                                    class="min-h-[44px] px-4 bg-red-600 text-white rounded-xl font-medium text-sm hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5 shadow-sm"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                                    <span class="hidden sm:inline">Send</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </section>

                <script>
                function aiChat() {
                    return {
                        expanded: true,
                        messages: [{
                            role: 'assistant',
                            content: 'Welcome! I\'m the MBFD Support Assistant. Ask me anything about driver manuals, SOGs, department procedures, or station operations.\n\n*I remember our conversation — feel free to ask follow-up questions.*'
                        }],
                        userInput: '',
                        loading: false,
                        lastSources: [],
                        streamBuffer: '',
                        workerUrl: '/api/public/support-chat',

                        get conversationHistory() {
                            return this.messages.slice(-10).map(m => ({
                                role: m.role,
                                content: m.plainContent || m.content
                            }));
                        },

                        clearConversation() {
                            this.messages = [{
                                role: 'assistant',
                                content: 'Conversation cleared. How can I help you?',
                                plainContent: 'Conversation cleared. How can I help you?'
                            }];
                            this.lastSources = [];
                        },

                        askQuestion(q) {
                            this.userInput = q;
                            this.sendMessage();
                        },

                        renderMarkdown(text) {
                            if (!text) return '';
                            const escaped = text
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                            return escaped
                                .replace(/### (.*?)(\n|$)/g, '<h3>$1</h3>')
                                .replace(/## (.*?)(\n|$)/g, '<h3>$1</h3>')
                                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                                .replace(/`(.*?)`/g, '<code class="bg-neutral-100 px-1 rounded text-xs font-mono">$1</code>')
                                .replace(/^\* (.+)$/gm, '<li>$1</li>')
                                .replace(/^- (.+)$/gm, '<li>$1</li>')
                                .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
                                .replace(/(<li>.*?<\/li>(\n)?)+/gs, match => '<ul class="list-disc ml-4 mb-2">' + match + '</ul>')
                                .replace(/<\/ul>\s*<ul[^>]*>/g, '')
                                .replace(/\n\n/g, '</p><p class="mb-2">')
                                .replace(/\n/g, '<br>')
                                .replace(/^/, '<p class="mb-2">').replace(/$/, '</p>')
                                .replace(/<p class="mb-2"><\/p>/g, '');
                        },

                        async sendMessage() {
                            const msg = this.userInput.trim();
                            if (!msg || this.loading) return;

                            this.messages.push({ role: 'user', content: msg, plainContent: msg });
                            this.userInput = '';
                            this.loading = true;
                            this.lastSources = [];
                            await this.$nextTick();
                            this.scrollToBottom();

                            const streamIndex = this.messages.length;
                            this.messages.push({ role: 'assistant', content: '', plainContent: '', streaming: true });

                            try {
                                const resp = await fetch(this.workerUrl, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        message: msg,
                                        history: this.conversationHistory.slice(0, -2),
                                        stream: true
                                    })
                                });

                                if (!resp.ok) {
                                    const err = await resp.json().catch(() => ({}));
                                    throw new Error(err.error || `Request failed (${resp.status})`);
                                }

                                const sourcesHeader = resp.headers.get('X-Sources');
                                if (sourcesHeader) {
                                    try { this.lastSources = JSON.parse(sourcesHeader); } catch(e) {}
                                }

                                const reader = resp.body.getReader();
                                const decoder = new TextDecoder();
                                let fullText = '';

                                while (true) {
                                    const { done, value } = await reader.read();
                                    if (done) break;

                                    const chunk = decoder.decode(value, { stream: true });
                                    const lines = chunk.split('\n');

                                    for (const line of lines) {
                                        if (line.startsWith('data: ')) {
                                            const data = line.slice(6).trim();
                                            if (data === '[DONE]') continue;
                                            try {
                                                const parsed = JSON.parse(data);
                                                const token = parsed.response || parsed.token || '';
                                                if (token) {
                                                    fullText += token;
                                                    this.messages[streamIndex].content = fullText;
                                                    this.messages[streamIndex].plainContent = fullText;
                                                    this.$nextTick(() => this.scrollToBottom());
                                                }
                                                if (parsed.sources) {
                                                    this.lastSources = parsed.sources;
                                                }
                                            } catch (e) {}
                                        }
                                    }
                                }

                                this.messages[streamIndex].streaming = false;

                                if (!fullText) {
                                    await this.sendMessageNonStreaming(msg, streamIndex);
                                }

                            } catch (e) {
                                try {
                                    await this.sendMessageNonStreaming(msg, streamIndex);
                                } catch (e2) {
                                    this.messages[streamIndex].content = 'Sorry, I encountered an error. Please try again.';
                                    this.messages[streamIndex].plainContent = '';
                                    this.messages[streamIndex].streaming = false;
                                }
                            } finally {
                                this.loading = false;
                                this.scrollToBottom();
                            }
                        },

                        async sendMessageNonStreaming(msg, streamIndex) {
                            const resp = await fetch(this.workerUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    message: msg,
                                    history: this.conversationHistory.slice(0, -2),
                                    stream: false
                                })
                            });

                            if (!resp.ok) {
                                const err = await resp.json().catch(() => ({}));
                                throw new Error(err.error || 'Request failed');
                            }

                            const data = await resp.json();
                            this.messages[streamIndex].content = data.response || '';
                            this.messages[streamIndex].plainContent = data.response || '';
                            this.messages[streamIndex].streaming = false;
                            this.lastSources = data.sources || [];
                        },

                        scrollToBottom() {
                            this.$nextTick(() => {
                                const el = this.$refs.chatMessages;
                                if (el) el.scrollTop = el.scrollHeight;
                            });
                        }
                    };
                }
                </script>
                @endif
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- Quick Launch Cards                                           -->
        <!-- ============================================================ -->
        <div class="space-y-4">
                <h2 class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Quick Launch
                </h2>

                <!-- Stations / Vehicles — purple accent -->
                <a href="{{ url('/daily/stations') }}" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-purple-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-purple-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2m-6 9l2 2 4-4"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-purple-700 font-heading text-base">Stations / Vehicles</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">Apparatus checkout, vehicle inspections, station inventory, and station requests</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-purple-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>

                <!-- Employee Portal — emerald accent -->
                <a href="{{ url('/employee') }}" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-emerald-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-emerald-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-emerald-700 font-heading text-base">Employee Portal</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">View assigned gear and uniforms, and submit equipment requests</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-emerald-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>

                {{-- Hidden per admin request 2026-04-04 — re-enable when ready --}}
                @if(false)
                <!-- Apparatus Equipment Planner — teal accent -->
                <a href="{{ url('/apparatus-layout') }}" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-teal-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-teal-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-teal-50 flex items-center justify-center text-teal-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-teal-700 font-heading text-base">Apparatus Equipment Planner</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">Visual compartment layout tool with drag-and-drop equipment placement</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-teal-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>
                @endif
            </div>

        <!-- Additional Links — secondary navigation, scalable for future links -->
        <div class="mt-8">
            <h3 class="text-sm font-semibold text-neutral-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                More Tools
            </h3>
            <div class="flex flex-wrap gap-3">
                <!-- Operational Forms -->
                <a href="{{ url('/employee/forms') }}" aria-label="Open operational forms" class="group inline-flex items-center gap-2.5 bg-white rounded-lg border border-neutral-200 px-4 py-3 shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-md bg-blue-50 flex items-center justify-center text-blue-700 flex-shrink-0 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2m-6 0a3 3 0 016 0m-6 0a3 3 0 006 0M9 12h6m-6 4h4"></path>
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-neutral-700 group-hover:text-blue-700 transition-colors">Forms</span>
                        <p class="text-xs text-neutral-400">ICS 214 &amp; F-ROC reports</p>
                    </div>
                    <svg class="w-4 h-4 text-neutral-300 group-hover:text-blue-500 transition-colors ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>

                <!-- Workgroup Dashboard -->
                <a href="{{ url('/workgroups/login') }}" class="group inline-flex items-center gap-2.5 bg-white rounded-lg border border-neutral-200 px-4 py-3 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-md bg-indigo-50 flex items-center justify-center text-indigo-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-neutral-700 group-hover:text-indigo-700 transition-colors">Workgroup Dashboard</span>
                        <p class="text-xs text-neutral-400">Evaluations &amp; reviews</p>
                    </div>
                    <svg class="w-4 h-4 text-neutral-300 group-hover:text-indigo-400 transition-colors ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>

                <!-- Pump Panel Simulator -->
                <a href="https://pdarleyjr.github.io/puc-sim-manual-ui/" target="_blank" rel="noopener noreferrer" aria-label="Pump Panel training simulator (opens in new tab)" class="group inline-flex items-center gap-2.5 bg-white rounded-lg border border-neutral-200 px-4 py-3 shadow-sm hover:shadow-md hover:border-amber-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-md bg-amber-50 flex items-center justify-center text-amber-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-neutral-700 group-hover:text-amber-700 transition-colors">Pump Panel</span>
                        <p class="text-xs text-neutral-400">Training simulator</p>
                    </div>
                    <svg class="w-4 h-4 text-neutral-300 group-hover:text-amber-400 transition-colors ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>

                <!-- MBFD Media -->
                <a href="https://videos.mbfdhub.com" target="_blank" rel="noopener noreferrer" aria-label="Open MBFD Media video library (opens in new tab)" class="group inline-flex items-center gap-2.5 bg-white rounded-lg border border-neutral-200 px-4 py-3 shadow-sm hover:shadow-md hover:border-red-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-md bg-red-50 flex items-center justify-center text-red-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div class="max-w-xs">
                        <span class="text-sm font-medium text-neutral-700 group-hover:text-red-700 transition-colors">MBFD Media</span>
                        <p class="text-xs text-neutral-400">Watch department videos, support services content, training media, and live event broadcasts.</p>
                        <span class="mt-1 inline-flex text-xs font-semibold text-red-600">Open MBFD Media</span>
                    </div>
                    <svg class="w-4 h-4 text-neutral-300 group-hover:text-red-400 transition-colors ml-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>
            </div>
        </div>
    </main>
    
    <!-- Minimal Footer -->
    <footer class="border-t border-neutral-200 bg-white/60 backdrop-blur-sm mt-8" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-neutral-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <div class="flex items-center gap-3 text-xs text-neutral-400">
                <a href="{{ url('/security-standards') }}" class="hover:text-neutral-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50 rounded-sm transition-colors">Security &amp; Standards</a>
                <span aria-hidden="true">&bull;</span>
                <span>Secured System</span>
                <span aria-hidden="true">&bull;</span>
                <span>Support Services Division</span>
            </div>
        </div>
    </footer>

    <script>
    function pulsePointFeed() {
        return {
            loading: true,
            error: false,
            activeIncidents: [],
            recentIncidents: [],
            lastUpdated: '',
            _timer: null,

            get activeCount() { return this.activeIncidents.length; },
            get recentCount() { return this.recentIncidents.length; },

            init() {
                this.fetchData();
                this._timer = setInterval(() => this.fetchData(), 30000);
            },

            destroy() {
                if (this._timer) clearInterval(this._timer);
            },

            async fetchData() {
                try {
                    const resp = await fetch('/api/incidents', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        signal: AbortSignal.timeout(12000)
                    });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const data = await resp.json();
                    if (data.error && !data.active) throw new Error(data.error);
                    this.activeIncidents = data.active || [];
                    this.recentIncidents = data.recent || [];
                    this.error = false;
                    this.lastUpdated = 'Updated ' + this.timeAgo(data.fetchedAt);
                } catch (e) {
                    this.error = true;
                    this.lastUpdated = 'Update failed';
                } finally {
                    this.loading = false;
                }
            },

            formatTime(isoStr) {
                if (!isoStr) return '--:--';
                try {
                    const d = new Date(isoStr);
                    return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'America/New_York' });
                } catch { return '--:--'; }
            },

            timeAgo(isoStr) {
                if (!isoStr) return 'just now';
                const diff = Math.floor((Date.now() - new Date(isoStr).getTime()) / 1000);
                if (diff < 60) return 'just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                return Math.floor(diff / 3600) + 'h ago';
            }
        };
    }
    </script>
</body>
</html>
