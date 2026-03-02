# MBFD Support Hub Landing Page Redesign - Implementation Plan

## 1. Goal Restatement

Transform the MBFD Support Hub landing page from its current marketing-style hero design into a refined **enterprise operational command portal**. The redesign will:

- Replace the oversized hero header with a compact, professional top shell
- Implement a two-column desktop layout featuring the AI assistant as the primary focus
- Add enterprise-grade status indicators and utility actions in the header
- Create an enterprise side panel with quick-launch module cards
- Deliver a mobile-first experience that feels like a native mobile web app
- Preserve all existing AI chat functionality (endpoint, Alpine.js behavior)
- Maintain compatibility with Filament, Livewire, and other existing UI surfaces

---

## 2. Assumptions

| # | Assumption | Rationale |
|---|------------|-----------|
| 1 | The existing AI chat endpoint (`https://mbfd-support-ai.pdarleyjr.workers.dev/chat`) remains functional | Required by task constraints |
| 2 | Tailwind CSS via CDN is acceptable (current approach) | Maintains simplicity; project already uses CDN |
| 3 | Alpine.js will continue to handle all chat interactions | Current implementation uses Alpine.js |
| 4 | The `/daily` and `/admin` routes remain unchanged | Task specifies not breaking existing routes |
| 5 | No dark theme is required | Explicitly excluded by design intent |
| 6 | MBFD red (`#B91C1C`) will serve as the primary action/accent color | Consistent with current branding |
| 7 | The deployment environment uses Docker with the existing `deploy.sh` script | Based on analysis of scripts |
| 8 | Cloudflare cache purging is part of deployment | Current deploy.sh includes this |
| 9 | Laravel Mix or Vite is available for asset building | Standard Laravel 11 setup |

---

## 3. Files to Modify/Create

### Primary File to Modify
| File | Purpose | Change Type |
|------|---------|-------------|
| `resources/views/welcome.blade.php` | Main landing page | Complete redesign |

### Supporting Files (Optional/If Needed)
| File | Purpose | Change Type |
|------|---------|-------------|
| `tailwind.config.js` | Add custom MBFD design tokens | Minor update |
| `resources/css/app.css` | Custom CSS if needed | Create if needed |
| `public/images/mbfd_logo_new.png` | Logo asset (assumed existing) | None |

### Files NOT to Modify
- `routes/web.php` - Routing is already correct
- `app/Services/CloudflareAIService.php` - Backend AI service unchanged
- Any Filament panel configuration files

---

## 4. Implementation Steps

### Phase 1: Design System & Structure Setup

#### Step 1.1: Update Tailwind Configuration
Add MBFD-specific design tokens to `tailwind.config.js`:

```javascript
// tailwind.config.js additions
colors: {
    mbfd: {
        50: '#fef2f2',
        100: '#fee2e2',
        200: '#fecaca',
        500: '#ef4444',
        600: '#dc2626',
        700: '#b91c1c',  // Primary MBFD red
        800: '#991b1b',
        900: '#7f1d1d',
    },
    slate: {
        850: '#1e293b',  // Deep navy for header
    }
},
extend: {
    boxShadow: {
        'card': '0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px -1px rgb(0 0 0 / 0.08)',
        'card-hover': '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08)',
    }
}
```

#### Step 1.2: Define Base Layout Structure

**Desktop Layout (min-width: 1024px)**
```
┌─────────────────────────────────────────────────────────────────┐
│  COMPACT HEADER (sticky)                                        │
│  ┌──────────┬─────────────────────────────┬──────────────────┐  │
│  │ Logo     │ Status Pills                │ Admin | Forms    │  │
│  └──────────┴─────────────────────────────┴──────────────────┘  │
├─────────────────────────────────────────────────────────────────┤
│  MAIN CONTENT                                                   │
│  ┌───────────────────────────────┬──────────────────────────┐  │
│  │ LEFT COLUMN (60%)             │ RIGHT COLUMN (40%)       │  │
│  │                               │                          │  │
│  │  AI Assistant Panel           │  System Overview Card    │  │
│  │  - Icon + Title               │  - Status indicators      │  │
│  │  - Live status                │                          │  │
│  │  - Quick prompts              │  Quick Launch Modules     │  │
│  │  - Chat interface             │  - MBFD Forms            │  │
│  │  - Composer                   │  - Admin Platform         │  │
│  │                               │  - Station Inventory     │  │
│  │                               │  - Training Panel        │  │
│  │                               │  - Daily Checkout        │  │
│  └───────────────────────────────┴──────────────────────────┘  │
├─────────────────────────────────────────────────────────────────┤
│  FOOTER (minimal)                                               │
└─────────────────────────────────────────────────────────────────┘
```

