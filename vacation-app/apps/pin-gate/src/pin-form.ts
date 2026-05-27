/**
 * Self-contained PIN entry page. No external fonts/CSS; the worker serves
 * this byte-identical every time.
 */
function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function renderPinForm(opts: { error?: string } = {}): string {
  const error = opts.error ? `<p class="err">${escapeHtml(opts.error)}</p>` : '';
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />
<meta name="robots" content="noindex,nofollow" />
<title>MBFD Vacation — PIN required</title>
<style>
  :root { color-scheme: light; }
  *,*::before,*::after { box-sizing: border-box; }
  html,body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", sans-serif;
    background: linear-gradient(180deg, #FAFAF9 0%, #F5F5F4 100%);
    color: #292524;
    display: flex; align-items: center; justify-content: center;
    padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  }
  .card {
    width: min(420px, 92vw);
    background: #fff;
    border: 1px solid #E7E5E3;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    overflow: hidden;
  }
  .top {
    background: #1e293b; color: #fff;
    padding: 14px 20px;
    display: flex; align-items: center; gap: 10px;
  }
  .top strong { font-weight: 700; font-size: 16px; letter-spacing: -0.01em; }
  .top span.pill {
    background: #B91C1C; color: #fff;
    padding: 2px 8px; border-radius: 6px;
    font-size: 10px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
  }
  .body { padding: 24px 20px 20px; }
  h1 { margin: 0 0 6px; font-size: 18px; }
  p { margin: 0 0 16px; color: #78716C; font-size: 14px; line-height: 1.5; }
  label { display: block; font-size: 12px; font-weight: 700; color: #78716C; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
  input[type="password"] {
    width: 100%; height: 48px; padding: 0 14px;
    border: 1px solid #E7E5E3; border-radius: 8px;
    font-size: 18px; font-variant-numeric: tabular-nums;
    letter-spacing: 0.1em; outline: none;
  }
  input[type="password"]:focus { border-color: #B91C1C; box-shadow: 0 0 0 3px rgba(185,28,28,0.15); }
  button {
    margin-top: 16px; width: 100%; height: 48px;
    background: #B91C1C; color: #fff; border: none; border-radius: 8px;
    font-weight: 600; font-size: 15px; cursor: pointer;
  }
  button:hover { background: #DC2626; }
  .err {
    background: #FEF2F2; color: #B91C1C;
    border: 1px solid rgba(185,28,28,0.2);
    padding: 8px 12px; border-radius: 6px; font-size: 13px; margin: 0 0 12px;
  }
  .foot { padding: 10px 20px; background: #F5F5F4; color: #78716C; font-size: 11px; border-top: 1px solid #E7E5E3; }
</style>
</head>
<body>
  <main class="card" role="main">
    <div class="top">
      <strong>MBFD Vacation</strong>
      <span class="pill">PIN required</span>
    </div>
    <div class="body">
      <h1>Department access</h1>
      <p>Enter the shared department PIN to view the vacation board.</p>
      ${error}
      <form method="POST" action="/__pin/submit" autocomplete="off">
        <label for="pin">PIN</label>
        <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="12" autofocus required />
        <button type="submit">Continue</button>
      </form>
    </div>
    <div class="foot">Miami Beach Fire Department — internal. Do not share.</div>
  </main>
</body>
</html>`;
}
