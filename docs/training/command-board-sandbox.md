# Command Board Sandbox (Training Tools)

The Command Board Sandbox is a practice copy of the Command Board that runs entirely in the member's browser. The Hub
hosts it so every signed-in member can open it from Home, Quick Access, Training Tools, with no PIN, no second sign-in
and no approval. The live Command Board (`cmd.mbfdhub.com`) is not touched: its OIDC client, `Command300Access` and
`OidcIdentityPolicy` are unchanged and still decide who may use it.

## What was built where

| Piece | Location |
| --- | --- |
| Sandbox source and build | `pdarleyjr/mbfd-command` (static single page, Vite `base` = `/training-tools/command-board/`) |
| Page (`index.html`) and manifest | `resources/training-tools/command-board/` (not under `public/`, so only the route can serve it) |
| Content-hashed assets (JS, CSS, fonts, images) | `public/training-tools/command-board/assets/` |
| Route | `GET /training-tools/command-board`, name `training.command-board` (`routes/training-tools.php`) |
| Controller | `App\Http\Controllers\Training\CommandBoardSandboxController` |
| Bundle check at request time | `App\Support\Training\CommandBoardSandboxBundle` (config `training-tools.command_board.*`) |
| Home card | `resources/views/welcome.blade.php` (Quick Access, "Training Tools") |
| Vendoring command | `scripts/training/vendor-command-board-sandbox.mjs` (rules in `scripts/training/lib/sandbox-bundle.mjs`) |

### Why `/training-tools/command-board` and not `/training/command-board`

`/training` is the Training Filament panel (`TrainingPanelProvider`, path `training`, with its own panel middleware and
the `training.access` gate). A route under it would sit inside that panel's URL space, and a `public/training/`
folder would shadow the panel at the web server. The sandbox therefore lives at `/training-tools/...`, which collides
with nothing (`tests/Feature/TrainingTools/CommandBoardSandboxRouteTest.php` pins this). The Vite `base` in
`mbfd-command` must be exactly `/training-tools/command-board/`; the vendoring command refuses a page whose asset URLs
are anywhere else.

### Ways back to the Hub

The sandbox carries its own way out, because the page has no Hub chrome. Exit (always on its bar) and Exit Training go
to `/` (Hub Home). Training Tools goes to `/#training-tools`, the `id` on the Home Training Tools disclosure;
`resources/js/home-duty.js` opens that disclosure when the page is reached with that fragment. Each is a same-window
link or `location.assign` and asks first when there is practice work (`mbfd-command` docs/TRAINING_SANDBOX_CONTRACT.md).
The practice map is inline SVG drawn by the page, so it needs no image, font or connection allowance: the policy below
is unchanged.

## Eligibility

Same audience as the Home Quick Access card that links to it: a signed-in Hub member whose account is allowed to sign in.

* Route middleware is `CanonicalHostRedirect` then `auth:web`, the same as Home's neighbours (for example
  `/daily-roster`). `CanonicalHostRedirect` leaves an installed window on its own host, per the one-window rules.
* The `web` group then applies `EnsureCanonicalSessionIsCurrent`, which calls `User::isAuthenticationAllowed()` (account
  status must be `active`, linked personnel identity current). A disabled or pending account has its session revoked
  and is sent to sign-in. `EnforceMemberBootstrapBoundary`, `ForcePasswordChange` and `EnsureCityEmailReview` hold a member
  at their pending step exactly as they do for every other Hub page.
* Signed out: normal redirect to `/login`. No role, permission, rank, 300 assignment or `app.cmd.access` is required, and
  none is granted by opening the page.
* The Home card is visible to the same people (no extra condition in `welcome.blade.php`).

## Response and headers

The controller returns the vendored `index.html` byte for byte (nothing injected: no CSRF token, no runtime config, no
Hub navigation) with:

```
Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'none'; worker-src 'none'; manifest-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'
Cache-Control: no-store, private
X-Content-Type-Options: nosniff
Referrer-Policy: same-origin
Permissions-Policy: microphone=(), camera=(), geolocation=()
```