**Mobile Layout (< 768px)**
```
┌─────────────────────────┐
│ STICKY TOP BAR         │
│ Logo | Status | Menu   │
├─────────────────────────┤
│ AI ASSISTANT (visible) │
│ [prompt chips - scroll]│
│ [chat messages]         │
│ [composer - 44px+]      │
├─────────────────────────┤
│ QUICK LAUNCH TILES     │
│ ┌─────┐ ┌─────┐        │
│ │Forms│ │Admin│        │
│ └─────┘ └─────┘        │
│ ┌─────┐ ┌─────┐        │
│ │Inv │ │Trng │        │
│ └─────┘ └─────┘        │
├─────────────────────────┤
│ FOOTER                  │
└─────────────────────────┘
```

### Phase 2: Header Implementation

#### Step 2.1: Compact Header Shell
Replace the hero gradient header with a sticky compact header:

```html
<!-- Desktop Header -->
<header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between h-16">
            <!-- Left: Logo + Identity -->
            <div class="flex items-center gap-3">
                <img src="/images/mbfd_logo_new.png" alt="MBFD" class="h-10 w-10">
                <div>
                    <h1 class="text-white font-semibold text-lg leading-tight">MBFD Support Hub</h1>
                    <p class="text-slate-400 text-xs">Enterprise Operations Portal</p>
                </div>
            </div>

            <!-- Center: Status Pills -->
            <div class="hidden md:flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 text-xs font-medium">
                    <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                    System Operational
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-500/15 border border-blue-500/30 text-blue-400 text-xs font-medium">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    Secure Portal
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-500/15 border border-red-500/30 text-red-400 text-xs font-medium">
                    <span class="w-1.5 h-1.5 bg-red-400 rounded-full animate-pulse"></span>
                    AI Online
                </span>
            </div>

            <!-- Right: Utility Actions -->
            <div class="flex items-center gap-2">
                <a href="{{ url('/admin') }}" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-medium rounded-lg transition-colors border border-slate-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Admin
                </a>
                <a href="{{ url('/daily') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Forms
                </a>
            </div>
        </div>
    </div>
</header>
```

#### Step 2.2: Mobile Header Adaptation
```html
<!-- Mobile Header - Sticky, Compact -->
<header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md safe-area-pt">
    <div class="flex items-center justify-between h-14 px-3">
        <!-- Logo -->
        <div class="flex items-center gap-2">
            <img src="/images/mbfd_logo_new.png" alt="MBFD" class="h-8 w-8">
            <span class="text-white font-semibold text-base">MBFD Hub</span>
        </div>

        <!-- Mobile Status + Actions -->
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse" title="System Operational"></span>
            <a href="{{ url('/daily') }}" class="p-2 bg-red-600 rounded-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            </a>
        </div>
    </div>
</header>
```

### Phase 3: Main Content - Two-Column Layout

#### Step 3.1: Container and Grid Setup
```html
<main class="min-h-screen bg-slate-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        <!-- Desktop: Two-Column Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <!-- Left Column: AI Assistant (60% / 3 cols) -->
            <div class="lg:col-span-3">
                <!-- AI Assistant Panel -->
            </div>

            <!-- Right Column: Enterprise Side Panel (40% / 2 cols) -->
            <div class="lg:col-span-2">
                <!-- System Overview + Quick Launch -->
            </div>
        </div>
    </div>
</main>
```

#### Step 3.2: AI Assistant Panel (Left Column)

This is the PRIMARY element - elevated design with stronger framing:

