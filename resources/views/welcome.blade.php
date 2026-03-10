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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Source Sans 3"', 'system-ui', 'sans-serif'], heading: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'] },
                    colors: {
                        mbfd: { 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d' },
                        neutral: { 50: '#FAFAF8', 100: '#F5F3F0', 200: '#E8E5E0', 300: '#D4D0CA', 400: '#A8A29E', 500: '#78716C', 600: '#57534E', 700: '#44403C', 800: '#292524', 900: '#1C1917' },
                        slate: { 850: '#1e293b', 900: '#0f172a' }
                    },
                    boxShadow: {
                        'card': '0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px -1px rgb(0 0 0 / 0.08)',
                        'card-hover': '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08)'
                    }
                }
            }
        }
    </script>
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
        @media (prefers-reduced-motion: reduce) {
            .typing-dot, .loading-bar::after { animation: none; }
            .stagger-item { opacity: 1; animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="antialiased bg-neutral-50 text-neutral-800 min-h-screen">

    <!-- Compact Header Shell -->
    <header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md h-16 flex items-center justify-between px-4 lg:px-6" style="padding-top: max(0px, env(safe-area-inset-top, 0px));">
        <!-- Left: Logo + Title -->
        <div class="flex items-center gap-3">
            <img src="/images/mbfd_logo_new.png" alt="MBFD Logo" class="h-10 w-10 object-contain">
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
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Left Column - Navigation Cards (Primary Actions) Ã¢ now 2/3 width on desktop, FIRST on mobile -->
            <div class="lg:col-span-2 space-y-4 order-1">
                <h2 class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Quick Launch
                </h2>

                <!-- MBFD Forms Ã¢ purple accent -->
                <a href="{{ url('/daily') }}" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-purple-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-purple-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2m-6 9l2 2 4-4"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-purple-700 font-heading text-base">MBFD Forms</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">Apparatus checkout, vehicle inspections, inventory forms, and station requests</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-purple-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>

                <!-- Eval Feedback Hub Ã¢ indigo accent -->
                <a href="{{ url('/workgroups/login') }}" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-indigo-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-indigo-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-indigo-700 font-heading text-base">Workgroup Dashboard</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">Committee evaluations, product reviews, and workgroup sessions</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-indigo-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>

                <!-- Pump Simulator Ã¢ amber accent -->
                <a href="https://pdarleyjr.github.io/puc-sim-manual-ui/" target="_blank" rel="noopener noreferrer" class="stagger-item group block bg-white rounded-xl shadow-card border border-neutral-200 hover:shadow-card-hover hover:border-amber-300 transition-all duration-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-amber-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="flex items-start gap-4 p-5 flex-1">
                            <div class="w-11 h-11 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600 flex-shrink-0 group-hover:scale-105 transition-transform">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-neutral-800 group-hover:text-amber-700 font-heading text-base">Pump Panel</h3>
                                <p class="text-sm text-neutral-500 mt-0.5">PUC pump panel operations training simulator</p>
                            </div>
                            <svg class="w-5 h-5 text-neutral-300 group-hover:text-amber-500 transition-colors flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Right Column - AI Support Assistant Panel (1/3 width) Ã¢ SECOND on mobile -->
            <div class="lg:col-span-1 order-2">
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
                        workerUrl: 'https://mbfd-support-ai.pdarleyjr.workers.dev/chat',

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
                                .replace(/^\* (.+)$/gm, '<li>$1</li}')
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
    </main>
    
    <!-- Minimal Footer -->
    <footer class="border-t border-neutral-200 bg-white/60 backdrop-blur-sm mt-8" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-neutral-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <p class="text-xs text-neutral-400">Secured System &bull; Support Services Division</p>
        </div>
    </footer>
</body>
</html>