`App\Http\Middleware\SecurityHeaders` runs on every response. It already left an existing `Content-Security-Policy`
alone; it now also keeps a `Referrer-Policy` and a `Permissions-Policy` the response already carries (identity pages are
still forced to `no-referrer`, and everything else gets the same defaults as before). Side effect: the few
controllers that already declared `Referrer-Policy: no-referrer` themselves (mail attachments and body, narcotics check
images, fleet vehicle documents) now actually send it; before, the global default silently replaced it.

If the bundle is missing, incomplete or altered, the route answers HTTP 503 with the Hub-styled
"The Command Board Sandbox is not available right now" page (`errors.hub`, with a "Return to Hub home" link), no stack
trace, `Cache-Control: no-store, private`, and logs one warning with the reason. It does not use the closed policy
(that page is a normal Hub page). Query strings are ignored, there are no route parameters, and only `GET`/`HEAD` exist.

## Vendoring

The Hub never builds the sandbox. It vendors a finished build:

```
# in pdarleyjr/mbfd-command: build with base /training-tools/command-board/  (output: dist-training/)
node scripts/training/vendor-command-board-sandbox.mjs <path-to>/dist-training <full-40-char-mbfd-command-commit-sha>
```

Optional `--built-at <ISO date>` records the build time (default: now). The command:

1. Reads `dist-training` and checks everything before writing anything. It accepts only `index.html` and a flat
   `assets/` folder. It refuses source maps, service worker or workbox files, data files (`.json`), hidden files,
   nested folders, any other root file (so build with `manifest: false` and no copied `public/` files), an `index.html`
   with an inline script, a `<base>`, frames or forms, or asset URLs that are not plain paths under
   `/training-tools/command-board/assets/`, any asset the page refers to that is missing, and any file containing a
   forbidden marker: `/api/`, `new WebSocket(`, `.sendBeacon(` (a call; the runtime guard that replaces it is allowed), `maps.googleapis.com`, `cmd.mbfdhub.com`,
   `navigator.serviceWorker`.
2. Copies `index.html` to `resources/training-tools/command-board/` and the assets to
   `public/training-tools/command-board/assets/`, removing earlier assets that are not in the new build (only inside that
   folder; nothing else in the repository is touched).
3. Writes `resources/training-tools/command-board/SANDBOX_SOURCE.json`:
   `{repository: "pdarleyjr/mbfd-command", commit, built_at, base_path, files: [{path, sha256, bytes}]}`.
4. Re-verifies what it wrote.

`node scripts/training/vendor-command-board-sandbox.mjs --verify` re-checks a checkout at any time (it also runs as a test).
`.gitattributes` marks the vendored files byte-exact (`-text`) so the checksums hold on every checkout.

### Update procedure

1. Build in `mbfd-command`, note the commit SHA.
2. Run the vendoring command above on a branch in this repository.
3. `node --test tests/Node/command-board-sandbox-vendoring.test.mjs` and the `tests/Feature/TrainingTools` PHPUnit files.
4. Review the diff of `SANDBOX_SOURCE.json` (commit, and which files changed), open a PR, release as usual
   (the production image copies `resources/` and `public/`, nothing else is needed; no migration, no config).
5. After release, open Home, Quick Access, Training Tools, Command Board Sandbox once as an ordinary member.

An open sandbox tab from the previous build keeps working until it reloads; its old hashed files are gone, so a lazily
loaded chunk of the old build would 404. The page needs a reload after an update, which is normal for a static bundle.

### Rollback

* Remove the bundle (`git revert` the vendoring commit, or delete `resources/training-tools/command-board/` and
  `public/training-tools/command-board/assets/` in a PR). With nothing vendored the route answers 503 with the friendly
  page and the Home link leads to that page. No other feature is affected.
* To take the link off Home as well, remove the "Command Board Sandbox" row from `$trainingTools` in
  `resources/views/welcome.blade.php` (the two simulator links stay in the same card).
* There is no data to undo: the sandbox stores nothing on the server.

## Isolation guarantees and the evidence for each