```html
<!-- AI Assistant Panel -->
<section x-data="aiChat()" class="bg-white rounded-xl shadow-card border border-slate-200 overflow-hidden">
    <!-- Header -->
    <div class="bg-slate-800 px-5 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-600 rounded-lg flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-white font-semibold">MBFD Support Assistant</h3>
                <p class="text-slate-400 text-xs">SOGs, manuals, procedures & more</p>
            </div>
        </div>
        <!-- Live Status Indicator -->
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span>
            <span class="text-emerald-400 text-xs font-medium">Online</span>
        </div>
    </div>

    <!-- Expandable Chat Body -->
    <div x-show="expanded" x-transition.duration.200ms>
        <!-- Quick Prompt Chips - Horizontally Scrollable -->
        <div class="px-4 pt-3 pb-2 bg-slate-50 border-b border-slate-100">
            <div class="flex gap-2 overflow-x-auto pb-1 -mb-1 scrollbar-hide">
                <button @click="askQuestion('What are the SOG requirements for ladder operations?')" 
                    class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                    🪜 Ladder SOGs
                </button>
                <button @click="askQuestion('What is the apparatus out-of-service procedure?')" 
                    class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                    🚒 Out of Service
                </button>
                <button @click="askQuestion('What PPE is required for hazmat incidents?')" 
                    class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                    ⚠️ Hazmat PPE
                </button>
                <button @click="askQuestion('List all daily checkout procedures')" 
                    class="flex-shrink-0 text-xs px-3 py-1.5 bg-white border border-slate-200 rounded-full text-slate-600 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                    📋 Daily Checkout
                </button>
            </div>
        </div>

        <!-- Messages Area -->
        <div class="chat-messages h-80 lg:h-96 overflow-y-auto p-4 space-y-3 bg-slate-50/50" x-ref="chatMessages">
            <template x-for="(msg, idx) in messages" :key="idx">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div :class="msg.role === 'user' 
                        ? 'bg-red-600 text-white rounded-2xl rounded-br-md px-4 py-2.5 max-w-xs lg:max-w-md text-sm shadow-sm' 
                        : 'bg-white border border-slate-200 text-slate-800 rounded-2xl rounded-bl-md px-4 py-2.5 max-w-xs lg:max-w-lg text-sm shadow-sm'"
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
                    <p class="text-xs text-slate-500 mt-1">Searching knowledge base...</p>
                </div>
            </div>
        </div>

        <!-- Sources Display -->
        <div x-show="lastSources.length > 0" class="px-4 py-2 bg-slate-100/80 border-t border-slate-200">
            <p class="text-xs text-slate-500">
                <span class="font-medium">Sources:</span>
                <template x-for="src in lastSources" :key="src">
                    <span class="inline-block bg-white text-slate-600 rounded px-1.5 py-0.5 ml-1 text-xs border border-slate-200" x-text="src"></span>
                </template>
            </p>
        </div>

        <!-- Input Composer -->
        <div class="p-4 border-t border-slate-200 bg-white">
            <form @submit.prevent="sendMessage()" class="flex gap-2">
                <input 
                    x-model="userInput" 
                    type="text" 
                    placeholder="Ask about procedures, manuals, or SOGs..." 
                    class="flex-1 min-h-[48px] bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-500 transition-all"
                    :disabled="loading"
                    x-ref="chatInput"
                >
                <button 
                    type="submit" 
                    :disabled="loading || !userInput.trim()"
                    class="min-h-[48px] px-5 bg-red-600 text-white rounded-xl font-medium text-sm hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2 shadow-sm"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </form>
        </div>
    </div>

    <!-- Collapsed State Header (clickable to expand) -->
    <button @click="expanded = !expanded" class="w-full bg-slate-800 px-5 py-3 flex items-center justify-between hover:bg-slate-750 transition-colors lg:hidden">
        <span class="text-white text-sm">Tap to open AI Assistant</span>
        <svg :class="expanded ? 'rotate-180' : ''" class="w-5 h-5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </button>
</section>
```

#### Step 3.3: Enterprise Side Panel (Right Column)

