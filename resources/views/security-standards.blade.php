<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <meta name="description" content="MBFD Hub Security & Standards — a public summary of the platform's security posture and alignment with applicable fire-service documentation standards.">
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
    <title>Security &amp; Standards | MBFD Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <style>
        body { font-family: 'Source Sans 3', system-ui, sans-serif; }
        .font-heading { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        .sec-card { transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s ease; }
        .sec-card:hover { transform: translateY(-1px); }
        @media (prefers-reduced-motion: reduce) {
            .sec-card:hover { transform: none; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="antialiased bg-neutral-50 text-neutral-800 min-h-screen flex flex-col">

    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 bg-red-600 text-white px-3 py-2 rounded-md text-sm font-medium">Skip to content</a>

    <!-- Header (matches landing page) -->
    <header class="sticky top-0 z-50 bg-slate-850 border-b border-slate-700/50 backdrop-blur-md h-16 flex items-center justify-between px-4 lg:px-6" style="background-color: #0f172a; padding-top: max(0px, env(safe-area-inset-top, 0px));">
        <a href="{{ url('/') }}" class="flex items-center gap-3 group" aria-label="Return to MBFD Support Hub home">
            <img src="/images/mbfd_logo.png" alt="" class="h-10 w-10 object-contain" aria-hidden="true">
            <div class="hidden sm:block">
                <h1 class="text-white font-semibold text-base leading-tight font-heading">MBFD Support Hub</h1>
                <p class="text-slate-400 text-xs">Enterprise Command Portal</p>
            </div>
        </a>
        <div class="flex items-center gap-2">
            <a href="{{ url('/') }}" class="hidden sm:inline-flex min-h-[44px] px-3 py-2 text-sm font-medium text-slate-200 hover:text-white items-center gap-2 rounded-lg hover:bg-slate-700/40 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Home
            </a>
            <a href="{{ url('/admin/login') }}" class="min-h-[44px] px-4 py-2 text-sm font-medium bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                <span class="hidden sm:inline">Admin Login</span>
            </a>
        </div>
    </header>

    <main id="main" class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 py-8 lg:py-12">

        <!-- Hero -->
        <section aria-labelledby="page-title" class="mb-10">
            <p class="text-xs font-semibold uppercase tracking-wider text-red-600 mb-2 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Public Trust Page
            </p>
            <h1 id="page-title" class="text-3xl sm:text-4xl font-bold text-neutral-900 font-heading tracking-tight">Security &amp; Standards</h1>
            <p class="mt-3 text-base sm:text-lg text-neutral-600 max-w-3xl leading-relaxed">
                MBFD Hub is designed to support secure, accountable fire department logistics, maintenance, project,
                and administrative workflows. This page summarizes the platform's security posture and the
                fire-service documentation standards its implemented modules are designed to support.
            </p>
        </section>

        <!-- A. Security-first overview -->
        <section aria-labelledby="overview-title" class="mb-12">
            <div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 sm:p-8">
                <h2 id="overview-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-3">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    Security-first overview
                </h2>
                <p class="text-neutral-700 leading-relaxed">
                    MBFD Hub is built around controlled access, role-aware workflows, and secure-by-design
                    development practices. Public, employee, and administrative areas are separated, sensitive
                    operations require authentication and role-based authorization, and administrative records
                    are designed to be reviewable and accountable. Security information on this page is
                    intentionally summarized at a high level &mdash; detailed infrastructure, configuration, and
                    control information is restricted to authorized administrators and IT reviewers.
                </p>
            </div>
        </section>

        <!-- B. Security posture -->
        <section aria-labelledby="posture-title" class="mb-12">
            <h2 id="posture-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                Security posture
            </h2>
            <p class="text-sm text-neutral-500 mb-5 max-w-3xl">
                Organized using widely referenced concepts from NIST CSF 2.0 (Govern, Identify, Protect,
                Detect, Respond, Recover) and informed by CISA Secure by Design principles. MBFD Hub is not
                certified by NIST or CISA.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

                <!-- Controlled Access -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-red-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center text-red-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Controlled Access</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Administrative areas require authentication. Privileged actions require an assigned role.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Encrypted Connections -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-emerald-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center text-emerald-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Encrypted Connections</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Traffic to MBFD Hub is delivered over HTTPS with modern transport security and strict transport headers.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Role-Aware Workflows -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-indigo-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Role-Aware Workflows</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Least-privilege roles separate command staff, administrators, logistics, training, and read-only reviewers.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input Validation -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-amber-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center text-amber-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Input Validation</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Form submissions and uploads are validated server-side, including file-type and size checks on photos and attachments.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Audit-Friendly Records -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-purple-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center text-purple-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Audit-Friendly Records</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Records capture timestamps, the responsible user, status changes, and review activity for defensible documentation.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rate Limiting & Abuse Protection -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-sky-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-sky-50 flex items-center justify-center text-sky-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Rate Limiting</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Public endpoints are rate-limited per client, and edge protections are in place against bulk and abusive traffic.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Signed & Expiring Links -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-teal-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-teal-50 flex items-center justify-center text-teal-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Signed Access Links</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Where enabled, station-side workflows use signed, scope-limited URLs so credentialed sessions are not required on shared devices.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Secure Development -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-rose-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-rose-50 flex items-center justify-center text-rose-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Secure Development</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Application secrets stay out of source control. AI-generated HTML is sanitized before being rendered to users.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monitoring & Review -->
                <div class="sec-card bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
                    <div class="flex">
                        <div class="w-1.5 bg-slate-500 flex-shrink-0 rounded-l-xl"></div>
                        <div class="p-5 flex-1">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 flex-shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-neutral-800 font-heading">Monitoring &amp; Health</h3>
                                    <p class="text-sm text-neutral-600 mt-1 leading-relaxed">Application health and error visibility are continuously monitored. Anomalies and failures are surfaced to administrators for review.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <!-- C. Fire-service standards awareness -->
        <section aria-labelledby="standards-awareness-title" class="mb-12">
            <h2 id="standards-awareness-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                Fire-service standards awareness
            </h2>
            <div class="bg-white rounded-xl border border-neutral-200 shadow-sm p-6 sm:p-7">
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-5">
                    <p class="text-sm text-amber-900 leading-relaxed">
                        <strong class="font-semibold">NFPA does not approve, certify, or endorse this software.</strong>
                        MBFD Hub is designed to support documentation and workflow alignment with applicable
                        fire-service standards, subject to the adopted code editions, departmental policies,
                        and Authority Having Jurisdiction requirements. This page is not a substitute for
                        legal, IT, AHJ, or records-retention review.
                    </p>
                </div>
                <p class="text-neutral-700 leading-relaxed">
                    MBFD Hub's implemented modules &mdash; including apparatus inspection, defects and repairs,
                    station inspections and room audits, uniform and assigned equipment, station and traveling
                    inventory, capital and under-25k projects, fire equipment requests, and structured
                    workgroup evaluations &mdash; are designed to support consistent, defensible recordkeeping
                    that aligns with concepts from the standards summarized below.
                </p>
            </div>
        </section>

        <!-- D. Standards alignment matrix -->
        <section aria-labelledby="matrix-title" class="mb-12">
            <h2 id="matrix-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 0h6m-6 0H5a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-4"></path></svg>
                Standards alignment matrix
            </h2>

            <div class="bg-white rounded-xl border border-neutral-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm">
                        <caption class="sr-only">MBFD Hub alignment with applicable fire-service standards. Rows include reference, area, alignment description, evidence module, and claim level.</caption>
                        <thead class="bg-neutral-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-neutral-600 uppercase tracking-wider">Reference</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-neutral-600 uppercase tracking-wider">Area</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-neutral-600 uppercase tracking-wider">MBFD Hub alignment</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-neutral-600 uppercase tracking-wider">Evidence (implemented module)</th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-neutral-600 uppercase tracking-wider">Claim level</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200">

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 950</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Data development &amp; exchange for the fire service</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Structured fire-service operational and administrative records that support consistent data capture and reporting.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Stations, apparatus, employees, inventory, projects &mdash; structured records with exports.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium border border-emerald-200">Implemented</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 951</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Guide to building &amp; utilizing digital information</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Department-wide digital information management across logistics, maintenance, and administrative records.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Filament admin panels for stations, apparatus, equipment, inventory, projects, requests, and reviews.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium border border-emerald-200">Implemented</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1850</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">PPE / assigned-equipment care &amp; maintenance</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Item-level tracking, assignment history, and lifecycle documentation for uniforms and assigned equipment.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Uniform &amp; assigned-equipment modules with employee assignment and request workflows.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium border border-emerald-200">Implemented</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1910</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Emergency vehicle inspection, maintenance, refurbishment, testing &amp; retirement</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Apparatus inspections, deficiency tracking, repair workflows, attachments, and status &amp; lifecycle documentation.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Apparatus, apparatus inspections, defects, defect recommendations, shop work, unit-master vehicle records.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium border border-emerald-200">Implemented</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1660</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Continuity, preparedness &amp; readiness concepts</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Station readiness, repair tracking, capital projects, and support-service documentation that support continuity-minded workflows.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Station inspections, room audits, capital projects, under-25k projects, big-ticket requests, supply requests.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-sky-50 text-sky-700 px-2.5 py-0.5 text-xs font-medium border border-sky-200">Supported where enabled</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1401</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Fire-service training reports &amp; records</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">Where training modules are enabled, structured training assignments, status, and administrative review workflows.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">Training panel with training-todo and update records.</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-amber-50 text-amber-700 px-2.5 py-0.5 text-xs font-medium border border-amber-200">Partially implemented</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1561</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Incident management &amp; command safety</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">No incident-command, accountability, or IAP modules are implemented today.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">&mdash;</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-neutral-100 text-neutral-700 px-2.5 py-0.5 text-xs font-medium border border-neutral-200">Not claimed</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1225</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Emergency services communications</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">No dispatch, CAD, station alerting, or radio-log functionality is implemented today.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">&mdash;</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-neutral-100 text-neutral-700 px-2.5 py-0.5 text-xs font-medium border border-neutral-200">Not claimed</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NFPA 1710 / 1720</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">Deployment &amp; response-time reporting</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">No turnout, travel, or arrival-time analytics are implemented today.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">&mdash;</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-neutral-100 text-neutral-700 px-2.5 py-0.5 text-xs font-medium border border-neutral-200">Not claimed</span></td>
                            </tr>

                            <tr>
                                <th scope="row" class="px-4 py-3 font-medium text-neutral-800 align-top">NERIS / NFIRS</th>
                                <td class="px-4 py-3 text-neutral-700 align-top">National incident reporting</td>
                                <td class="px-4 py-3 text-neutral-700 align-top">No incident-reporting or CAD/RMS export modules are implemented today.</td>
                                <td class="px-4 py-3 text-neutral-600 align-top">&mdash;</td>
                                <td class="px-4 py-3 align-top"><span class="inline-flex items-center rounded-full bg-neutral-100 text-neutral-700 px-2.5 py-0.5 text-xs font-medium border border-neutral-200">Not claimed</span></td>
                            </tr>

                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-neutral-500 mt-3 max-w-3xl leading-relaxed">
                Claim levels reflect the implemented feature set at publication time. Where a standard
                addresses operational response, communications, or incident-command activities outside the
                current scope, MBFD Hub makes no claim. Departmental policy, adopted code editions, and the
                Authority Having Jurisdiction govern how recordkeeping is used in practice.
            </p>
        </section>

        <!-- E. Records and audit readiness -->
        <section aria-labelledby="records-title" class="mb-12">
            <h2 id="records-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Records &amp; audit readiness
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <ul class="bg-white rounded-xl border border-neutral-200 shadow-sm p-5 space-y-3 text-sm text-neutral-700">
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Timestamped entries with the responsible user captured on creation and material updates.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Status tracking across inspections, defects, requests, projects, and supply workflows.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Notes, comments, and structured attachments (photos and documents) on the records that benefit from them.</span>
                    </li>
                </ul>

                <ul class="bg-white rounded-xl border border-neutral-200 shadow-sm p-5 space-y-3 text-sm text-neutral-700">
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Where enabled, review and approval workflows route records to the appropriate role before close-out.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Reporting and export options support administrative review without exposing operational internals.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Retention and disposition remain subject to departmental policy and applicable public-records requirements.</span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- F. Contact / responsible disclosure -->
        <section aria-labelledby="contact-title" class="mb-4">
            <h2 id="contact-title" class="text-lg font-semibold text-neutral-800 font-heading flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                Contact &amp; responsible disclosure
            </h2>
            <div class="bg-white rounded-xl border border-neutral-200 shadow-sm p-5 sm:p-6">
                <p class="text-neutral-700 leading-relaxed">
                    For security, access, or records-handling concerns related to MBFD Hub, please contact the
                    MBFD Hub administrator through official department channels. Reports made in good faith
                    are reviewed promptly; please do not share exploit details, credentials, or sensitive
                    technical information in unsolicited or public communications.
                </p>
            </div>
        </section>

    </main>

    <!-- Footer (matches landing page) -->
    <footer class="border-t border-neutral-200 bg-white/60 backdrop-blur-sm mt-4" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-neutral-400 font-medium">&copy; {{ date('Y') }} Miami Beach Fire Department</p>
            <div class="flex items-center gap-3 text-xs text-neutral-400">
                <a href="{{ url('/') }}" class="hover:text-neutral-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50 rounded-sm transition-colors">Home</a>
                <span aria-hidden="true">&bull;</span>
                <span>Secured System</span>
                <span aria-hidden="true">&bull;</span>
                <span>Support Services Division</span>
            </div>
        </div>
    </footer>

</body>
</html>
