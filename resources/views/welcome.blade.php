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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        mbfd: { 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d' },
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
        body { font-family: 'Inter', system-ui, sans-serif; }
        .chat-messages { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .chat-messages::-webkit-scrollbar { width: 6px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 3px; }
        .typing-dot { animation: typing 1.4s infinite; }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing { 0%, 60%, 100% { opacity: 0.3; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-4px); } }
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        .loading-bar { position: relative; overflow: hidden; }
        .loading-bar::after { content: ''; position: absolute; top: 0; left: 0; width: 50%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent); animation: shimmer 1.5s infinite; }
        .msg-ai p { margin-bottom: 0.5rem; }
        .msg-ai ul, .msg-ai ol { margin-left: 1.25rem; margin-bottom: 0.5rem; }
        .msg-ai li { margin-bottom: 0.25rem; }
        .msg-ai strong { font-weight: 600; }
        .msg-ai h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem; }
        @media (prefers-reduced-motion: reduce) {
            .typing-dot, .loading-bar::after { animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="antialiased bg-slate-50 text-slate-900 min-h-screen">

    <!-- Compact Header Shell -->
    <header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md h-16 flex items-center justify-between px-4 lg:px-6">
        <!-- Left: Logo + Title -->
        <div class="flex items-center gap-3">
            <img src="/images/mbfd_logo_new.png" alt="MBFD Logo" class="h-10 w-10 object-contain">
            <div class="hidden sm:block">
                <h1 class="text-white font-semibold text-base leading-tight">MBFD Support Hub</h1>
                <p class="text-slate-400 text-xs">Enterprise Command Portal</p>
            </div>
        </div>

        <!-- Center: Status Pills -->
        <div class="hidden md:flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-medium">
                <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                System Operational
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-500/15 border border-blue-500/30 text-blue-400 text-xs font-medium">
                <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                Secure Portal
            </span>
        </div>

        <!-- Right: Utility Actions -->
        <div class="flex items-center gap-2">
            <a href="{{ url('/admin/login') }}" class="min-h-[44px] px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                <span class="hidden sm:inline">Admin Login</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            
            <!-- Left Column - AI Support Assistant Panel (60%) -->
            <div class="lg:col-span-3">
                @if(env('FEATURE_AI_CHAT', true))
                <section x-data="aiChat()">
                    <div class="bg-white rounded-xl shadow-card border border-slate-200 overflow-hidden">
                        <!-- Chat Header -->
                        <div class="bg-slate-800 px-5 py-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 bg-red-600 rounded-lg flex items-center justify-center shadow-sm">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold text-sm">MBFD Support Assistant</h3>
                                    <p class="text-slate-400 text-xs">AI-powered guidance for SOGs, manuals, procedures, and station operations</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs">
                                    <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                                    Online
                                </span>
                            </div>
                        </div>

                        <!-- Quick Action Chips -->
                        <div class="px-4 pt-3 pb-2 bg-slate-50 border-b border-slate-100 flex flex-wrap gap-2 overflow-x-auto">
                            <button @click="askQuestion('What are the SOG requirements for ladder operations?')" class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                                🪜 Ladder SOGs
                            </button>
                            <button @click="askQuestion('What is the procedure for apparatus out of service?')" class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                                🚒 Out of Service
                            </button>
                            <button @click="askQuestion('What PPE is required for hazmat incidents?')" class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                                ⚠️ Hazmat PPE
                            </button>
                            <button @click="askQuestion('How do I complete daily checkout?')" class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                                📋 MBFD Forms
                            </button>
                        </div>

                        <!-- Messages Area -->
                        <div class="chat-messages h-80 lg:h-96 overflow-y-auto p-4 space-y-3 bg-slate-50/50" x-ref="chatMessages">
                            <template x-for="(msg, idx) in messages" :key="idx">
                                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                    <div :class="msg.role === 'user' 
                                        ? 'bg-red-600 text-white rounded-2xl rounded-br-md px-4 py-2.5 max-w-xs md:max-w-md text-sm shadow-sm' 
                                        : 'msg-ai bg-white border border-slate-200 text-slate-800 rounded-2xl rounded-bl-md px-4 py-2.5 max-w-xs md:max-w-lg text-sm shadow-sm'"
                                        x-html="msg.role === 'user' ? msg.content : renderMarkdown(msg.content)">
                                    </div>
                                </div>
                            </template>
                            <!-- Typing Indicator -->
                            <div x-show="loading" class="flex justify-start">
                                <div class="bg-white border border-slate-200 rounded-2xl rounded-bl-md px-4 py-3 shadow-sm">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                        <span class="typing-dot w-2 h-2 bg-red-400 rounded-full inline-block"></span>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-1">Searching documents...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Loading Progress Bar -->
                        <div x-show="loading" class="px-4 py-2 bg-slate-50 border-t border-slate-200">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-slate-200 rounded-full loading-bar">
                                    <div class="h-full bg-red-500 rounded-full" style="width: 100%;"></div>
                                </div>
                                <span class="text-xs text-slate-500 whitespace-nowrap">Analyzing...</span>
                            </div>
                        </div>

                        <!-- Sources -->
                        <div x-show="lastSources.length > 0" class="px-4 py-2 bg-slate-100/80 border-t border-slate-200">
                            <p class="text-xs text-slate-500">
                                <span class="font-medium">Sources:</span>
                                <template x-for="src in lastSources" :key="src">
                                    <span class="inline-block bg-white text-slate-600 rounded px-1.5 py-0.5 ml-1 text-xs border border-slate-200" x-text="src"></span>
                                </template>
                            </p>
                        </div>

                        <!-- Input Area -->
                        <div class="p-4 border-t border-slate-200 bg-white">
                            <form @submit.prevent="sendMessage()" class="flex gap-2">
                                <input 
                                    x-model="userInput" 
                                    type="text" 
                                    placeholder="Ask about procedures, manuals, SOGs, or station operations..." 
                                    aria-label="Type your message"
                                    class="flex-1 min-h-[44px] bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 transition-all"
                                    :disabled="loading"
                                    x-ref="chatInput"
                                >
                                <button 
                                    type="submit" 
                                    :disabled="loading || !userInput.trim()"
                                    class="min-h-[44px] px-5 bg-red-600 text-white rounded-xl font-medium text-sm hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2 shadow-sm"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                                    Send
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
                            content: 'Welcome! I\'m the MBFD Support Assistant. Ask me anything about driver manuals, SOGs, department procedures, or station operations.'
                        }],
                        userInput: '',
                        loading: false,
                        lastSources: [],
                        workerUrl: 'https://mbfd-support-ai.pdarleyjr.workers.dev/chat',

                        askQuestion(q) {
                            this.userInput = q;
                            this.sendMessage();
                        },

                        renderMarkdown(text) {
                            if (!text) return '';
                            // First escape HTML to prevent XSS
                            const escaped = text
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                            // Then render markdown
                            return escaped
                                .replace(/### (.*?)(\n|$)/g, '<h3>$1</h3>')
                                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                                .replace(/`(.*?)`/g, '<code class="bg-slate-100 px-1 rounded text-sm">$1</code>')
                                .replace(/^\* (.+)$/gm, '<li>$1</li>')
                                .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
                                .replace(/<\/ul>\s*<ul>/g, '')
                                .replace(/\n\n/g, '</p><p>')
                                .replace(/\n/g, '<br>')
                                .replace(/^/, '<p>').replace(/$/, '</p>')
                                .replace(/<p><\/p>/g, '');
                        },

                        async sendMessage() {
                            const msg = this.userInput.trim();
                            if (!msg || this.loading) return;

                            this.expanded = true;
                            this.messages.push({ role: 'user', content: msg });
                            this.userInput = '';
                            this.loading = true;
                            this.lastSources = [];
                            await this.$nextTick();
                            this.scrollToBottom();

                            try {
                                const resp = await fetch(this.workerUrl, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ message: msg })
                                });

                                if (!resp.ok) {
                                    const err = await resp.json().catch(() => ({}));
                                    throw new Error(err.error || 'Request failed');
                                }

                                const data = await resp.json();
                                this.messages.push({ role: 'assistant', content: data.response });
                                this.lastSources = data.sources || [];
                            } catch (e) {
                                this.messages.push({ role: 'assistant', content: 'Sorry, I encountered an error. Please try again.' });
                            } finally {
                                this.loading = false;
                                this.scrollToBottom();
                            }
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

            <!-- Right Column - Enterprise Side Panel (40%) -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- System Overview Mini Card -->
                <div class="bg-white rounded-xl shadow-card border border-slate-200 p-4">
                    <h3 class="text-sm font-semibold text-slate-800 mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        System Overview
                    </h3>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between py-2 border-b border-slate-100">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                <span class="text-sm text-slate-600">Platform</span>
                            </div>
                            <span class="text-xs font-medium text-emerald-600">Operational</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-slate-100">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                                <span class="text-sm text-slate-600">AI Assistant</span>
                            </div>
                            <span class="text-xs font-medium text-emerald-600">Online</span>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                <span class="text-sm text-slate-600">Database</span>
                            </div>
                            <span class="text-xs font-medium text-emerald-600">Connected</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Launch Module Cards -->
                <div class="bg-white rounded-xl shadow-card border border-slate-200 p-4">
                    <h3 class="text-sm font-semibold text-slate-800 mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </h3>

                        <!-- MBFD Forms -->
                        <a href="{{ url('/daily') }}" class="group block p-4 bg-white rounded-lg border border-slate-200 hover:border-purple-400 hover:shadow-card-hover transition-all duration-200">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2m-6 9l2 2 4-4"></path>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-slate-800 group-hover:text-purple-700">MBFD Forms</h3>
                                    <p class="text-sm text-slate-500">Apparatus checkout, inventory forms, and station requests</p>
                                </div>
                                <svg class="w-5 h-5 text-slate-300 group-hover:text-purple-500 transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </div>
                        </a>

                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Minimal Footer -->
    <footer class="border-t border-slate-200 bg-white/60 backdrop-blur-sm mt-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-slate-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <p class="text-xs text-slate-400">Secured System • Support Services Division</p>
        </div>
    </footer>
</body>
</html>