```html
<!-- Right Column: Enterprise Side Panel -->
<div class="space-y-4">
    <!-- System Overview Mini Card -->
    <div class="bg-white rounded-xl shadow-card border border-slate-200 p-4">
        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">System Status</h4>
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600">Platform</span>
                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Operational
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600">AI Assistant</span>
                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Online
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-slate-600">Database</span>
                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Connected
                </span>
            </div>
        </div>
    </div>

    <!-- Quick Launch Modules -->
    <div class="bg-white rounded-xl shadow-card border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 bg-slate-50 border-b border-slate-100">
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Quick Launch</h4>
        </div>
        
        <div class="divide-y divide-slate-100">
            <!-- Module 1: MBFD Forms -->
            <a href="{{ url('/daily') }}" class="group flex items-center gap-3 p-4 hover:bg-slate-50 transition-colors">
                <div class="flex-shrink-0 w-10 h-10 bg-red-50 text-red-600 rounded-lg flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-slate-900 group-hover:text-red-700 transition-colors">MBFD Forms</h5>
                    <p class="text-xs text-slate-500 truncate">Daily checkout, inventory, supply requests</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-red-400 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>

            <!-- Module 2: Admin Platform -->
            <a href="{{ url('/admin') }}" class="group flex items-center gap-3 p-4 hover:bg-slate-50 transition-colors">
                <div class="flex-shrink-0 w-10 h-10 bg-slate-100 text-slate-600 rounded-lg flex items-center justify-center group-hover:bg-slate-800 group-hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-slate-900 group-hover:text-slate-700 transition-colors">Admin Platform</h5>
                    <p class="text-xs text-slate-500 truncate">Fleet, inspections, inventory, analytics</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>

            <!-- Module 3: Station Inventory -->
            <a href="#" class="group flex items-center gap-3 p-4 hover:bg-slate-50 transition-colors">
                <div class="flex-shrink-0 w-10 h-10 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center group-hover:bg-amber-600 group-hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-slate-900 group-hover:text-amber-700 transition-colors">Station Inventory</h5>
                    <p class="text-xs text-slate-500 truncate">Physical inventory tracking system</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-amber-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>

            <!-- Module 4: Training Panel -->
            <a href="#" class="group flex items-center gap-3 p-4 hover:bg-slate-50 transition-colors">
                <div class="flex-shrink-0 w-10 h-10 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-slate-900 group-hover:text-blue-700 transition-colors">Training Panel</h5>
                    <p class="text-xs text-slate-500 truncate">Training records and certifications</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-blue-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>

            <!-- Module 5: Daily Checkout -->
            <a href="#" class="group flex items-center gap-3 p-4 hover:bg-slate-50 transition-colors">
                <div class="flex-shrink-0 w-10 h-10 bg-purple-50 text-purple-600 rounded-lg flex items-center justify-center group-hover:bg-purple-600 group-hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-slate-900 group-hover:text-purple-700 transition-colors">Daily Checkout</h5>
                    <p class="text-xs text-slate-500 truncate">Equipment checkout and returns</p>
                </div>
                <svg class="w-4 h-4 text-slate-300 group-hover:text-purple-500 group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>
        </div>
    </div>
</div>
```

### Phase 4: Mobile-Optimized Adaptations

#### Step 4.1: Mobile Layout Overrides
```css
/* In <style> block or app.css */
@media (max-width: 1023px) {
    /* Stack columns vertically */
    .lg\:grid-cols-5 {
        grid-template-columns: 1fr;
    }
    
    /* Remove left column span on mobile */
    .lg\:col-span-3 {
        grid-column: span 1 / span 1;
    }
    
    .lg\:col-span-2 {
        grid-column: span 1 / span 1;
    }
}

@media (max-width: 767px) {
    /* Mobile-specific adjustments */
    .chat-messages {
        height: 64vh; /* More screen real estate on mobile */
    }
    
    /* Tighter spacing */
    .p-4 {
        padding: 0.75rem;
    }
    
    /* Larger touch targets */
    button, a {
        min-height: 44px;
    }
}

/* Hide scrollbar but keep functionality */
.scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.scrollbar-hide::-webkit-scrollbar {
    display: none;
}
```

### Phase 5: Footer

#### Step 5.1: Minimal Footer
```html
<footer class="border-t border-slate-200 bg-white mt-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
        <p class="text-xs text-slate-400">© {{ date('Y') }} Miami Beach Fire Department</p>
        <p class="text-xs text-slate-400">Support Services Division • Secured System</p>
    </div>
</footer>
```

---

## 5. Responsive Strategy

### Breakpoint Strategy

| Breakpoint | Target | Layout Behavior |
|------------|--------|-----------------|
| `0-639px` | Small mobile | Single column, stacked components, compact header |
| `640-1023px` | Tablet/Large mobile | Single column, AI assistant primary, tiles below |
| `1024px+` | Desktop | Two-column layout (60/40 split) |

### Key Responsive Decisions

1. **Header**: Sticky on all devices; reduces from 3 status pills to 1 on mobile
2. **AI Panel**: Always visible on mobile (not collapsed); expanded by default on desktop
3. **Quick Prompts**: Horizontal scroll on mobile; wraps on desktop
4. **Module Cards**: Vertical stack on mobile; list view on desktop side panel
5. **Touch Targets**: Minimum 44px height for all interactive elements
6. **Safe Areas**: Respect mobile browser chrome with proper padding

---

## 6. Verification Plan

### Pre-Deployment Verification (Local)

