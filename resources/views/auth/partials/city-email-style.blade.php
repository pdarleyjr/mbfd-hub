@vite('resources/css/app.css')
<style>
    :root { color-scheme: light; font-family: var(--hub-font-sans); }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; padding: clamp(1rem, 4vw, 3rem); background: linear-gradient(180deg, rgb(var(--hub-header)) 0, rgb(var(--hub-header)) 10rem, rgb(var(--hub-canvas)) 10rem); color: rgb(var(--hub-ink)); }
    main { width: min(100%, 40rem); margin: 0 auto; padding: clamp(1.25rem, 5vw, 2.5rem); border: 1px solid rgb(var(--hub-border)); border-radius: .75rem; background: rgb(var(--hub-surface)); box-shadow: 0 1rem 3rem rgba(16, 42, 67, .14); }
    h1 { font-weight: 700; margin: .4rem 0 1rem; font-size: clamp(1.5rem, 5vw, 2rem); line-height: 1.2; }
    h2 { font-weight: 700; margin-top: 1.5rem; font-size: 1.15rem; }
    p { margin-block: 1rem; color: rgb(var(--hub-muted)); line-height: 1.5; }
    a { color: rgb(var(--hub-blue)); overflow-wrap: anywhere; }
    ul { margin-block: 1rem; padding-inline-start: 1.5rem; list-style: disc; }
    label { display: block; margin-top: 1rem; font-weight: 700; }
    input:not([type=checkbox]) { width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid rgb(var(--hub-control-border)); border-radius: .4rem; background: rgb(var(--hub-surface)); color: rgb(var(--hub-ink)); font: inherit; }
    input:focus, button:focus-visible, a:focus-visible { outline: 3px solid rgb(var(--hub-focus)); outline-offset: 2px; }
    .checkbox { display: flex; gap: .65rem; align-items: flex-start; line-height: 1.5; font-weight: 400; }
    .checkbox input { width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: .15rem; }
    button, .identity-button { display: block; width: 100%; min-height: 44px; margin-top: 1rem; padding: .85rem; border: 0; border-radius: .4rem; background: rgb(var(--hub-action-primary)); color: white; font: inherit; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
    button:hover, .identity-button:hover { background: rgb(var(--hub-action-primary-hover)); }
    .secondary, .cancel { background: rgb(var(--hub-surface-muted)); color: rgb(var(--hub-ink)); border: 1px solid rgb(var(--hub-border)); }
    .secondary:hover, .cancel:hover { background: rgb(var(--hub-border-soft)); }
    .error { color: rgb(var(--hub-red-strong)); font-weight: 700; }
    .notice { padding: 1rem; border-left: 4px solid rgb(var(--hub-blue)); background: rgb(var(--hub-blue) / .08); line-height: 1.5; overflow-wrap: anywhere; }
    .status { display: inline-block; padding: .25rem .5rem; border-radius: .3rem; background: rgb(var(--hub-warning) / .12); color: rgb(var(--hub-warning)); font-size: .9rem; font-weight: 700; }
    .verified { background: rgb(var(--hub-success) / .1); color: rgb(var(--hub-success)); }
    .detail { overflow-wrap: anywhere; }
    .identity { padding: .8rem; border: 1px solid rgb(var(--hub-border)); border-radius: .5rem; background: rgb(var(--hub-surface-muted)); }
    .identity-strip { margin: -1.25rem -1.25rem 1.5rem; padding: .75rem 1.25rem; border-radius: .75rem .75rem 0 0; background: rgb(var(--hub-header)); color: white; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0 0; color: rgb(var(--hub-muted)); font-size: .8rem; }
    .divider::before, .divider::after { content: ''; height: 1px; flex: 1; background: rgb(var(--hub-border)); }
    .help { display: block; margin-top: 1rem; text-align: center; color: rgb(var(--hub-blue)); }
    .small { font-size: .9rem; }
    details { margin-top: 1.5rem; border-top: 1px solid rgb(var(--hub-border)); padding-top: 1rem; }
    summary { padding: .5rem 0; cursor: pointer; font-weight: 700; }
    @media (min-width: 30rem) { .identity-strip { margin: -2.5rem -2.5rem 1.5rem; padding-inline: 2.5rem; } }
</style>
