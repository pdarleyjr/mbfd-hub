<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
    :root { color-scheme: light; font-family: "Plus Jakarta Sans", Arial, sans-serif; --hub-header: #102a43; --hub-elevated: #173a5e; --hub-blue: #1e4e8c; --hub-red: #dc2626; --hub-red-strong: #b91c1c; --hub-canvas: #f7fafc; --hub-surface: #ffffff; --hub-surface-muted: #f1f5f9; --hub-border: #cbd5e1; --hub-ink: #172033; --hub-muted: #526072; --hub-focus: #60a5fa; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; padding: clamp(1rem, 4vw, 3rem); background: linear-gradient(180deg, var(--hub-header) 0, var(--hub-header) 10rem, var(--hub-canvas) 10rem); color: var(--hub-ink); }
    main { width: min(100%, 40rem); margin: 0 auto; padding: clamp(1.25rem, 5vw, 2.5rem); border: 1px solid var(--hub-border); border-radius: .75rem; background: var(--hub-surface); box-shadow: 0 1rem 3rem rgba(16, 42, 67, .14); }
    h1 { margin: .4rem 0 1rem; font-size: clamp(1.5rem, 5vw, 2rem); line-height: 1.2; }
    h2 { margin-top: 1.5rem; font-size: 1.15rem; }
    p { color: var(--hub-muted); line-height: 1.5; }
    a { color: var(--hub-blue); overflow-wrap: anywhere; }
    label { display: block; margin-top: 1rem; font-weight: 700; }
    input:not([type=checkbox]) { width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid #64748b; border-radius: .4rem; background: var(--hub-surface); color: var(--hub-ink); font: inherit; }
    input:focus, button:focus-visible, a:focus-visible { outline: 3px solid var(--hub-focus); outline-offset: 2px; }
    .checkbox { display: flex; gap: .65rem; align-items: flex-start; line-height: 1.5; font-weight: 400; }
    .checkbox input { width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: .15rem; }
    button, .identity-button { display: block; width: 100%; min-height: 44px; margin-top: 1rem; padding: .85rem; border: 0; border-radius: .4rem; background: var(--hub-red); color: white; font: inherit; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
    button:hover, .identity-button:hover { background: var(--hub-red-strong); }
    .secondary, .cancel { background: var(--hub-surface-muted); color: var(--hub-ink); border: 1px solid var(--hub-border); }
    .error { color: var(--hub-red-strong); font-weight: 700; }
    .notice { padding: 1rem; border-left: 4px solid var(--hub-blue); background: #eff6ff; line-height: 1.5; overflow-wrap: anywhere; }
    .status { display: inline-block; padding: .25rem .5rem; border-radius: .3rem; background: #fef3c7; color: #713f12; font-size: .9rem; font-weight: 700; }
    .verified { background: #dcfce7; color: #14532d; }
    .detail { overflow-wrap: anywhere; }
    .identity { padding: .8rem; border: 1px solid var(--hub-border); border-radius: .5rem; background: var(--hub-surface-muted); }
    .identity-strip { margin: -1.25rem -1.25rem 1.5rem; padding: .75rem 1.25rem; border-radius: .75rem .75rem 0 0; background: var(--hub-header); color: white; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0 0; color: var(--hub-muted); font-size: .8rem; }
    .divider::before, .divider::after { content: ''; height: 1px; flex: 1; background: var(--hub-border); }
    .help { display: block; margin-top: 1rem; text-align: center; color: var(--hub-blue); }
    .small { font-size: .9rem; }
    details { margin-top: 1.5rem; border-top: 1px solid #cbd5e1; padding-top: 1rem; }
    summary { padding: .5rem 0; cursor: pointer; font-weight: 700; }
    @media (min-width: 30rem) { .identity-strip { margin: -2.5rem -2.5rem 1.5rem; padding-inline: 2.5rem; } }
</style>
