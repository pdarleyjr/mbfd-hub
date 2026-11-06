# Claude tooling inventory, 2026-11-06

Machine: Windows 11, user profile `C:\Users\Peter Darley`. Node 22.22.3, npm 11.4.1, Python 3.13.1
(`C:\Users\Peter Darley\AppData\Local\Programs\Python\Python313\python.exe`; `C:\Windows\System32\python` and the
WindowsApps `python.exe` are aliases, do not use them). Claude Code CLI on PATH: **2.1.98** (npm latest 2.1.318).

Nothing was uninstalled or deleted. MCP env values, headers and tokens were not read into this report.

## Toolchain verification

| Tool | Installed | Latest upstream | Install location | Smoke test | Result |
|---|---|---|---|---|---|
| Impeccable | skill 4.8.0, engine 0.1.18 | npm 4.8.0; repo `c3bb418` (2026-11-05) | `~/.claude/skills/impeccable` + 4 agents `~/.claude/agents/impeccable-*.md` + 2 hooks in `~/.claude/settings.json` | `npx impeccable@4.8.0 detect C:\mbfd-ui-modernization\resources\views\components\hub` | PASS: 11 files scanned, 0 findings, exit 0 (Blade = regex rules only; scan a URL for contrast and tiny-text rules) |
| Open Design Agent Kit | plugin `open-design@open-design-agent-kit` 0.3.5, MCP server 0.3.0 | marketplace 0.3.5 (`995caa5`, 2026-11-06); npm MCP 0.3.0 | `~/.claude/plugins/cache/open-design-agent-kit/open-design/0.3.5` (user scope) | stdio MCP `initialize`, `tools/list`, `tools/call list_open_design_skills` | PASS: server `open-design 0.3.0`, 34 tools, `list_open_design_skills` returned 488 entries |
| Anthropic frontend-design | `frontend-design@claude-plugins-official` 1.1.1 | marketplace 1.1.1 (catalog `a1ecfbc7`, refreshed 2026-11-06) | `~/.claude/plugins/cache/claude-plugins-official/frontend-design/1.1.1` | `claude plugin list` + SKILL.md hash vs marketplace | PASS: up to date, hash identical; loaded in session as `frontend-design:frontend-design`. CLI 2.1.98 wrongly shows "failed to load" (see FIX) |
| UI/UX Pro Max | 2.15.0 (7 skills) | npm `ui-ux-pro-max-cli` 2.15.0 | `~/.claude/skills/{ui-ux-pro-max,design,design-system,ui-styling,brand,banner-design,slides}` | `& $PY -E -s ~/.claude/skills/ui-ux-pro-max/scripts/search.py "enterprise dashboard public safety"` (+ `--design-system`) | PASS: exit 0, 3 style results (top: Data-Dense Dashboard); design-system mode exit 0 |
| Vercel web-design-guidelines | 1.0.0 | agent-skills `1a2c79d` (2026-10-27) | `~/.claude/skills/web-design-guidelines/SKILL.md` (copied, not symlinked) | `npx skills@1.7.6 add vercel-labs/agent-skills --skill web-design-guidelines -a claude-code -g --copy -y` | PASS: SKILL.md 1231 B, valid frontmatter, listed in session skills |
| Playwright MCP | plugin `playwright@claude-plugins-official` (`a1ecfbc7`), runs `npx @playwright/mcp@latest` = 0.0.84 | npm `@playwright/mcp` 0.0.84 | plugin `.mcp.json`; npx cache | `browser_navigate` to a `data:` page, then `browser_close` | PASS: page title `pw-smoke` returned, tab closed |
| interface-design (project) | copy of `.agents/skills/interface-design/SKILL.md` | n/a | `C:\mbfd-ui-modernization\.claude\skills\interface-design\SKILL.md` | frontmatter check | PASS: `name` + `description` valid; reference links rewritten to `.agents/skills/interface-design/references/*`. Note: `/.claude/` is gitignored in this repo, so the copy is local-only |

`$PY` = the Python 3.13 path above. Do not run `search.py` with `-I`: it drops the script directory from
`sys.path` and the import of `core` fails.