| Guarantee | Layers that enforce it | Evidence |
| --- | --- | --- |
| The sandbox cannot send data anywhere | Build rules: no `/api/`, `WebSocket`, `.sendBeacon(` calls, map host or `cmd.mbfdhub.com` in any file. Browser: `connect-src 'none'` (no fetch, XHR, WebSocket, EventSource, beacon), `form-action 'none'`, `default-src 'none'`. | `tests/Node/command-board-sandbox-vendoring.test.mjs` (every marker in every file type is refused; the committed bundle is re-hashed and scanned), `CommandBoardSandboxRouteTest::test_an_ordinary_firefighter_gets_the_page...` (exact header) |
| It cannot run code that was not vendored | `script-src 'self'` (no inline script, no `eval`), `worker-src 'none'`, `base-uri 'none'`, no frames or objects (`default-src 'none'`), inline scripts refused at vendoring | vendoring test (inline script, outside script, `<base>`, form, handler cases), route test |
| It cannot be framed or embedded | `frame-ancestors 'none'` | exact-header assertion |
| It cannot register a service worker or install | `worker-src 'none'`, `manifest-src 'none'`, no worker files accepted, `navigator.serviceWorker` is a forbidden marker. The Hub's root `/sw.js` has no `fetch` handler, so it intercepts and caches nothing here. | vendoring test; `docs/pwa-service-worker-architecture.md` section 1.1 (R1, "No `fetch` handler") |
| No Command data reaches it | The page is served unmodified, with no injected context; the route never reads `Command300Access`, the `cmd` OIDC client, bid or roster data | `test_the_response_carries_no_command_data_and_grants_no_command_access` |
| Opening it grants nothing and the live board stays restricted | No permission, role or session is created; `Command300Access`, `OidcIdentityPolicy` and the OIDC client are unchanged; Home still shows the live Command Board button only to 300-eligible members | `test_a_member_without_300_access_still_cannot_reach_the_live_board...`, `HomeQuickActionsTest`, `CommandBoardLauncherTest`, `Command300AccessTest` (unchanged) |
| Only a verified bundle is served | Request-time check: manifest valid, `index.html` matches its sha256, every listed asset exists with the recorded size | `test_a_page_that_no_longer_matches_its_manifest_is_not_served`, missing/resized asset and hostile manifest tests |
| The page is never cached or fetchable by the public | `Cache-Control: no-store, private`, page lives under `resources/` (no static URL), route behind `auth:web` | route test (signed out redirects; `/training-tools/command-board/index.html` is 404) |

The assets under `public/` are reachable without signing in (they are static files served by the web server before Laravel
runs). They are content-hashed code and styles with no data, which the policy above guarantees.

## Operational notes

* Assets are served by `server.php` as plain static files: no `Cache-Control` from PHP (the edge applies its default),
  which is safe because every file name carries a content hash. `server.php` only marks `/build/assets` and `/daily/assets`
  immutable; extending that is a possible follow-up, deliberately not done here because `tests/Node/ci-configuration.test.mjs`
  pins that rule.
* Service worker: no change. `/sw.js` (scope `/`) has no `fetch` handler; the admin worker (`/admin/`) and Daily worker
  (`/daily/`) have scopes that do not include `/training-tools/`.
* CI: the Static Analysis lane runs `node --test tests/Node/*.test.mjs`, which includes the vendoring test. Gitleaks and
  Trivy scan the committed bundle like any other tracked file; minified code with long identifiers can look like a
  secret to gitleaks, so check the `Hub secret scan` job on the vendoring PR and add a reviewed `.gitleaksignore`
  fingerprint if needed. There is no bundle size budget for this path (`scripts/ci/check-video-conferencing-bundle.mjs` budgets only the
  Conference bundle, and its source-map guard looks only in `public/build` and `public/daily`; the vendoring command and
  test refuse `.map` files in this path themselves). `scripts/ci/guard-generated-assets.mjs` fails on tracked files under
  `public/build`, `public/daily` and Filament output, not `public/training-tools`, so the vendored files are tracked on purpose.
