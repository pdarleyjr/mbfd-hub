<style>
    :root { color-scheme: light; font-family: Arial, sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; padding: clamp(1rem, 4vw, 3rem); background: #f1f5f9; color: #172033; }
    main { width: min(100%, 40rem); margin: 0 auto; padding: clamp(1.25rem, 5vw, 2.5rem); border-radius: .75rem; background: white; box-shadow: 0 1rem 3rem rgba(15, 23, 42, .12); }
    h1 { margin: .4rem 0 1rem; font-size: clamp(1.5rem, 5vw, 2rem); line-height: 1.2; }
    h2 { margin-top: 1.5rem; font-size: 1.15rem; }
    p { color: #475569; line-height: 1.5; }
    a { color: #1d4ed8; overflow-wrap: anywhere; }
    label { display: block; margin-top: 1rem; font-weight: 700; }
    input:not([type=checkbox]) { width: 100%; min-height: 44px; margin-top: .4rem; padding: .75rem; border: 1px solid #64748b; border-radius: .4rem; font: inherit; }
    input:focus, button:focus-visible, a:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
    .checkbox { display: flex; gap: .65rem; align-items: flex-start; line-height: 1.5; font-weight: 400; }
    .checkbox input { width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: .15rem; }
    button { width: 100%; min-height: 44px; margin-top: 1rem; padding: .85rem; border: 0; border-radius: .4rem; background: #b91c1c; color: white; font: inherit; font-weight: 700; cursor: pointer; }
    .secondary { background: #e2e8f0; color: #172033; }
    .error { color: #b91c1c; font-weight: 700; }
    .notice { padding: 1rem; border-left: 4px solid #1d4ed8; background: #eff6ff; line-height: 1.5; overflow-wrap: anywhere; }
    .status { display: inline-block; padding: .25rem .5rem; border-radius: .3rem; background: #fef3c7; color: #713f12; font-size: .9rem; font-weight: 700; }
    .verified { background: #dcfce7; color: #14532d; }
    .detail { overflow-wrap: anywhere; }
    .small { font-size: .9rem; }
    details { margin-top: 1.5rem; border-top: 1px solid #cbd5e1; padding-top: 1rem; }
    summary { padding: .5rem 0; cursor: pointer; font-weight: 700; }
</style>