| # | Check | Method |
|---|-------|--------|
| 1 | Page loads without errors | Browser console inspection |
| 2 | AI chat sends message successfully | Send test message, verify response |
| 3 | All quick prompt buttons work | Click each chip, verify message sent |
| 4 | Mobile layout renders correctly | Browser DevTools mobile viewport |
| 5 | Desktop two-column layout | Browser DevTools desktop viewport |
| 6 | Header sticky behavior | Scroll page, verify header stays fixed |
| 7 | Module links navigate correctly | Click each module, verify route |
| 8 | No Filament/Livewire conflicts | Verify other routes still work |

### Post-Deployment Verification (Production)

| # | Check | Method |
|---|-------|--------|
| 1 | Landing page accessible | `curl https://support.darleyplex.com/` |
| 2 | __version endpoint works | `curl https://support.darleyplex.com/__version` |
| 3 | AI chat endpoint reachable | Send test message from production UI |
| 4 | /daily route works | `curl https://support.darleyplex.com/daily/` |
| 5 | /admin redirects properly | `curl -I https://support.darleyplex.com/admin` |
| 6 | Cloudflare cache cleared | Verify CDN serving new content |

---

## 7. Deployment Plan

### Step-by-Step Deployment

1. **Commit Changes**
   ```bash
   git add resources/views/welcome.blade.php
   git commit -m "refactor: Redesign landing page as enterprise command portal"
   git push origin main
   ```

2. **Run Deploy Script** (on VPS or via CI)
   ```bash
   ./scripts/deploy.sh
   ```

3. **Smoke Tests** (automated in deploy.sh, can also run manually)
   ```bash
   # Test landing page
   curl -sf https://support.darleyplex.com/ | head -50
   
   # Test version endpoint
   curl -sf https://support.darleyplex.com/__version | jq .
   
   # Test AI endpoint reachable
   curl -sf -X POST https://mbfd-support-ai.pdarleyjr.workers.dev/chat \
     -H "Content-Type: application/json" \
     -d '{"message":"test"}'
   ```

### Deployment Verification Checklist

- [ ] Git commit pushed to origin/main
- [ ] Deploy script executed successfully
- [ ] Database migration completed (if any)
- [ ] Laravel optimizations ran
- [ ] Cloudflare cache purged
- [ ] Smoke tests passed
- [ ] Landing page renders correctly
- [ ] AI chat functional
- [ ] All module links work

---

## 8. Rollback Plan

### If Issues Occur

1. **Quick Rollback** (if just need previous version):
   ```bash
   # On VPS
   ./scripts/rollback.sh
   ```

2. **Rollback with Database** (if schema changed):
   ```bash
   # Find backup file
   ls -la backups/
   
   # Rollback with specific backup
   ./scripts/rollback.sh <tag> backups/mbfd_hub_<timestamp>.sql
   ```

3. **Manual Revert** (if need specific change undone):
   ```bash
   git revert <commit-hash>
   git push origin main
   # Redeploy
   ./scripts/deploy.sh
   ```

### Rollback Triggers

Immediately rollback if:
- Landing page returns 500 error
- AI chat completely non-functional
- All module links broken
- Filament admin inaccessible

---

## 9. Risk Checklist

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|------------|--------|-------------|
| 1 | AI chat breaks due to HTML changes | Low | High | Keep existing Alpine.js `aiChat()` function intact; only modify UI wrapper |
| 2 | Filament UI broken by CSS conflicts | Low | High | Use specific class names; avoid overriding generic Tailwind classes used by Filament |
| 3 | Mobile layout unusable | Medium | Medium | Test thoroughly in DevTools before deploy |
| 4 | Deployment fails | Low | Medium | Have rollback.sh ready; backup database before deploy |
| 5 | Route conflicts | Low | High | Do not modify routes/web.php |
| 6 | Cloudflare caching old version | Low | Medium | Deploy script purges cache; can manually purge if needed |
| 7 | AI worker endpoint unreachable | Medium | High | This is external; verify endpoint separately; landing page should still load |
| 8 | JavaScript errors break chat | Low | Medium | Keep existing `aiChat()` function unchanged; only update UI elements |

---

## Summary

This plan delivers a **complete redesign** of the MBFD Support Hub landing page from a marketing-style hero to an **enterprise operational command portal** with:

- ✅ Compact sticky header with status indicators
- ✅ Two-column desktop layout (AI assistant + quick launch)
- ✅ Premium AI assistant panel as primary focus
- ✅ Enterprise side panel with system status + module cards
- ✅ Mobile-first responsive design
- ✅ All existing functionality preserved
- ✅ No breaking changes to routes or other UI surfaces

The implementation is **contained in a single file change** (`welcome.blade.php`), making it low-risk to deploy and easy to rollback if needed.