### Impeccable details

- Old per-command skill set (20 folders + Impeccable's old `frontend-design`) backed up to
  `~/.claude/backups/impeccable-pre-update-20261106/` (21 folders, plus `settings.json.bak` and
  `settings.json.after-install`). The old folders were **left in place** (no-delete rule), so they still load.
- Current upstream ships **one** skill, `impeccable`, with 24 sub-commands:
  `shape, critique, audit, polish, bolder, quieter, distill, colorize, typeset, layout, animate, delight,
  overdrive, harden, clarify, adapt, optimize, onboard, live, generate, init, document, extract` (+ deprecated
  `craft`). Invoke as `/impeccable <command> <target>`; `/impeccable pin audit` makes `/audit` a shortcut.
  `teach-impeccable` is now `/impeccable init` (`teach` is an alias; writes `PRODUCT.md`). Old `arrange` and
  `normalize` have no command of the same name; the nearest are `layout` and `extract`/`document`.
- Agents added: `impeccable-asset-producer`, `impeccable-documenter`, `impeccable-finish-reviewer`,
  `impeccable-manual-edit-applier`.
- Hooks (one launcher `skills/impeccable/scripts/impeccable hook`):
  - SessionStart, 5 s: prepares a session. **Enabled.**
  - PostToolUse `Edit|Write`, 5 s: scans the edited UI file and returns findings as `additionalContext`
    (never `decision: block`, no exit 2). **Enabled.**
  - Stop, 30 s "design deep pass": re-enters the turn with findings (a completion gate). **Removed** from
    `~/.claude/settings.json`; `npx impeccable update` will add it back, so re-check after updates.
- Telemetry: the skill fetches visual directions from impeccable.style on new designs; turn off with
  `npx impeccable telemetry off` or `DO_NOT_TRACK=1` if wanted. Detector: 62 rules (`detect --list-rules`).

### Open Design details

- Marketplace `open-design-agent-kit` (github `feimacode/open-design-agent-kit`) added at user scope; plugin enabled.
- 43 skills: `open-design`, `open-design-{new,campaign,critique,deck-from-source,design-system-from-url,docs,explore,
  integrations,poster,preview,publish,recurring,redesign,site-audit,social-post}` and 27 explicit `od-*` templates.
- Fix applied: CLI 2.1.98 did not install the plugin's locked dependency and the npx fallback cache was
  incomplete (`MODULE_NOT_FOUND` in `@modelcontextprotocol/sdk`). Ran `npm ci --omit=dev` in the plugin folder
  (the step Claude Code normally does at install). MCP tools appear as `mcp__plugin_open-design_open-design__*`
  after a Claude Code restart.
- Paid/external tools to avoid unless asked: `publish_open_design_artifact_to_canva`, `..._to_shop`,
  `push_open_design_artifact_to_figma`, `publish_open_design_artifact`.

## Skills

| Group | Location | Count | Description bytes per session | Class |
|---|---|---:|---:|---|
| Impeccable 4.8.0 | `~/.claude/skills/impeccable` | 1 | 589 | KEEP ACTIVE |
| Impeccable pre-4.x (adapt ... typeset, teach-impeccable, old frontend-design) | `~/.claude/skills` | 21 | 5,390 | CLEANUP CANDIDATE (superseded, backed up) |
| UI/UX Pro Max | `~/.claude/skills` | 7 | 2,692 | KEEP ON DEMAND (`ui-ux-pro-max` active; `design`, `brand`, `banner-design`, `slides` rarely relevant) |
| web-design-guidelines | `~/.claude/skills` | 1 | 184 | KEEP ACTIVE |
| anti-slop-ship, excel-workflow | `~/.claude/skills` | 2 | ~700 | KEEP ACTIVE |
| ECC copies duplicated in `~/.claude/skills` | `~/.claude/skills` | 47 | 6,999 | CLEANUP CANDIDATE (same skills ship in the ECC plugin) |
| `learned` (empty folder, no SKILL.md) | `~/.claude/skills/learned` | 0 | 0 | KEEP (ECC learning output dir) |
| everything-claude-code plugin | plugin 1.9.0 (marketplace has 2.0.0-rc.1) | 125 | 23,623 | KEEP ON DEMAND; trim unused language packs |
| open-design plugin | plugin 0.3.5 | 43 | 9,033 | KEEP ON DEMAND (disable between design sessions) |
| superpowers | plugin 7.0.0 | 15 | 2,317 | KEEP ACTIVE |
| frontend-design, skill-creator, claude-md-management | plugins | 3 | 861 | KEEP ACTIVE |
| Project: interface-design | `C:\mbfd-ui-modernization\.claude\skills` | 1 | ~180 | KEEP ACTIVE |

Measured total: 78 user skills = 16,314 B of descriptions; 186 enabled plugin skills = 35,834 B. About
**52 KB (~13k tokens) of skill descriptions per session**, before plugin commands (ECC: 60) and agent
descriptions. Removing the two cleanup groups saves ~12.4 KB (~3k tokens) with no loss of capability.

Name overlaps to know about: `design-system` (UI/UX Pro Max user skill) vs `everything-claude-code:design-system`;
`frontend-design` (old Impeccable copy) vs `frontend-design:frontend-design` (Anthropic);
`audit`/`polish`/`critique` etc. (old Impeccable) vs `/impeccable audit` etc. and `design:design-critique`.

## Plugins (`~/.claude/plugins/installed_plugins.json`, all user scope, all enabled)

| Plugin | Version | Adds | Class |
|---|---|---|---|
| everything-claude-code | 1.9.0 | 125 skills, 60 commands, 28 agents, 28 hook entries | KEEP ON DEMAND (heaviest; see duplicates) |
| superpowers | 7.0.0 | 15 skills, SessionStart hook | KEEP ACTIVE |
| frontend-design | 1.1.1 | 1 skill | KEEP ACTIVE |
| playwright | a1ecfbc7 | Playwright MCP (stdio) | KEEP ACTIVE |
| context7 | a1ecfbc7 | Context7 MCP (http, needs /mcp auth) | FIX (authorize) |
| github | a1ecfbc7 | GitHub MCP (http) | CLEANUP CANDIDATE (duplicates user `github` stdio MCP and `gh`) |
| feature-dev, code-review | 1.0.1 | 1 command each, 3 agents | KEEP ON DEMAND |
| typescript-lsp | 1.0.0 | LSP | KEEP ACTIVE |
| skill-creator, claude-md-management | a1ecfbc7 / 1.0.0 | 1 skill each | KEEP ON DEMAND |
| security-guidance | 2.0.17 | 7 hook entries | KEEP ACTIVE |
| open-design | 0.3.5 | 43 skills, 1 agent, MCP (stdio) | KEEP ON DEMAND |

Desktop-app plugins seen in this session but not in `installed_plugins.json` (managed by the app):
`engineering`, `data`, `design`, `productivity`, `pdf-viewer`, `desktop-commander`, `anthropic-skills`,
`modern-web-guidance`, `github-cli-health-check`, `cowork-plugin-management`.

## MCP servers (`~/.claude.json`, parsed with node `JSON.parse`; names and transport only)

| Server | Type | Status this session | Class |
|---|---|---|---|
| github | stdio (npx) | connected | KEEP ACTIVE |
| memory, sequential-thinking | stdio | connected | KEEP ON DEMAND |
| firecrawl | stdio | connected | KEEP ACTIVE |
| sentry | stdio | connected | KEEP ACTIVE |
| composio | http | needs /mcp auth | FIX or CLEANUP CANDIDATE |
| e2b | stdio | connected | KEEP ON DEMAND |
| cloudflare-workers | stdio (`@cloudflare/mcp-server-cloudflare`) | **failed: connection closed** | FIX (switch to the hosted Cloudflare API MCP; the bindings server reports itself deprecated) |
| stitch | http | connected | KEEP ON DEMAND |
| svgmaker | stdio | connected | KEEP ON DEMAND |
| magic-ui | stdio (`@21st-dev/magic` 0.2.3) | **failed: API key missing or reset** | FIX (new key from 21st.dev) |
| ui-expert | stdio (github source) | connected | CLEANUP CANDIDATE (overlaps Impeccable + UI/UX Pro Max) |
| a11y | stdio | connected | KEEP ACTIVE |
| web-mcp (Bright Data) | stdio | connected | KEEP ON DEMAND (has its own scraping browser) |
| nutrient-dws | stdio | connected | KEEP ON DEMAND |
| excel-mcp | stdio | **failed: connection closed** | FIX |
| laravel-boost | stdio over ssh | **failed: connection closed** | FIX (ssh target/key) |
| ruflo | stdio (local shim) | connected, ~300 deferred tools | KEEP ON DEMAND |
| Project `fltf2portal`: laravel-boost, mcp-pdf, mcp-pdf-forms | stdio | n/a here | KEEP |
| plugin:data:definite | http | **failed: endpoint not found** | FIX or disable in the data plugin |

`~/.claude.json` has 29 project keys; `D:/GitHub_Repos/mbfd_hub` appears twice with different casing
(JSON.parse keeps the last one; ConvertFrom-Json would fail). CLEANUP CANDIDATE, merge by hand while Claude is closed.

Browser automation overlap: Playwright plugin MCP, built-in Claude Browser pane, claude-in-chrome, computer-use,
Bright Data `scraping_browser_*`, ruflo `browser_*`, plus a Codex `playwright-mcp` process. Keep Playwright MCP
(tests) and the built-in Browser pane (previews); treat the rest as on-demand.

## Hooks

| Source | Events | Class |
|---|---|---|
| `~/.claude/settings.json` (Impeccable) | SessionStart 1, PostToolUse `Edit\|Write` 1 | KEEP ACTIVE (non-blocking, 5 s) |
| everything-claude-code plugin | PreToolUse 11, PostToolUse 8, Stop 5, PreCompact, SessionStart, SessionEnd, PostToolUseFailure | KEEP ON DEMAND; review the 5 Stop hooks for latency |
| security-guidance plugin | SessionStart, UserPromptSubmit, PostToolUse 4, Stop 1 | KEEP ACTIVE |
| superpowers plugin | SessionStart 1 | KEEP ACTIVE |

## Custom agents (`~/.claude/agents`, 32 files)

28 files (`architect` ... `typescript-reviewer`) are identical names to the ECC plugin's 28 agents, so each
appears twice in the agent list (`planner` and `everything-claude-code:planner`). CLEANUP CANDIDATE: keep one copy.
4 new `impeccable-*` agents: KEEP ACTIVE.

## FIX items

1. **Claude Code CLI 2.1.98 is stale** (latest 2.1.318). `claude plugin list` reports every
   `claude-plugins-official` plugin as "not found in marketplace" although the desktop app loads them, and it
   skipped the Open Design dependency install. Update with `npm i -g @anthropic-ai/claude-code@latest` when convenient.
2. magic-ui: re-key. cloudflare-workers: replace with the hosted Cloudflare MCP. excel-mcp, laravel-boost: check
   the launch command or ssh target. definite: fix the URL or disable. context7 and composio: authorize via `/mcp`.
3. After any `npx impeccable update`, remove the re-added Stop hook if it is still unwanted.

## CLEANUP CANDIDATES (not done; owner decision)

1. 21 pre-4.x Impeccable skill folders in `~/.claude/skills` (backed up). Saves 5.4 KB per session and
   removes duplicate `audit`/`polish`/`frontend-design` triggers.
2. 47 ECC skills copied into `~/.claude/skills` that the ECC plugin already ships. Saves 7.0 KB per session.
3. 28 duplicate agents in `~/.claude/agents` vs the ECC plugin.
4. `github@claude-plugins-official` (http) vs user `github` MCP (stdio).
5. `ui-expert` MCP (overlaps the design skills).
6. Duplicate-cased `D:/GitHub_Repos/mbfd_hub` project key in `~/.claude.json`.
7. ECC language packs not used here (cpp, go, kotlin, java/spring, perl, rust, swift, django): biggest
   share of the 23.6 KB ECC description load.
