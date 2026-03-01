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
    <title>MBFD Hub | Enterprise Management</title>
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
                        mbfd: { 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d' }
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
        .hero-gradient { background: linear-gradient(135deg, #1e293b 0%, #334155 50%, #1e293b 100%); }
        .card-glow:hover { box-shadow: 0 20px 40px -12px rgba(0,0,0,0.1); }
        @media (prefers-reduced-motion: reduce) {
            .typing-dot, .loading-bar::after { animation: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="antialiased bg-slate-100 text-slate-900 min-h-screen">

    <!-- Hero Header -->
    <header class="hero-gradient relative overflow-hidden">
        <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iMC4wMyI+PHBhdGggZD0iTTM2IDM0djItSDJ2LTJoMzR6bTAtMzBoMnYySDM2VjR6Ii8+PC9nPjwvZz48L3N2Zz4=')] opacity-50"></div>
        <div class="relative max-w-4xl mx-auto px-6 py-12 md:py-16 text-center">
            <div class="inline-flex items-center justify-center w-28 h-28 bg-white/10 backdrop-blur-sm rounded-2xl border border-white/20 shadow-lg mb-6">
                <img src="/images/mbfd_logo_new.png" alt="MBFD Logo" class="h-24 w-24 object-contain drop-shadow-md rounded-xl">
            </div>
            <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-white tracking-tight mb-3">
                MBFD Support Hub
            </h1>
            <p class="text-slate-300 text-base md:text-lg max-w-lg mx-auto leading-relaxed">
                Enterprise equipment management, daily checkout, and logistics platform.
            </p>
            <div class="mt-6 flex items-center justify-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-500/20 border border-green-400/30 text-green-300 text-xs font-medium">
                    <span class="w-1.5 h-1.5 bg-green-400 rounded-full animate-pulse"></span>
                    System Operational
                </span>
            </div>
        </div>
        <!-- Bottom fade -->
        <div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-slate-100 to-transparent"></div>
    </header>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 mt-8 pb-12 space-y-8">

        @if(env('FEATURE_AI_CHAT', true))
        <!-- AI Chat Assistant -->
        <section x-data="aiChat()">
            <div class="bg-white rounded-2xl shadow-md border border-slate-200 overflow-hidden">
                <!-- Chat Header -->
                <button @click="expanded = !expanded" class="w-full bg-slate-800 px-5 py-4 flex items-center justify-between cursor-pointer hover:bg-slate-750 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-red-600 rounded-lg flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-white font-semibold text-sm">MBFD Support Assistant</h3>
                            <p class="text-slate-400 text-xs">Ask about SOGs, equipment manuals & procedures</p>
                        </div>
                    </div>
                    <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>

                <!-- Chat Body -->
                <div x-show="expanded" x-transition.duration.200ms>
                    <!-- Quick Action Chips -->
                    <div class="px-4 pt-3 pb-1 bg-slate-50 border-b border-slate-100 flex flex-wrap gap-2">
                        <button @click="askQuestion('What are the SOG requirements for ladder operations?')" class="text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                            🪜 Ladder SOGs
                        </button>
                        <button @click="askQuestion('What is the procedure for apparatus out of service?')" class="text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                            🚒 Out of Service
                        </button>
                        <button @click="askQuestion('What PPE is required for hazmat incidents?')" class="text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 transition-colors">
                            ⚠️ Hazmat PPE
                        </button>
                    </div>

                    <!-- Messages Area -->
                    <div class="chat-messages h-72 overflow-y-auto p-4 space-y-3 bg-slate-50/50" x-ref="chatMessages">
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
                                placeholder="Ask about procedures, manuals, or SOGs..." 
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
            </div>
        </section>

        <script>
        function aiChat() {
            return {
                expanded: false,
                messages: [{
                    role: 'assistant',
                    content: 'Welcome! I\'m the MBFD Support Assistant. Ask me anything about driver manuals, SOGs, or department procedures.'
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
                    return text
                        .replace(/### (.*?)(\n|$)/g, '<h3>$1</h3>')
                        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                        .replace(/\*(.*?)\*/g, '<em>$1</em>')
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

        <!-- Action Cards Grid -->
        <section>
            <h2 class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-4 px-1">Quick Access</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                
                <!-- MBFD Forms Card -->
                <a href="{{ url('/daily') }}" class="group block relative bg-white rounded-2xl shadow-sm border border-slate-200 p-7 card-glow hover:border-red-200 hover:-translate-y-0.5 transition-all duration-200 overflow-hidden">
                    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-red-600 to-red-500 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
                    
                    <div class="flex items-start gap-5">
                        <div class="flex-shrink-0 w-12 h-12 bg-red-50 text-red-600 rounded-xl flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-colors duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-slate-900 group-hover:text-red-700 transition-colors">MBFD Forms</h3>
                            <p class="text-sm text-slate-500 mt-1 leading-relaxed">Daily checkout modules, physical inventory forms, and station supply requests.</p>
                        </div>
                        <svg class="w-5 h-5 text-slate-300 group-hover:text-red-400 group-hover:translate-x-0.5 transition-all flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </div>
                </a>

                <!-- Admin Platform Card -->
                <a href="{{ url('/admin') }}" class="group block relative bg-white rounded-2xl shadow-sm border border-slate-200 p-7 card-glow hover:border-slate-300 hover:-translate-y-0.5 transition-all duration-200 overflow-hidden">
                    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-slate-700 to-slate-600 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></div>
                    
                    <div class="flex items-start gap-5">
                        <div class="flex-shrink-0 w-12 h-12 bg-slate-100 text-slate-600 rounded-xl flex items-center justify-center group-hover:bg-slate-800 group-hover:text-white transition-colors duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-slate-900 group-hover:text-slate-700 transition-colors">Admin Platform</h3>
                            <p class="text-sm text-slate-500 mt-1 leading-relaxed">Fleet management, inspections, inventory, personnel, and analytics console.</p>
                        </div>
                        <svg class="w-5 h-5 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </div>
                </a>
                
            </div>
        </section>
    </main>
    
    <!-- Footer -->
    <footer class="border-t border-slate-200 bg-white/60 backdrop-blur-sm">
        <div class="max-w-4xl mx-auto px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-slate-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <p class="text-xs text-slate-400">Secured System &middot; Support Services Division</p>
        </div>
    </footer>
</body>
</html>