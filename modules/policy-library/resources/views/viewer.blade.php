<!doctype html>
<html lang="en">
<head>
    @php
        $viewerScript = public_path('vendor/policy-library/viewer.js');
        $viewerStyle = public_path('vendor/policy-library/viewer.css');
        $viewerScriptVersion = is_file($viewerScript) ? hash_file('sha256', $viewerScript) : '';
        $viewerStyleVersion = is_file($viewerStyle) ? hash_file('sha256', $viewerStyle) : '';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Policy &amp; Protocol Library · MBFD</title>
    <link rel="icon" type="image/png" href="{{ asset('vendor/policy-library/images/mbfd-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/policy-library/viewer.css') }}?v={{ $viewerStyleVersion }}">
    <script type="module" src="{{ asset('vendor/policy-library/viewer.js') }}?v={{ $viewerScriptVersion }}"></script>
</head>
<body class="library-body">
    <a class="skip-link" href="#document-workspace">Skip to document</a>
    <header class="library-header">
        <button type="button" id="menu-toggle" class="icon-button" aria-label="Toggle manual navigation" aria-expanded="false" aria-controls="manual-sidebar">☰</button>
        <img src="{{ asset('vendor/policy-library/images/mbfd-logo.png') }}" alt="Miami Beach Fire Rescue" width="48" height="48">
        <div class="brand"><span>MIAMI BEACH FIRE RESCUE</span><h1>Policy &amp; Protocol Library</h1></div>
        <a id="manage-link" class="quiet-link" href="/manage" hidden>Manage library</a>
    </header>
    <div class="library-layout">
        <button id="drawer-shade" class="drawer-shade" aria-label="Close manual navigation" hidden></button>
        <aside id="manual-sidebar" class="manual-sidebar" aria-label="Manual navigation">
            <div class="sidebar-heading"><span class="eyebrow">DEPARTMENT MANUALS</span><button id="drawer-close" type="button" class="icon-button mobile-menu" aria-label="Close manual navigation">×</button></div>
            <div id="manual-select" class="manual-select" aria-label="Select a manual"></div>
            <form id="page-search-form" class="page-search-form" role="search">
                <label for="page-search" class="search-label">Search this manual</label>
                <div class="page-search-input"><input id="page-search" type="search" placeholder="Find words in pages…" minlength="2" maxlength="160" required autocomplete="off"><button type="submit" aria-label="Search published pages">→</button></div>
                <label class="search-scope"><input id="search-all-manuals" type="checkbox"> All manuals</label>
            </form>
            <div class="rail-tabs" aria-label="Manual views"><button id="contents-toggle" type="button" aria-pressed="true">Contents</button><button id="recent-toggle" type="button" aria-pressed="false">Recently updated</button></div>
            <div id="contents-panel" class="rail-panel">
                <label for="title-search" class="sr-only">Filter document titles</label>
                <input id="title-search" type="search" class="title-search" placeholder="Filter titles…" autocomplete="off">
                <nav id="manual-tree" aria-label="Sections, documents, and pages" aria-busy="true"><p class="sidebar-message">Loading manuals…</p></nav>
            </div>
            <section id="recent-panel" class="rail-panel" aria-label="Recently published documents" hidden><p class="sidebar-message recent-intro">Latest publications in this manual.</p><div id="recent-documents"></div></section>
            <section id="results-panel" class="rail-panel" aria-label="Page search results" hidden><button id="results-back" type="button" class="results-back">← Back to contents</button><p id="search-status" class="sidebar-message" role="status"></p><div id="search-results" aria-busy="false"></div></section>
            <p class="sidebar-footnote">MBFD · Original department documents</p>
        </aside>
        <main id="document-workspace" class="document-workspace" tabindex="-1">
            <div class="document-heading"><div class="document-heading-text"><p id="document-path" class="eyebrow">MBFD LIBRARY</p><h2 id="document-title">Your department manuals</h2><p id="revision-info" class="revision-info">Choose a policy or protocol to begin.</p></div><div class="page-share"><a id="download-pdf" class="view-button" hidden>Download PDF</a><button id="copy-link" type="button" class="view-button" disabled>Copy link</button><span id="copy-status" class="copy-status" role="status"></span><input id="copy-link-value" aria-label="Current page link" type="text" readonly hidden></div></div>
            <div class="viewer-toolbar" aria-label="Document controls">
                <div class="page-controls"><button type="button" data-nav="previous" class="nav-button" disabled aria-label="Previous page">← <span>Previous</span></button><span class="page-status" data-page-status>—</span><button type="button" data-nav="next" class="nav-button" disabled aria-label="Next page"><span>Next</span> →</button></div>
                <div class="view-controls"><button type="button" id="fit-page" class="view-button" aria-pressed="true">Fit page</button><button type="button" id="fit-width" class="view-button" aria-pressed="false">Fit width</button><span class="control-divider"></span><button type="button" id="zoom-out" class="icon-button" aria-label="Zoom out">−</button><output id="zoom-value" aria-label="Zoom level">100%</output><button type="button" id="zoom-in" class="icon-button" aria-label="Zoom in">+</button><button type="button" id="focus-mode" class="view-button" aria-pressed="false">Focus view</button></div>
            </div>
            <section id="page-stage" class="page-stage" aria-label="PDF page">
                <div id="viewer-message" class="viewer-message" role="status"><span class="document-symbol" aria-hidden="true">▤</span><h3>Ready when you need it</h3><p>Select SOGs or Medical Protocols, then choose a section.</p></div>
                <div id="pdf-page" class="pdf-page" hidden><canvas id="pdf-canvas" aria-label="Original PDF document page"></canvas><div id="pdf-text" class="textLayer"></div><div id="pdf-links" class="pdf-links" aria-label="Document links"></div></div>
            </section>
            <footer class="bottom-toolbar" aria-label="Bottom page controls"><button type="button" data-nav="previous" class="nav-button" disabled aria-label="Previous page">← Previous</button><span class="page-status" data-page-status>—</span><button type="button" data-nav="next" class="nav-button" disabled aria-label="Next page">Next →</button></footer>
            <p id="page-announcement" class="sr-only" aria-live="polite"></p>
        </main>
    </div>
</body>
</html>
