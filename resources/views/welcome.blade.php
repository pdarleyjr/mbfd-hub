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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MBFD Hub">
    <title>MBFD Hub | Enterprise Management</title>
    <!-- Inter Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="antialiased bg-slate-50 text-slate-900">
    <div class="min-h-screen flex flex-col items-center justify-center p-6 bg-gradient-to-br from-slate-50 to-slate-100">
        
        <!-- Header Section -->
        <div class="max-w-xl w-full text-center space-y-6 mb-12 animate-fade-in-up">
            <div class="inline-block p-4 bg-white rounded-2xl shadow-sm border border-slate-100 mb-2">
                <img src="/images/mbfd_no_bg_new.png" alt="MBFD Logo" class="h-20 w-auto object-contain drop-shadow-sm">
            </div>
            <div>
                <h1 class="text-4xl md:text-5xl font-bold tracking-tight text-slate-900 mb-3">MBFD Support Hub</h1>
                <p class="text-lg text-slate-500 max-w-md mx-auto leading-relaxed">Enterprise equipment management, daily checkout, and logistics platform.</p>
            </div>
        </div>

        <!-- Action Cards Grid -->
        <div class="max-w-3xl w-full grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Daily Checkout Card -->
            <a href="{{ url('/daily') }}" class="group block relative bg-white rounded-2xl shadow-sm border border-slate-200 p-8 hover:shadow-xl hover:border-red-200 hover:-translate-y-1 transition-all duration-300 overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-red-600 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                
                <div class="flex items-start justify-between mb-6">
                    <div class="w-14 h-14 bg-red-50 text-red-600 rounded-xl flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-colors duration-300">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                </div>

                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold text-slate-900">MBFD Forms</h2>
                    <p class="text-slate-500 leading-relaxed">Access daily checkout modules, physical inventory forms, and station requests.</p>
                </div>
            </a>

            <!-- Admin Dashboard Card -->
            <a href="{{ url('/admin') }}" class="group block relative bg-white rounded-2xl shadow-sm border border-slate-200 p-8 hover:shadow-xl hover:border-slate-400 hover:-translate-y-1 transition-all duration-300 overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-slate-800 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                
                <div class="flex items-start justify-between mb-6">
                    <div class="w-14 h-14 bg-slate-100 text-slate-700 rounded-xl flex items-center justify-center group-hover:bg-slate-800 group-hover:text-white transition-colors duration-300">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                </div>

                <div class="space-y-2">
                    <h2 class="text-2xl font-semibold text-slate-900">Admin Platform</h2>
                    <p class="text-slate-500 leading-relaxed">Secure portal for management of fleet, inspections, personnel, and analytics.</p>
                </div>
            </a>
            
        </div>
        
        <!-- Footer Info -->
        <div class="mt-16 text-center text-sm text-slate-400 font-medium tracking-wide">
            &copy; {{ date('Y') }} Miami Beach Fire Department. Secured System.
        </div>
    </div>
</body>
</html>