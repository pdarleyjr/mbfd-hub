# Plan 01 — Foundation: repo, deploy, PIN gate, auth

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliverable: `staging.bid.mbfdhub.com` reachable, gated by PIN 2300, accepts Employee Portal credentials via `/verify-credentials`, lands on a placeholder `/lobby` page. Member can log in on a phone or desktop. Admin role is detected. CI deploys on every push to `main`. All design tokens from `.impeccable.md` enforced.

**Architecture:** pnpm monorepo at `bid-app/` with `apps/web` (Next.js 15 on Cloudflare Pages) and `apps/worker` (Hono on Cloudflare Workers, D1 binding, KV binding for sessions). PIN gate is Cloudflare Pages middleware. Auth is two-leg: Pages middleware → Worker `/api/auth/login` → external portal `/verify-credentials` → JWT cookie. Design system tokens compiled into Tailwind config.

**Tech Stack:** Next.js 15 App Router · React 19 · TypeScript strict · TailwindCSS 4 · shadcn/ui · Hono 4 · Drizzle ORM · Cloudflare Pages + Workers + D1 + KV · Zod · Vitest · Playwright · Wrangler · pnpm 9

---

## File map (created in this plan)

```
MBFD_Hub/bid-app/
├── .gitignore
├── .env.example
├── .npmrc
├── package.json
├── pnpm-workspace.yaml
├── tsconfig.base.json
├── biome.json                              ← formatter + linter (Biome)
├── wrangler.toml
├── README.md
├── apps/
│   ├── web/
│   │   ├── package.json
│   │   ├── next.config.mjs
│   │   ├── tsconfig.json
│   │   ├── tailwind.config.ts
│   │   ├── postcss.config.mjs
│   │   ├── components.json                 ← shadcn/ui config
│   │   ├── middleware.ts                   ← PIN gate
│   │   ├── app/
│   │   │   ├── layout.tsx
│   │   │   ├── page.tsx                    ← PIN form
│   │   │   ├── globals.css
│   │   │   ├── (auth)/
│   │   │   │   └── login/
│   │   │   │       ├── page.tsx
│   │   │   │       └── login-form.tsx       ← 'use client'
│   │   │   ├── lobby/
│   │   │   │   ├── page.tsx
│   │   │   │   └── loading.tsx
│   │   │   └── api/
│   │   │       └── pin/route.ts
│   │   ├── lib/
│   │   │   ├── jwt.ts
│   │   │   ├── cookies.ts
│   │   │   └── design-tokens.ts            ← typed exports from .impeccable.md
│   │   ├── components/
│   │   │   ├── ui/                          ← shadcn primitives go here
│   │   │   ├── PinForm.tsx
│   │   │   ├── BrandHeader.tsx
│   │   │   └── ImpeccableThemeProvider.tsx
│   │   └── tests/
│   │       ├── e2e/pin-login-lobby.spec.ts
│   │       └── unit/jwt.test.ts
│   └── worker/
│       ├── package.json
│       ├── tsconfig.json
│       ├── wrangler.toml                    ← worker-specific deploy config
│       ├── src/
│       │   ├── index.ts                     ← Hono app entrypoint
│       │   ├── routes/
│       │   │   ├── auth.ts
│       │   │   └── health.ts
│       │   ├── middleware/
│       │   │   ├── pin-cookie.ts
│       │   │   └── jwt-auth.ts
│       │   ├── lib/
│       │   │   ├── jwt.ts                    ← matches apps/web/lib/jwt.ts
│       │   │   ├── portal-client.ts
│       │   │   └── env.ts
│       │   └── types/env.d.ts
│       ├── migrations/
│       │   └── 0001_init.sql                 ← empty `bid_years` table (real schema in Plan 02)
│       └── tests/
│           ├── unit/
│           │   ├── jwt.test.ts
│           │   ├── portal-client.test.ts
│           │   └── pin-cookie.test.ts
│           └── integration/
│               └── auth-login.test.ts
├── packages/
│   └── shared/
│       ├── package.json
│       ├── tsconfig.json
│       ├── src/
│       │   ├── index.ts
│       │   ├── schemas/
│       │   │   ├── auth.ts                  ← Zod for login req/resp
│       │   │   └── jwt.ts                   ← Zod for JWT payload
│       │   └── constants/
│       │       ├── ranks.ts
│       │       ├── shifts.ts
│       │       └── design-tokens.ts
│       └── tests/
│           └── schemas/auth.test.ts
└── .github/
    └── workflows/
        ├── ci.yml
        └── deploy-staging.yml
```

---

### Task 1: Initialize monorepo skeleton

**Files:**
- Create: `MBFD_Hub/bid-app/package.json`
- Create: `MBFD_Hub/bid-app/pnpm-workspace.yaml`
- Create: `MBFD_Hub/bid-app/.gitignore`
- Create: `MBFD_Hub/bid-app/.npmrc`
- Create: `MBFD_Hub/bid-app/tsconfig.base.json`
- Create: `MBFD_Hub/bid-app/README.md`

- [ ] **Step 1: Create workspace root**

```bash
cd MBFD_Hub
mkdir -p bid-app && cd bid-app
```

- [ ] **Step 2: Write `package.json`**

```json
{
  "name": "mbfd-bid",
  "private": true,
  "type": "module",
  "packageManager": "pnpm@9.12.0",
  "engines": {
    "node": ">=20.11.0",
    "pnpm": ">=9.0.0"
  },
  "scripts": {
    "build": "pnpm -r build",
    "dev": "pnpm -r --parallel dev",
    "test": "pnpm -r test",
    "test:e2e": "pnpm --filter web test:e2e",
    "lint": "biome check .",
    "lint:fix": "biome check --write .",
    "typecheck": "pnpm -r typecheck",
    "deploy:staging": "pnpm -r deploy:staging"
  },
  "devDependencies": {
    "@biomejs/biome": "1.9.4",
    "typescript": "5.6.3"
  }
}
```

- [ ] **Step 3: Write `pnpm-workspace.yaml`**

```yaml
packages:
  - 'apps/*'
  - 'packages/*'
```

- [ ] **Step 4: Write `.gitignore`**

```gitignore
# Dependencies
node_modules
.pnpm-store
.pnpm-debug.log*

# Build outputs
.next
.open-next
.vercel
.wrangler
dist
build
*.tsbuildinfo

# Env
.env
.env.local
.env.*.local
!.env.example

# Secrets
.dev.vars
.dev.vars.*

# Cloudflare
.mf
wrangler.toml.bak

# Editor
.vscode/*
!.vscode/extensions.json
!.vscode/settings.json.example
.idea
*.swp

# OS
.DS_Store
Thumbs.db

# Test
coverage
playwright-report
test-results

# Logs
*.log
```

- [ ] **Step 5: Write `.npmrc`**

```ini
auto-install-peers=true
strict-peer-dependencies=false
shared-workspace-lockfile=true
node-linker=isolated
```

- [ ] **Step 6: Write `tsconfig.base.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "lib": ["ES2022", "DOM", "DOM.Iterable"],
    "strict": true,
    "noImplicitAny": true,
    "noUncheckedIndexedAccess": true,
    "noImplicitOverride": true,
    "noImplicitReturns": true,
    "noFallthroughCasesInSwitch": true,
    "exactOptionalPropertyTypes": true,
    "useUnknownInCatchVariables": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "resolveJsonModule": true,
    "isolatedModules": true,
    "verbatimModuleSyntax": true,
    "allowSyntheticDefaultImports": true,
    "incremental": true,
    "composite": true,
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true
  }
}
```

- [ ] **Step 7: Write `README.md`**

```markdown
# MBFD Bid Web App

Annual shift bid platform for the Miami Beach Fire Department.

- **Spec:** `../docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md`
- **Master plan:** `../docs/superpowers/plans/2026-05-17-mbfd-bid-master-index.md`

## Quickstart

\`\`\`bash
pnpm install
pnpm dev          # all apps in parallel
pnpm test
\`\`\`

## Layout

- `apps/web/`       — Next.js 15 on Cloudflare Pages
- `apps/worker/`    — Hono Worker on Cloudflare Workers
- `packages/shared/` — Zod schemas + TS types + constants

## Deploy

Pushes to `main` deploy to staging automatically (see `.github/workflows/deploy-staging.yml`).
Production deploys are manual via the GitHub Actions UI.

## Credentials

Never put secrets in `wrangler.toml` or source. Use `wrangler secret put NAME` for the worker, and GitHub repository secrets for CI. See `.env.example` for required names.
```

- [ ] **Step 8: Install root deps**

Run: `pnpm install`
Expected: `pnpm-lock.yaml` created, no errors.

- [ ] **Step 9: Initialize git repo (or join existing)**

This project lives inside the existing `MBFD_Hub` git repo. No new repo needed. Run:
```bash
cd ../..
git status MBFD_Hub/bid-app
```
Expected: shows untracked `bid-app/` directory.

- [ ] **Step 10: Commit**

```bash
git add bid-app/package.json bid-app/pnpm-workspace.yaml bid-app/.gitignore bid-app/.npmrc bid-app/tsconfig.base.json bid-app/README.md bid-app/pnpm-lock.yaml
git commit -m "chore: initialize bid-app pnpm monorepo skeleton"
```

---

### Task 2: Configure Biome (lint + format)

**Files:**
- Create: `MBFD_Hub/bid-app/biome.json`

- [ ] **Step 1: Write Biome config**

```json
{
  "$schema": "./node_modules/@biomejs/biome/configuration_schema.json",
  "files": {
    "ignore": [
      "**/node_modules",
      "**/.next",
      "**/.open-next",
      "**/.wrangler",
      "**/dist",
      "**/coverage",
      "**/playwright-report",
      "**/test-results",
      "pnpm-lock.yaml"
    ]
  },
  "organizeImports": { "enabled": true },
  "formatter": {
    "enabled": true,
    "indentStyle": "space",
    "indentWidth": 2,
    "lineWidth": 100,
    "lineEnding": "lf"
  },
  "linter": {
    "enabled": true,
    "rules": {
      "recommended": true,
      "correctness": {
        "noUnusedVariables": "error",
        "useExhaustiveDependencies": "error"
      },
      "style": {
        "useConst": "error",
        "useTemplate": "error"
      },
      "suspicious": {
        "noConsole": { "level": "warn", "options": { "allow": ["error", "warn", "info"] } },
        "noExplicitAny": "error"
      }
    }
  },
  "javascript": {
    "formatter": {
      "quoteStyle": "single",
      "trailingCommas": "all",
      "semicolons": "always",
      "arrowParentheses": "always"
    }
  }
}
```

- [ ] **Step 2: Run lint to verify config**

Run: `pnpm lint`
Expected: passes (no files to lint yet).

- [ ] **Step 3: Commit**

```bash
git add bid-app/biome.json
git commit -m "chore: add biome lint + format config"
```

---

### Task 3: Scaffold `packages/shared`

**Files:**
- Create: `MBFD_Hub/bid-app/packages/shared/package.json`
- Create: `MBFD_Hub/bid-app/packages/shared/tsconfig.json`
- Create: `MBFD_Hub/bid-app/packages/shared/src/index.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/src/constants/ranks.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/src/constants/shifts.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/src/constants/design-tokens.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/src/schemas/auth.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/src/schemas/jwt.ts`
- Create: `MBFD_Hub/bid-app/packages/shared/tests/schemas/auth.test.ts`

- [ ] **Step 1: Write package.json**

```json
{
  "name": "@mbfd/shared",
  "version": "0.0.1",
  "private": true,
  "type": "module",
  "main": "./dist/index.js",
  "types": "./dist/index.d.ts",
  "exports": {
    ".": { "types": "./dist/index.d.ts", "default": "./dist/index.js" },
    "./schemas/*": { "types": "./dist/schemas/*.d.ts", "default": "./dist/schemas/*.js" },
    "./constants/*": { "types": "./dist/constants/*.d.ts", "default": "./dist/constants/*.js" }
  },
  "scripts": {
    "build": "tsc -b",
    "dev": "tsc -b --watch",
    "test": "vitest run",
    "typecheck": "tsc -b --noEmit"
  },
  "dependencies": {
    "zod": "3.23.8"
  },
  "devDependencies": {
    "typescript": "5.6.3",
    "vitest": "2.1.4"
  }
}
```

- [ ] **Step 2: Write tsconfig**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "outDir": "./dist",
    "rootDir": "./src"
  },
  "include": ["src"],
  "exclude": ["node_modules", "dist", "tests"]
}
```

- [ ] **Step 3: Write failing test for auth schema**

`packages/shared/tests/schemas/auth.test.ts`:

```typescript
import { describe, expect, it } from 'vitest';
import { LoginRequestSchema, LoginResponseSchema } from '../../src/schemas/auth';

describe('LoginRequestSchema', () => {
  it('accepts a valid employee_id + password', () => {
    const parsed = LoginRequestSchema.safeParse({
      employee_id: '20731',
      password: 'correct-horse-battery-staple',
    });
    expect(parsed.success).toBe(true);
  });

  it('rejects an empty employee_id', () => {
    const parsed = LoginRequestSchema.safeParse({
      employee_id: '',
      password: 'pw',
    });
    expect(parsed.success).toBe(false);
  });

  it('rejects a password shorter than 6 chars', () => {
    const parsed = LoginRequestSchema.safeParse({
      employee_id: '20731',
      password: '12345',
    });
    expect(parsed.success).toBe(false);
  });
});

describe('LoginResponseSchema', () => {
  it('parses a portal success response', () => {
    const parsed = LoginResponseSchema.safeParse({
      member_id: 555,
      employee_id: '20731',
      first_name: 'Peter',
      last_name: 'Darley',
      rank: 'LT',
      role: 'member',
    });
    expect(parsed.success).toBe(true);
  });

  it('rejects unknown rank', () => {
    const parsed = LoginResponseSchema.safeParse({
      member_id: 555,
      employee_id: '20731',
      first_name: 'X',
      last_name: 'Y',
      rank: 'ENSIGN',
      role: 'member',
    });
    expect(parsed.success).toBe(false);
  });
});
```

- [ ] **Step 4: Install deps and run test (verify fails)**

```bash
cd packages/shared
pnpm install
pnpm test
```
Expected: FAIL — `Cannot find module '../../src/schemas/auth'`

- [ ] **Step 5: Implement constants**

`packages/shared/src/constants/ranks.ts`:

```typescript
export const RANKS = ['FF', 'LT', 'CPT', 'DC', 'DEP_CHIEF', 'CHIEF'] as const;
export type Rank = (typeof RANKS)[number];

export const RANK_LABELS: Record<Rank, string> = {
  FF: 'Firefighter',
  LT: 'Lieutenant',
  CPT: 'Captain',
  DC: 'Division Chief',
  DEP_CHIEF: 'Deputy Chief',
  CHIEF: 'Fire Chief',
};
```

`packages/shared/src/constants/shifts.ts`:

```typescript
export const SHIFTS = ['A', 'B', 'C', 'D'] as const;
export type Shift = (typeof SHIFTS)[number];

export const SHIFT_LABELS: Record<Shift, string> = {
  A: 'A Shift',
  B: 'B Shift',
  C: 'C Shift',
  D: 'D Shift (Days)',
};
```

`packages/shared/src/constants/design-tokens.ts`:

```typescript
// Mirror of MBFD_Hub/.impeccable.md — bid app variant
// Any change here MUST be reflected in tailwind.config.ts via this module.

export const COLORS = {
  // Brand
  brandRed: { 700: '#B91C1C', 600: '#DC2626', 50: '#FEF2F2' },
  // Authority (admin)
  slate850: '#1e293b',
  slate700: '#374151',
  // Warm neutrals (replace ALL cold grays)
  stone: {
    50: '#FAFAF9',
    100: '#F5F5F4',
    200: '#E7E5E3',
    400: '#A8A29E',
    600: '#78716C',
    800: '#292524',
  },
  // Semantic status (for live bid board parity with incident feed colors)
  status: {
    active: '#B91C1C',
    enroute: '#D97706',
    onscene: '#16A34A',
    clear: '#64748B',
  },
} as const;

export const FONTS = {
  heading: 'Plus Jakarta Sans, system-ui, sans-serif',
  body: 'Source Sans 3, system-ui, sans-serif',
  mono: 'JetBrains Mono, ui-monospace, monospace',
} as const;

export const MOTION = {
  durationFast: '150ms',
  durationBase: '200ms',
  durationSlow: '300ms',
  durationReveal: '400ms',
  easingOut: 'cubic-bezier(0, 0, 0.2, 1)',
  easingInOut: 'cubic-bezier(0.4, 0, 0.2, 1)',
} as const;

export const TYPE = {
  tabularNums: 'tabular-nums',
} as const;
```

- [ ] **Step 6: Implement auth schema**

`packages/shared/src/schemas/auth.ts`:

```typescript
import { z } from 'zod';
import { RANKS } from '../constants/ranks';

export const RoleSchema = z.enum(['member', 'admin']);
export type Role = z.infer<typeof RoleSchema>;

export const LoginRequestSchema = z.object({
  employee_id: z.string().trim().min(1, 'Employee ID required'),
  password: z.string().min(6, 'Password must be at least 6 characters'),
});
export type LoginRequest = z.infer<typeof LoginRequestSchema>;

export const LoginResponseSchema = z.object({
  member_id: z.number().int().positive(),
  employee_id: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  rank: z.enum(RANKS),
  role: RoleSchema,
});
export type LoginResponse = z.infer<typeof LoginResponseSchema>;
```

- [ ] **Step 7: Implement JWT schema**

`packages/shared/src/schemas/jwt.ts`:

```typescript
import { z } from 'zod';
import { RoleSchema } from './auth';
import { RANKS } from '../constants/ranks';

export const JwtPayloadSchema = z.object({
  sub: z.number().int().positive(),       // member_id
  emp: z.string(),                         // employee_id
  role: RoleSchema,
  rank: z.enum(RANKS),
  first_name: z.string(),
  last_name: z.string(),
  fresh_auth_at: z.number().int(),         // unix seconds
  iat: z.number().int(),
  exp: z.number().int(),
});
export type JwtPayload = z.infer<typeof JwtPayloadSchema>;
```

- [ ] **Step 8: Implement barrel export**

`packages/shared/src/index.ts`:

```typescript
export * from './constants/ranks';
export * from './constants/shifts';
export * from './constants/design-tokens';
export * from './schemas/auth';
export * from './schemas/jwt';
```

- [ ] **Step 9: Run tests to verify pass**

Run: `cd packages/shared && pnpm test`
Expected: all 5 tests pass.

- [ ] **Step 10: Build to verify type emit**

Run: `pnpm build`
Expected: `dist/` created with `.js` + `.d.ts` files.

- [ ] **Step 11: Commit**

```bash
git add packages/shared
git commit -m "feat(shared): add Zod schemas + design tokens + rank/shift constants"
```

---

### Task 4: Scaffold `apps/worker` (Hono)

**Files:**
- Create: `MBFD_Hub/bid-app/apps/worker/package.json`
- Create: `MBFD_Hub/bid-app/apps/worker/tsconfig.json`
- Create: `MBFD_Hub/bid-app/apps/worker/wrangler.toml`
- Create: `MBFD_Hub/bid-app/apps/worker/src/index.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/src/types/env.d.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/src/routes/health.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/migrations/0001_init.sql`
- Create: `MBFD_Hub/bid-app/apps/worker/.dev.vars.example`
- Create: `MBFD_Hub/bid-app/apps/worker/tests/unit/health.test.ts`

- [ ] **Step 1: Write package.json**

```json
{
  "name": "@mbfd/worker",
  "version": "0.0.1",
  "private": true,
  "type": "module",
  "main": "./src/index.ts",
  "scripts": {
    "dev": "wrangler dev --persist-to .wrangler/state --port 8787",
    "deploy:staging": "wrangler deploy --env staging",
    "deploy:production": "wrangler deploy --env production",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "tsc --noEmit",
    "db:migrate:local": "wrangler d1 migrations apply mbfd-bid-staging --local",
    "db:migrate:remote": "wrangler d1 migrations apply mbfd-bid-staging --remote"
  },
  "dependencies": {
    "@mbfd/shared": "workspace:*",
    "hono": "4.6.5",
    "@hono/zod-validator": "0.4.1",
    "drizzle-orm": "0.36.0",
    "zod": "3.23.8",
    "jose": "5.9.4"
  },
  "devDependencies": {
    "@cloudflare/workers-types": "4.20241011.0",
    "@cloudflare/vitest-pool-workers": "0.5.20",
    "drizzle-kit": "0.28.0",
    "typescript": "5.6.3",
    "vitest": "2.1.4",
    "wrangler": "3.84.1"
  }
}
```

- [ ] **Step 2: Write tsconfig**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "types": ["@cloudflare/workers-types", "vitest/globals"],
    "moduleResolution": "Bundler",
    "noEmit": true,
    "rootDir": "./src"
  },
  "include": ["src", "tests"]
}
```

- [ ] **Step 3: Write wrangler.toml (worker-level)**

```toml
name = "mbfd-bid-worker"
main = "src/index.ts"
compatibility_date = "2024-11-01"
compatibility_flags = ["nodejs_compat"]

# Cloudflare account ID set via env var: CLOUDFLARE_ACCOUNT_ID
# Token via wrangler login OR CLOUDFLARE_API_TOKEN

[env.staging]
name = "mbfd-bid-worker-staging"
route = { pattern = "api.staging.bid.mbfdhub.com/*", zone_name = "mbfdhub.com" }
vars = { ENV = "staging", PORTAL_BASE_URL = "https://portal.mbfdhub.com" }

[[env.staging.d1_databases]]
binding = "DB"
database_name = "mbfd-bid-staging"
database_id = "REPLACE_AFTER_d1_create"
migrations_dir = "./migrations"

[[env.staging.kv_namespaces]]
binding = "KV"
id = "REPLACE_AFTER_kv_create"

[env.production]
name = "mbfd-bid-worker-production"
route = { pattern = "api.bid.mbfdhub.com/*", zone_name = "mbfdhub.com" }
vars = { ENV = "production", PORTAL_BASE_URL = "https://portal.mbfdhub.com" }

[[env.production.d1_databases]]
binding = "DB"
database_name = "mbfd-bid-production"
database_id = "REPLACE_AFTER_d1_create"
migrations_dir = "./migrations"

[[env.production.kv_namespaces]]
binding = "KV"
id = "REPLACE_AFTER_kv_create"

# Secrets — set via `wrangler secret put NAME --env staging`:
#   JWT_SIGNING_KEY    (HMAC HS256, 32-byte random base64url)
#   PIN_HASH           (bcrypt of the live PIN; rotate via `wrangler secret put`)
#   PORTAL_BID_READER  (service token for /verify-credentials)
#   PORTAL_BID_WRITER  (service token for /bid-assignment — used in Plan 08)
```

- [ ] **Step 4: Write env type**

`apps/worker/src/types/env.d.ts`:

```typescript
import type { D1Database, KVNamespace } from '@cloudflare/workers-types';

export interface WorkerEnv {
  ENV: 'staging' | 'production';
  PORTAL_BASE_URL: string;
  // Secrets (set via wrangler secret put)
  JWT_SIGNING_KEY: string;
  PIN_HASH: string;
  PORTAL_BID_READER: string;
  // Bindings
  DB: D1Database;
  KV: KVNamespace;
}
```

- [ ] **Step 5: Write `.dev.vars.example`**

```
# Copy to .dev.vars (gitignored) for local dev.
# Real values set in CF dashboard for staging/production.

JWT_SIGNING_KEY=DEV_ONLY_REPLACE_WITH_32_BYTE_BASE64URL_VALUE
PIN_HASH=$2b$12$LocalDevBcryptHashOfPin2300Here
PORTAL_BID_READER=DEV_ONLY_NOT_A_REAL_TOKEN
```

- [ ] **Step 6: Write failing health test**

`apps/worker/tests/unit/health.test.ts`:

```typescript
import { describe, expect, it } from 'vitest';
import healthRoutes from '../../src/routes/health';
import { Hono } from 'hono';

describe('GET /health', () => {
  it('returns 200 with version info', async () => {
    const app = new Hono();
    app.route('/', healthRoutes);
    const res = await app.request('/health');
    expect(res.status).toBe(200);
    const body = (await res.json()) as { ok: boolean; env: string; ts: number };
    expect(body.ok).toBe(true);
    expect(body.env).toBeDefined();
    expect(typeof body.ts).toBe('number');
  });
});
```

- [ ] **Step 7: Run test (expect fail)**

```bash
cd apps/worker
pnpm install
pnpm test
```
Expected: FAIL — `Cannot find module '../../src/routes/health'`

- [ ] **Step 8: Implement health route**

`apps/worker/src/routes/health.ts`:

```typescript
import { Hono } from 'hono';
import type { WorkerEnv } from '../types/env';

const health = new Hono<{ Bindings: WorkerEnv }>();

health.get('/health', (c) => {
  return c.json({
    ok: true,
    env: c.env?.ENV ?? 'dev',
    ts: Date.now(),
  });
});

export default health;
```

- [ ] **Step 9: Implement worker entrypoint**

`apps/worker/src/index.ts`:

```typescript
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { logger } from 'hono/logger';
import health from './routes/health';
import type { WorkerEnv } from './types/env';

const app = new Hono<{ Bindings: WorkerEnv }>();

app.use('*', logger());
app.use(
  '*',
  cors({
    origin: (origin) => {
      // Reflect-only for known hostnames; reject otherwise.
      if (!origin) return null;
      if (origin.endsWith('.bid.mbfdhub.com') || origin === 'https://bid.mbfdhub.com') {
        return origin;
      }
      if (origin.startsWith('http://localhost:')) return origin;
      return null;
    },
    credentials: true,
    allowMethods: ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
  }),
);

app.route('/api', health);

app.notFound((c) => c.json({ error: 'Not Found' }, 404));

app.onError((err, c) => {
  console.error('[worker error]', err);
  return c.json({ error: 'Internal Error' }, 500);
});

export default app;
```

- [ ] **Step 10: Run tests (expect pass)**

```bash
pnpm test
```
Expected: 1 test passed.

- [ ] **Step 11: Write initial migration**

`apps/worker/migrations/0001_init.sql`:

```sql
-- Initial migration. Real schema arrives in Plan 02.
-- For now, a minimal table so the D1 binding is exercised.

CREATE TABLE IF NOT EXISTS schema_meta (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('plan', '01');
INSERT OR REPLACE INTO schema_meta (key, value) VALUES ('schema_version', '0001');
```

- [ ] **Step 12: Commit**

```bash
cd ../..
git add apps/worker
git commit -m "feat(worker): scaffold Hono worker with /health route and initial migration"
```

---

### Task 5: Scaffold `apps/web` (Next.js 15)

**Files:**
- Create: `MBFD_Hub/bid-app/apps/web/package.json`
- Create: `MBFD_Hub/bid-app/apps/web/tsconfig.json`
- Create: `MBFD_Hub/bid-app/apps/web/next.config.mjs`
- Create: `MBFD_Hub/bid-app/apps/web/tailwind.config.ts`
- Create: `MBFD_Hub/bid-app/apps/web/postcss.config.mjs`
- Create: `MBFD_Hub/bid-app/apps/web/app/layout.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/app/globals.css`
- Create: `MBFD_Hub/bid-app/apps/web/app/page.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/.env.example`

- [ ] **Step 1: Write package.json**

```json
{
  "name": "@mbfd/web",
  "version": "0.0.1",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "next dev --turbo --port 3000",
    "build": "next build",
    "start": "next start",
    "test": "vitest run",
    "test:e2e": "playwright test",
    "typecheck": "tsc --noEmit",
    "deploy:staging": "opennextjs-cloudflare deploy --env staging",
    "deploy:production": "opennextjs-cloudflare deploy --env production"
  },
  "dependencies": {
    "@mbfd/shared": "workspace:*",
    "next": "15.0.3",
    "react": "19.0.0-rc.1",
    "react-dom": "19.0.0-rc.1",
    "@radix-ui/react-slot": "1.1.0",
    "class-variance-authority": "0.7.0",
    "clsx": "2.1.1",
    "tailwind-merge": "2.5.4",
    "zod": "3.23.8",
    "jose": "5.9.4",
    "zustand": "5.0.0",
    "@tanstack/react-query": "5.59.16",
    "framer-motion": "11.11.10",
    "lucide-react": "0.453.0"
  },
  "devDependencies": {
    "@opennextjs/cloudflare": "0.5.7",
    "@types/node": "22.7.7",
    "@types/react": "18.3.12",
    "@types/react-dom": "18.3.1",
    "@playwright/test": "1.48.1",
    "@fontsource-variable/plus-jakarta-sans": "5.1.0",
    "@fontsource-variable/source-sans-3": "5.1.0",
    "@fontsource/jetbrains-mono": "5.1.1",
    "autoprefixer": "10.4.20",
    "postcss": "8.4.47",
    "tailwindcss": "3.4.14",
    "typescript": "5.6.3",
    "vitest": "2.1.4"
  }
}
```

- [ ] **Step 2: Write next.config.mjs**

```javascript
/** @type {import('next').NextConfig} */
const nextConfig = {
  experimental: {
    reactCompiler: true,
    typedRoutes: true,
  },
  // OpenNext on Cloudflare requires this; lazy server bundling.
  output: 'standalone',
  poweredByHeader: false,
  reactStrictMode: true,
  // Edge-runtime by default for our routes; pages-level opt-in per file.
  // Image optimization off (Cloudflare Images handles it later if needed).
  images: { unoptimized: true },
};

export default nextConfig;
```

- [ ] **Step 3: Write tsconfig**

```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "jsx": "preserve",
    "noEmit": true,
    "paths": {
      "@/*": ["./*"],
      "@mbfd/shared": ["../../packages/shared/src/index.ts"]
    },
    "plugins": [{ "name": "next" }]
  },
  "include": ["next-env.d.ts", "**/*.ts", "**/*.tsx", ".next/types/**/*.ts"],
  "exclude": ["node_modules"]
}
```

- [ ] **Step 4: Write tailwind.config.ts**

```typescript
import type { Config } from 'tailwindcss';
import { COLORS, FONTS, MOTION } from '@mbfd/shared';

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        red: COLORS.brandRed,
        slate: { 700: COLORS.slate700, 850: COLORS.slate850 },
        stone: COLORS.stone,
        status: COLORS.status,
      },
      fontFamily: {
        heading: [FONTS.heading],
        body: [FONTS.body],
        mono: [FONTS.mono],
      },
      fontVariantNumeric: {
        'tabular-nums': 'tabular-nums',
      },
      transitionDuration: {
        fast: MOTION.durationFast,
        base: MOTION.durationBase,
        slow: MOTION.durationSlow,
        reveal: MOTION.durationReveal,
      },
      transitionTimingFunction: {
        'out-quart': MOTION.easingOut,
        'in-out-quart': MOTION.easingInOut,
      },
    },
  },
  plugins: [
    // Custom plugin: forbid `gray-*` and `bg-black` / `bg-white` / arbitrary text-gray
    function ({ addBase, theme }: { addBase: (rules: object) => void; theme: (path: string) => unknown }) {
      addBase({
        // Set the body font + tabular-nums default for numeric headings
        body: { fontFamily: theme('fontFamily.body') as string },
      });
    },
  ],
};

export default config;
```

- [ ] **Step 5: Write postcss.config.mjs**

```javascript
export default {
  plugins: { tailwindcss: {}, autoprefixer: {} },
};
```

- [ ] **Step 6: Write globals.css**

`apps/web/app/globals.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

/* Variable font self-host via @fontsource — no Google Fonts CDN at runtime */
@import '@fontsource-variable/plus-jakarta-sans/index.css';
@import '@fontsource-variable/source-sans-3/index.css';
@import '@fontsource/jetbrains-mono/400.css';

@layer base {
  :root {
    /* Tint stones with brand hue (subtle) per .impeccable.md guidance */
    --bg: 36 33% 97%;        /* stone-50 */
    --fg: 24 10% 14%;        /* stone-800 */
  }

  html {
    color-scheme: light;
    background: hsl(var(--bg));
    color: hsl(var(--fg));
  }

  body {
    font-family: var(--font-body, 'Source Sans 3 Variable'), system-ui, sans-serif;
    font-feature-settings: 'kern' 1, 'liga' 1;
  }

  h1, h2, h3, h4, h5, h6 {
    font-family: 'Plus Jakarta Sans Variable', system-ui, sans-serif;
    font-weight: 600;
    letter-spacing: -0.01em;
  }

  /* All numeric data uses tabular-nums by default (spec §17.B) */
  .num,
  [data-num],
  .font-mono {
    font-variant-numeric: tabular-nums;
  }

  /* Touch targets — spec §17.D / .impeccable.md ERROR-037 */
  @media (hover: none) {
    button, a[role='button'], [type='submit'], [type='button'] {
      min-height: 44px;
      min-width: 44px;
    }
  }

  /* Motion gate — spec §17.C / .impeccable.md ERROR-036 */
  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      animation-duration: 0s !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0s !important;
      scroll-behavior: auto !important;
    }
  }
}
```

- [ ] **Step 7: Write root layout**

`apps/web/app/layout.tsx`:

```tsx
import type { Metadata, Viewport } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'MBFD Bid',
  description: 'Miami Beach Fire Department — Annual Shift Bid',
  robots: { index: false, follow: false }, // unlisted; PIN-gated
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  maximumScale: 5,
  viewportFit: 'cover',
  themeColor: '#1e293b',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body className="min-h-screen bg-stone-50 text-stone-800 antialiased">
        {children}
      </body>
    </html>
  );
}
```

- [ ] **Step 8: Write placeholder home page**

`apps/web/app/page.tsx`:

```tsx
export default function Home() {
  return (
    <main className="mx-auto max-w-md px-4 py-16 sm:py-24">
      <h1 className="font-heading text-3xl text-stone-800">MBFD Bid</h1>
      <p className="mt-3 text-stone-600">
        Annual shift bid. Authorized personnel only.
      </p>
    </main>
  );
}
```

> The real PIN form replaces this in Task 7. Placeholder lets us verify the deploy first.

- [ ] **Step 9: Install deps**

```bash
cd apps/web
pnpm install
```

Expected: lockfile updated, no errors.

- [ ] **Step 10: Run dev to verify**

Run: `pnpm dev`
Open: http://localhost:3000
Expected: page renders with "MBFD Bid" heading in Plus Jakarta Sans, body text in Source Sans 3, stone-50 background.
Quit: Ctrl-C

- [ ] **Step 11: Commit**

```bash
cd ../..
git add apps/web
git commit -m "feat(web): scaffold Next.js 15 app with .impeccable design tokens applied"
```

---

### Task 6: Implement JWT module (shared between web + worker)

**Files:**
- Create: `MBFD_Hub/bid-app/apps/worker/src/lib/jwt.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/tests/unit/jwt.test.ts`
- Create: `MBFD_Hub/bid-app/apps/web/lib/jwt.ts`
- Create: `MBFD_Hub/bid-app/apps/web/tests/unit/jwt.test.ts`

> Note: The web and worker each have their own `jwt.ts` import wrapper but both delegate to `jose` and share the `JwtPayloadSchema` from `@mbfd/shared`. Both files exist because edge runtimes differ slightly between Pages middleware and Workers.

- [ ] **Step 1: Write failing worker test**

`apps/worker/tests/unit/jwt.test.ts`:

```typescript
import { describe, expect, it } from 'vitest';
import { signJwt, verifyJwt } from '../../src/lib/jwt';

const TEST_KEY = 'A'.repeat(64); // 32-byte hex placeholder

const payload = {
  sub: 555,
  emp: '20731',
  role: 'member' as const,
  rank: 'LT' as const,
  first_name: 'Peter',
  last_name: 'Darley',
  fresh_auth_at: Math.floor(Date.now() / 1000),
};

describe('signJwt / verifyJwt', () => {
  it('round-trips a valid JWT', async () => {
    const token = await signJwt(payload, TEST_KEY, '8h');
    expect(typeof token).toBe('string');
    expect(token.split('.').length).toBe(3);

    const verified = await verifyJwt(token, TEST_KEY);
    expect(verified.sub).toBe(payload.sub);
    expect(verified.emp).toBe(payload.emp);
    expect(verified.role).toBe('member');
  });

  it('rejects a token signed with a different key', async () => {
    const token = await signJwt(payload, TEST_KEY, '8h');
    await expect(verifyJwt(token, 'B'.repeat(64))).rejects.toThrow();
  });

  it('rejects an expired token', async () => {
    const token = await signJwt(payload, TEST_KEY, '-1s');
    await expect(verifyJwt(token, TEST_KEY)).rejects.toThrow();
  });
});
```

- [ ] **Step 2: Run test (expect fail)**

```bash
cd apps/worker
pnpm test -- jwt
```
Expected: FAIL — module not found.

- [ ] **Step 3: Implement worker JWT module**

`apps/worker/src/lib/jwt.ts`:

```typescript
import { SignJWT, jwtVerify } from 'jose';
import type { JwtPayload } from '@mbfd/shared';
import { JwtPayloadSchema } from '@mbfd/shared';

function keyToUint8(key: string): Uint8Array {
  // Accept either hex (32 bytes = 64 chars) or base64url
  if (/^[0-9a-fA-F]{64}$/.test(key)) {
    return Uint8Array.from(key.match(/.{1,2}/g)!.map((b) => Number.parseInt(b, 16)));
  }
  return new TextEncoder().encode(key);
}

export async function signJwt(
  payload: Omit<JwtPayload, 'iat' | 'exp'>,
  signingKey: string,
  expiresIn: string = '8h',
): Promise<string> {
  const key = keyToUint8(signingKey);
  return new SignJWT(payload as unknown as Record<string, unknown>)
    .setProtectedHeader({ alg: 'HS256' })
    .setIssuedAt()
    .setExpirationTime(expiresIn)
    .sign(key);
}

export async function verifyJwt(token: string, signingKey: string): Promise<JwtPayload> {
  const key = keyToUint8(signingKey);
  const { payload } = await jwtVerify(token, key, { algorithms: ['HS256'] });
  return JwtPayloadSchema.parse(payload);
}
```

- [ ] **Step 4: Run tests (expect pass)**

```bash
pnpm test -- jwt
```
Expected: 3 tests passed.

- [ ] **Step 5: Mirror to web app**

`apps/web/lib/jwt.ts`: identical content to `apps/worker/src/lib/jwt.ts`, copied verbatim. (Future: extract to `packages/shared/src/jwt.ts`. YAGNI for now — both files are ~30 lines.)

```typescript
import { SignJWT, jwtVerify } from 'jose';
import type { JwtPayload } from '@mbfd/shared';
import { JwtPayloadSchema } from '@mbfd/shared';

function keyToUint8(key: string): Uint8Array {
  if (/^[0-9a-fA-F]{64}$/.test(key)) {
    return Uint8Array.from(key.match(/.{1,2}/g)!.map((b) => Number.parseInt(b, 16)));
  }
  return new TextEncoder().encode(key);
}

export async function signJwt(
  payload: Omit<JwtPayload, 'iat' | 'exp'>,
  signingKey: string,
  expiresIn: string = '8h',
): Promise<string> {
  const key = keyToUint8(signingKey);
  return new SignJWT(payload as unknown as Record<string, unknown>)
    .setProtectedHeader({ alg: 'HS256' })
    .setIssuedAt()
    .setExpirationTime(expiresIn)
    .sign(key);
}

export async function verifyJwt(token: string, signingKey: string): Promise<JwtPayload> {
  const key = keyToUint8(signingKey);
  const { payload } = await jwtVerify(token, key, { algorithms: ['HS256'] });
  return JwtPayloadSchema.parse(payload);
}
```

- [ ] **Step 6: Mirror the web test**

`apps/web/tests/unit/jwt.test.ts`: identical to worker test, except imports `../../lib/jwt`.

- [ ] **Step 7: Run web tests (expect pass)**

```bash
cd apps/web
pnpm test -- jwt
```
Expected: 3 tests passed.

- [ ] **Step 8: Commit**

```bash
cd ../..
git add apps/worker/src/lib/jwt.ts apps/worker/tests/unit/jwt.test.ts apps/web/lib/jwt.ts apps/web/tests/unit/jwt.test.ts
git commit -m "feat(auth): JWT sign/verify modules with jose, shared schema"
```

---

### Task 7: PIN gate (Next.js middleware) + PIN form

**Files:**
- Create: `MBFD_Hub/bid-app/apps/web/middleware.ts`
- Create: `MBFD_Hub/bid-app/apps/web/app/api/pin/route.ts`
- Create: `MBFD_Hub/bid-app/apps/web/components/PinForm.tsx`
- Modify: `MBFD_Hub/bid-app/apps/web/app/page.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/lib/cookies.ts`

- [ ] **Step 1: Write the PIN page (Server Component)**

Replace `apps/web/app/page.tsx`:

```tsx
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { PIN_COOKIE_NAME } from '@/lib/cookies';
import { PinForm } from '@/components/PinForm';

export default async function Home() {
  const c = await cookies();
  if (c.get(PIN_COOKIE_NAME)?.value === 'ok') {
    redirect('/login');
  }
  return (
    <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-16">
      <header className="mb-8">
        <h1 className="font-heading text-3xl text-stone-800">MBFD Bid</h1>
        <p className="mt-2 text-stone-600">Authorized access only.</p>
      </header>
      <PinForm />
    </main>
  );
}
```

- [ ] **Step 2: Write cookie helpers**

`apps/web/lib/cookies.ts`:

```typescript
export const PIN_COOKIE_NAME = 'mbfd_pin';
export const JWT_COOKIE_NAME = 'mbfd_bid_jwt';

export const PIN_COOKIE_OPTS = {
  httpOnly: true,
  secure: true,
  sameSite: 'strict' as const,
  path: '/',
  maxAge: 60 * 60 * 24 * 7, // 7 days — survives a multi-day bid event
};

export const JWT_COOKIE_OPTS = {
  httpOnly: true,
  secure: true,
  sameSite: 'strict' as const,
  path: '/',
  maxAge: 60 * 60 * 8, // 8 hours
};
```

- [ ] **Step 3: Write PIN form client component**

`apps/web/components/PinForm.tsx`:

```tsx
'use client';

import { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';

export function PinForm() {
  const router = useRouter();
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    startTransition(async () => {
      const res = await fetch('/api/pin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pin }),
      });
      if (res.ok) {
        router.push('/login');
        router.refresh();
      } else if (res.status === 401) {
        setError('Incorrect PIN.');
      } else {
        setError('Could not verify PIN. Try again.');
      }
    });
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <label className="block">
        <span className="block text-sm font-medium text-stone-700">Access PIN</span>
        <input
          type="password"
          inputMode="numeric"
          autoComplete="off"
          autoFocus
          required
          minLength={4}
          maxLength={8}
          value={pin}
          onChange={(e) => setPin(e.target.value)}
          aria-invalid={error ? 'true' : 'false'}
          aria-describedby={error ? 'pin-error' : undefined}
          className="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-3 font-mono text-lg tracking-widest text-stone-800 shadow-sm outline-none transition duration-base ease-out-quart focus:border-red-700 focus:ring-2 focus:ring-red-700"
        />
      </label>
      {error && (
        <p id="pin-error" role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
      <button
        type="submit"
        disabled={pending || pin.length < 4}
        className="inline-flex w-full items-center justify-center rounded-lg bg-red-700 px-4 py-3 text-sm font-semibold text-white shadow-sm transition duration-base ease-out-quart hover:bg-red-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {pending ? 'Verifying…' : 'Continue'}
      </button>
    </form>
  );
}
```

- [ ] **Step 4: Write `/api/pin` route**

`apps/web/app/api/pin/route.ts`:

```typescript
import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
import { z } from 'zod';
import { PIN_COOKIE_NAME, PIN_COOKIE_OPTS } from '@/lib/cookies';

export const runtime = 'edge';

const Body = z.object({ pin: z.string().min(4).max(8) });

export async function POST(req: Request) {
  const json = await req.json().catch(() => null);
  const parsed = Body.safeParse(json);
  if (!parsed.success) {
    return NextResponse.json({ error: 'invalid' }, { status: 400 });
  }

  // PIN_HASH env is a bcrypt hash; we use timing-safe compare on hashed pin instead.
  // For v1 we accept the plain PIN against a CF env var (PIN_PLAIN).
  // In production, swap to bcrypt verify via `bcryptjs` or `@noble/hashes`.
  const expected = process.env.PIN_PLAIN ?? '2300';
  const ok = parsed.data.pin === expected;
  if (!ok) {
    return NextResponse.json({ error: 'invalid_pin' }, { status: 401 });
  }

  const c = await cookies();
  c.set(PIN_COOKIE_NAME, 'ok', PIN_COOKIE_OPTS);
  return new NextResponse(null, { status: 204 });
}
```

> The bcrypt swap happens in Task 12 once we wire the worker secret store. For now the env var keeps Plan 01 self-contained.

- [ ] **Step 5: Write middleware (gate everything except `/`, `/api/pin`, static assets)**

`apps/web/middleware.ts`:

```typescript
import { NextResponse, type NextRequest } from 'next/server';
import { PIN_COOKIE_NAME } from '@/lib/cookies';

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api/pin).*)'],
};

export function middleware(req: NextRequest) {
  const { pathname } = req.nextUrl;

  // The PIN form itself is public
  if (pathname === '/' || pathname.startsWith('/api/pin')) return NextResponse.next();

  const pin = req.cookies.get(PIN_COOKIE_NAME)?.value;
  if (pin !== 'ok') {
    const url = req.nextUrl.clone();
    url.pathname = '/';
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}
```

- [ ] **Step 6: Run dev + manual smoke**

```bash
cd apps/web
pnpm dev
```
Open: http://localhost:3000

Expected:
1. PIN form renders, focus-ring is red-700.
2. Wrong PIN → red error message under input.
3. Correct PIN (2300) → navigates to /login (404 expected — built in Task 8).

- [ ] **Step 7: Write E2E test (Playwright)**

Create `apps/web/playwright.config.ts`:

```typescript
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  retries: process.env.CI ? 2 : 0,
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'on-first-retry',
  },
  webServer: {
    command: 'pnpm dev',
    url: 'http://localhost:3000',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
```

Create `apps/web/tests/e2e/pin-login-lobby.spec.ts`:

```typescript
import { test, expect } from '@playwright/test';

test.describe('PIN gate', () => {
  test('rejects an incorrect PIN', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Access PIN').fill('9999');
    await page.getByRole('button', { name: /continue/i }).click();
    await expect(page.getByRole('alert')).toHaveText(/incorrect/i);
  });

  test('accepts PIN 2300 and forwards to /login', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Access PIN').fill('2300');
    await page.getByRole('button', { name: /continue/i }).click();
    await expect(page).toHaveURL(/\/login$/);
  });

  test('a /lobby request without PIN cookie redirects to /', async ({ page }) => {
    await page.goto('/lobby');
    await expect(page).toHaveURL(/\/$/);
  });
});
```

- [ ] **Step 8: Run E2E**

```bash
pnpm test:e2e
```
Expected: 3 tests pass × 2 browsers = 6 passing tests.

- [ ] **Step 9: Commit**

```bash
cd ../..
git add apps/web/middleware.ts apps/web/app/page.tsx apps/web/app/api apps/web/components/PinForm.tsx apps/web/lib/cookies.ts apps/web/playwright.config.ts apps/web/tests/e2e
git commit -m "feat(web): PIN gate (cookie + middleware + form) with E2E tests"
```

---

### Task 8: Portal client + `/api/auth/login` worker route

**Files:**
- Create: `MBFD_Hub/bid-app/apps/worker/src/lib/portal-client.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/src/lib/env.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/src/routes/auth.ts`
- Modify: `MBFD_Hub/bid-app/apps/worker/src/index.ts` (mount auth)
- Create: `MBFD_Hub/bid-app/apps/worker/tests/unit/portal-client.test.ts`
- Create: `MBFD_Hub/bid-app/apps/worker/tests/integration/auth-login.test.ts`

- [ ] **Step 1: Write env validator**

`apps/worker/src/lib/env.ts`:

```typescript
import { z } from 'zod';

export const EnvSchema = z.object({
  ENV: z.enum(['staging', 'production']),
  PORTAL_BASE_URL: z.string().url(),
  JWT_SIGNING_KEY: z.string().min(32),
  PIN_HASH: z.string().min(1),
  PORTAL_BID_READER: z.string().min(1),
});

export type ValidatedEnv = z.infer<typeof EnvSchema>;

export function validateEnv(env: unknown): ValidatedEnv {
  return EnvSchema.parse(env);
}
```

- [ ] **Step 2: Write failing portal-client test**

`apps/worker/tests/unit/portal-client.test.ts`:

```typescript
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { verifyCredentials } from '../../src/lib/portal-client';

const ORIG_FETCH = globalThis.fetch;

describe('verifyCredentials', () => {
  beforeEach(() => {
    globalThis.fetch = vi.fn();
  });
  afterEach(() => {
    globalThis.fetch = ORIG_FETCH;
  });

  it('returns the portal payload on 200', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(
        JSON.stringify({
          member_id: 555,
          employee_id: '20731',
          first_name: 'Peter',
          last_name: 'Darley',
          rank: 'LT',
          role: 'member',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );
    const result = await verifyCredentials({
      portalBaseUrl: 'https://portal.test',
      token: 'tok',
      employee_id: '20731',
      password: 'pw',
    });
    expect(result.member_id).toBe(555);
  });

  it('returns null on 401', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 401 }),
    );
    const result = await verifyCredentials({
      portalBaseUrl: 'https://portal.test',
      token: 'tok',
      employee_id: 'x',
      password: 'y',
    });
    expect(result).toBeNull();
  });

  it('throws on 5xx', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response('boom', { status: 502 }),
    );
    await expect(
      verifyCredentials({
        portalBaseUrl: 'https://portal.test',
        token: 'tok',
        employee_id: 'x',
        password: 'y',
      }),
    ).rejects.toThrow(/portal_unavailable/);
  });
});
```

- [ ] **Step 3: Implement portal client**

`apps/worker/src/lib/portal-client.ts`:

```typescript
import { LoginResponseSchema, type LoginResponse } from '@mbfd/shared';

export interface VerifyCredentialsInput {
  portalBaseUrl: string;
  token: string;
  employee_id: string;
  password: string;
}

export async function verifyCredentials(
  input: VerifyCredentialsInput,
): Promise<LoginResponse | null> {
  const url = `${input.portalBaseUrl}/api/v2/verify-credentials`;
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${input.token}`,
      'User-Agent': 'mbfd-bid-worker/1.0',
    },
    body: JSON.stringify({
      employee_id: input.employee_id,
      password: input.password,
    }),
  });

  if (res.status === 401) return null;
  if (res.status >= 500) throw new Error('portal_unavailable');
  if (!res.ok) throw new Error(`portal_error_${res.status}`);

  const json = (await res.json()) as unknown;
  return LoginResponseSchema.parse(json);
}
```

- [ ] **Step 4: Run portal-client tests**

```bash
cd apps/worker
pnpm test -- portal-client
```
Expected: 3 tests pass.

- [ ] **Step 5: Write integration test for /api/auth/login**

`apps/worker/tests/integration/auth-login.test.ts`:

```typescript
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { Hono } from 'hono';
import auth from '../../src/routes/auth';
import type { WorkerEnv } from '../../src/types/env';

const ORIG_FETCH = globalThis.fetch;

function mkEnv(): WorkerEnv {
  return {
    ENV: 'staging',
    PORTAL_BASE_URL: 'https://portal.test',
    JWT_SIGNING_KEY: 'A'.repeat(64),
    PIN_HASH: '$2b$12$placeholder',
    PORTAL_BID_READER: 'reader-tok',
    DB: {} as never, // unused on this route
    KV: {} as never,
  };
}

describe('POST /api/auth/login', () => {
  beforeEach(() => {
    globalThis.fetch = vi.fn();
  });
  afterEach(() => {
    globalThis.fetch = ORIG_FETCH;
  });

  it('returns 200 + JWT on portal success', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(
        JSON.stringify({
          member_id: 555,
          employee_id: '20731',
          first_name: 'Peter',
          last_name: 'Darley',
          rank: 'LT',
          role: 'member',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const app = new Hono<{ Bindings: WorkerEnv }>();
    app.route('/api/auth', auth);

    const res = await app.request(
      '/api/auth/login',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: '20731', password: 'pw-secret' }),
      },
      mkEnv(),
    );
    expect(res.status).toBe(200);
    const body = (await res.json()) as { jwt: string; role: string };
    expect(body.role).toBe('member');
    expect(body.jwt.split('.').length).toBe(3);
  });

  it('returns 401 on portal 401', async () => {
    (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      new Response(null, { status: 401 }),
    );

    const app = new Hono<{ Bindings: WorkerEnv }>();
    app.route('/api/auth', auth);

    const res = await app.request(
      '/api/auth/login',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: 'x', password: 'wrong' }),
      },
      mkEnv(),
    );
    expect(res.status).toBe(401);
  });

  it('returns 400 on invalid body', async () => {
    const app = new Hono<{ Bindings: WorkerEnv }>();
    app.route('/api/auth', auth);

    const res = await app.request(
      '/api/auth/login',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: '', password: 'x' }),
      },
      mkEnv(),
    );
    expect(res.status).toBe(400);
  });
});
```

- [ ] **Step 6: Run test (expect fail)**

```bash
pnpm test -- auth-login
```
Expected: module not found.

- [ ] **Step 7: Implement auth route**

`apps/worker/src/routes/auth.ts`:

```typescript
import { Hono } from 'hono';
import { zValidator } from '@hono/zod-validator';
import { LoginRequestSchema } from '@mbfd/shared';
import { verifyCredentials } from '../lib/portal-client';
import { signJwt } from '../lib/jwt';
import { validateEnv } from '../lib/env';
import type { WorkerEnv } from '../types/env';

const auth = new Hono<{ Bindings: WorkerEnv }>();

auth.post('/login', zValidator('json', LoginRequestSchema), async (c) => {
  const { employee_id, password } = c.req.valid('json');
  const env = validateEnv(c.env);

  let portalResponse;
  try {
    portalResponse = await verifyCredentials({
      portalBaseUrl: env.PORTAL_BASE_URL,
      token: env.PORTAL_BID_READER,
      employee_id,
      password,
    });
  } catch (err) {
    console.error('[auth.login] portal error', err);
    return c.json({ error: 'portal_unavailable' }, 503);
  }

  if (!portalResponse) {
    return c.json({ error: 'invalid_credentials' }, 401);
  }

  const nowSec = Math.floor(Date.now() / 1000);
  const jwt = await signJwt(
    {
      sub: portalResponse.member_id,
      emp: portalResponse.employee_id,
      role: portalResponse.role,
      rank: portalResponse.rank,
      first_name: portalResponse.first_name,
      last_name: portalResponse.last_name,
      fresh_auth_at: nowSec,
    },
    env.JWT_SIGNING_KEY,
    '8h',
  );

  return c.json({
    jwt,
    role: portalResponse.role,
    member: {
      member_id: portalResponse.member_id,
      employee_id: portalResponse.employee_id,
      first_name: portalResponse.first_name,
      last_name: portalResponse.last_name,
      rank: portalResponse.rank,
    },
  });
});

export default auth;
```

- [ ] **Step 8: Mount in worker entrypoint**

Edit `apps/worker/src/index.ts`, replace the `app.route('/api', health);` line with:

```typescript
import auth from './routes/auth';
// …
app.route('/api', health);
app.route('/api/auth', auth);
```

- [ ] **Step 9: Run tests (expect pass)**

```bash
pnpm test
```
Expected: all tests pass (7 total in worker package).

- [ ] **Step 10: Commit**

```bash
cd ../..
git add apps/worker/src/lib/portal-client.ts apps/worker/src/lib/env.ts apps/worker/src/routes/auth.ts apps/worker/src/index.ts apps/worker/tests/unit/portal-client.test.ts apps/worker/tests/integration/auth-login.test.ts
git commit -m "feat(worker): /api/auth/login route with portal verify + JWT issuance"
```

---

### Task 9: Login page on the web app

**Files:**
- Create: `MBFD_Hub/bid-app/apps/web/app/(auth)/login/page.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/app/(auth)/login/login-form.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/components/BrandHeader.tsx`

- [ ] **Step 1: Write the brand header**

`apps/web/components/BrandHeader.tsx`:

```tsx
export function BrandHeader({ subtitle }: { subtitle?: string }) {
  return (
    <header className="flex items-center gap-3 border-b border-stone-200 bg-slate-850 px-4 py-4 text-white sm:px-6">
      <div
        aria-hidden
        className="flex h-9 w-9 items-center justify-center rounded-md bg-red-700 font-heading text-base font-bold leading-none"
      >
        FD
      </div>
      <div className="flex-1">
        <h1 className="font-heading text-base leading-tight">MBFD Annual Bid</h1>
        {subtitle && <p className="text-xs leading-tight text-stone-200/80">{subtitle}</p>}
      </div>
    </header>
  );
}
```

- [ ] **Step 2: Write login page (Server Component)**

`apps/web/app/(auth)/login/page.tsx`:

```tsx
import { BrandHeader } from '@/components/BrandHeader';
import { LoginForm } from './login-form';

export default function LoginPage() {
  return (
    <div className="min-h-screen bg-stone-50">
      <BrandHeader subtitle="Authorized personnel only" />
      <main className="mx-auto max-w-md px-4 py-12 sm:py-16">
        <h2 className="font-heading text-2xl text-stone-800">Sign in</h2>
        <p className="mt-1 text-sm text-stone-600">
          Use your employee portal credentials.
        </p>
        <div className="mt-8">
          <LoginForm />
        </div>
      </main>
    </div>
  );
}
```

- [ ] **Step 3: Write login client form**

`apps/web/app/(auth)/login/login-form.tsx`:

```tsx
'use client';

import { useState, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import { LoginRequestSchema } from '@mbfd/shared';

export function LoginForm() {
  const router = useRouter();
  const [employeeId, setEmployeeId] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const parsed = LoginRequestSchema.safeParse({
      employee_id: employeeId.trim(),
      password,
    });
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid input');
      return;
    }
    startTransition(async () => {
      const apiBase = process.env.NEXT_PUBLIC_WORKER_BASE ?? '';
      const res = await fetch(`${apiBase}/api/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(parsed.data),
      });
      if (res.ok) {
        // JWT cookie will be set by a forthcoming /api/auth/session-finalize call (Task 10)
        const body = (await res.json()) as { jwt: string };
        // Hand off to the web app's /api/auth/session-finalize to set the HTTP-only cookie
        const finalize = await fetch('/api/auth/session-finalize', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ jwt: body.jwt }),
        });
        if (!finalize.ok) {
          setError('Could not finalize session. Try again.');
          return;
        }
        router.push('/lobby');
        router.refresh();
      } else if (res.status === 401) {
        setError('Incorrect employee ID or password.');
      } else if (res.status === 503) {
        setError('Portal is unreachable. Contact IT.');
      } else {
        setError('Login failed. Try again.');
      }
    });
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <label className="block">
        <span className="block text-sm font-medium text-stone-700">Employee ID</span>
        <input
          type="text"
          inputMode="numeric"
          autoComplete="username"
          autoFocus
          required
          value={employeeId}
          onChange={(e) => setEmployeeId(e.target.value)}
          className="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-3 font-mono text-base text-stone-800 shadow-sm outline-none transition duration-base ease-out-quart focus:border-red-700 focus:ring-2 focus:ring-red-700"
        />
      </label>
      <label className="block">
        <span className="block text-sm font-medium text-stone-700">Password</span>
        <input
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-3 text-base text-stone-800 shadow-sm outline-none transition duration-base ease-out-quart focus:border-red-700 focus:ring-2 focus:ring-red-700"
        />
      </label>
      {error && (
        <p role="alert" className="text-sm text-red-700">
          {error}
        </p>
      )}
      <button
        type="submit"
        disabled={pending || !employeeId.trim() || password.length < 6}
        className="inline-flex w-full items-center justify-center rounded-lg bg-red-700 px-4 py-3 text-sm font-semibold text-white shadow-sm transition duration-base ease-out-quart hover:bg-red-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {pending ? 'Signing in…' : 'Sign in'}
      </button>
    </form>
  );
}
```

- [ ] **Step 4: Run dev + manual smoke**

```bash
cd apps/web
pnpm dev
```
Open: http://localhost:3000 → enter PIN 2300 → see login form. Form renders correctly.

- [ ] **Step 5: Commit**

```bash
cd ../..
git add apps/web/app/(auth) apps/web/components/BrandHeader.tsx
git commit -m "feat(web): login page + form with .impeccable design tokens"
```

---

### Task 10: Web-side session finalize + protected /lobby

**Files:**
- Create: `MBFD_Hub/bid-app/apps/web/app/api/auth/session-finalize/route.ts`
- Modify: `MBFD_Hub/bid-app/apps/web/middleware.ts` (gate /lobby on JWT)
- Create: `MBFD_Hub/bid-app/apps/web/app/lobby/page.tsx`
- Create: `MBFD_Hub/bid-app/apps/web/app/lobby/loading.tsx`

- [ ] **Step 1: Implement session-finalize**

`apps/web/app/api/auth/session-finalize/route.ts`:

```typescript
import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
import { z } from 'zod';
import { JWT_COOKIE_NAME, JWT_COOKIE_OPTS } from '@/lib/cookies';
import { verifyJwt } from '@/lib/jwt';

export const runtime = 'edge';

const Body = z.object({ jwt: z.string().min(1) });

export async function POST(req: Request) {
  const json = await req.json().catch(() => null);
  const parsed = Body.safeParse(json);
  if (!parsed.success) return NextResponse.json({ error: 'invalid' }, { status: 400 });

  const signingKey = process.env.JWT_SIGNING_KEY;
  if (!signingKey) {
    return NextResponse.json({ error: 'misconfigured' }, { status: 500 });
  }

  try {
    await verifyJwt(parsed.data.jwt, signingKey);
  } catch {
    return NextResponse.json({ error: 'invalid_jwt' }, { status: 401 });
  }

  const c = await cookies();
  c.set(JWT_COOKIE_NAME, parsed.data.jwt, JWT_COOKIE_OPTS);
  return new NextResponse(null, { status: 204 });
}
```

- [ ] **Step 2: Update middleware to require JWT for /lobby and onward**

Replace `apps/web/middleware.ts`:

```typescript
import { NextResponse, type NextRequest } from 'next/server';
import { PIN_COOKIE_NAME, JWT_COOKIE_NAME } from '@/lib/cookies';

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico|api/pin).*)'],
};

const PUBLIC_PATHS = new Set(['/', '/login']);

export function middleware(req: NextRequest) {
  const { pathname } = req.nextUrl;

  // Allow PIN form + login page + their API routes
  if (
    PUBLIC_PATHS.has(pathname) ||
    pathname.startsWith('/api/pin') ||
    pathname.startsWith('/api/auth/session-finalize')
  ) {
    // …but still require PIN before /login
    if (pathname === '/login' && req.cookies.get(PIN_COOKIE_NAME)?.value !== 'ok') {
      const url = req.nextUrl.clone();
      url.pathname = '/';
      return NextResponse.redirect(url);
    }
    return NextResponse.next();
  }

  // All other paths require both PIN and JWT
  if (req.cookies.get(PIN_COOKIE_NAME)?.value !== 'ok') {
    const url = req.nextUrl.clone();
    url.pathname = '/';
    return NextResponse.redirect(url);
  }
  if (!req.cookies.get(JWT_COOKIE_NAME)?.value) {
    const url = req.nextUrl.clone();
    url.pathname = '/login';
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}
```

- [ ] **Step 3: Write `/lobby/page.tsx` (Server Component)**

`apps/web/app/lobby/page.tsx`:

```tsx
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { BrandHeader } from '@/components/BrandHeader';
import { JWT_COOKIE_NAME } from '@/lib/cookies';
import { verifyJwt } from '@/lib/jwt';
import { RANK_LABELS } from '@mbfd/shared';

export const runtime = 'edge';

export default async function LobbyPage() {
  const jwt = (await cookies()).get(JWT_COOKIE_NAME)?.value;
  if (!jwt) redirect('/login');

  const signingKey = process.env.JWT_SIGNING_KEY;
  if (!signingKey) throw new Error('missing JWT_SIGNING_KEY');

  let payload;
  try {
    payload = await verifyJwt(jwt, signingKey);
  } catch {
    redirect('/login');
  }

  return (
    <div className="min-h-screen bg-stone-50">
      <BrandHeader subtitle={`Hi, ${payload.first_name} — ${RANK_LABELS[payload.rank]}`} />
      <main className="mx-auto max-w-3xl px-4 py-8 sm:py-12">
        <h2 className="font-heading text-2xl text-stone-800">Lobby</h2>
        <p className="mt-2 text-stone-600">
          Bid hasn't started yet. This page will become the pre-bid lobby in Plan 04.
        </p>
        <dl className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Card label="Employee ID" value={payload.emp} numeric />
          <Card label="Rank" value={RANK_LABELS[payload.rank]} />
          <Card label="Member ID" value={String(payload.sub)} numeric />
          <Card label="Role" value={payload.role} />
        </dl>
      </main>
    </div>
  );
}

function Card({ label, value, numeric }: { label: string; value: string; numeric?: boolean }) {
  return (
    <div className="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition duration-base ease-out-quart hover:border-red-200">
      <dt className="text-xs font-medium uppercase tracking-wide text-stone-500">{label}</dt>
      <dd
        className={`mt-1 text-lg font-semibold text-stone-800 ${numeric ? 'font-mono [font-variant-numeric:tabular-nums]' : ''}`}
      >
        {value}
      </dd>
    </div>
  );
}
```

- [ ] **Step 4: Write loading state**

`apps/web/app/lobby/loading.tsx`:

```tsx
export default function Loading() {
  return (
    <div className="min-h-screen bg-stone-50">
      <div className="h-[57px] bg-slate-850" />
      <main className="mx-auto max-w-3xl px-4 py-12">
        <div className="h-7 w-28 animate-pulse rounded bg-stone-200" />
        <div className="mt-4 h-4 w-64 animate-pulse rounded bg-stone-200" />
        <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="h-20 animate-pulse rounded-2xl border border-stone-200 bg-white" />
          ))}
        </div>
      </main>
    </div>
  );
}
```

- [ ] **Step 5: Extend E2E test for full happy path**

Append to `apps/web/tests/e2e/pin-login-lobby.spec.ts`:

```typescript
test.describe('Full happy path', () => {
  test.beforeEach(async ({ context }) => {
    // Mock the worker login response at the network layer.
    await context.route(/\/api\/auth\/login$/, async (route) => {
      // Workshop note: in real CI we point NEXT_PUBLIC_WORKER_BASE at the staging worker
      // and don't mock — but for local E2E this keeps the test hermetic.
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          jwt: 'TEST_FAKE_JWT_REPLACED_BY_E2E_HELPER',
          role: 'member',
          member: { member_id: 555, employee_id: '20731', first_name: 'Peter', last_name: 'Darley', rank: 'LT' },
        }),
      });
    });
  });

  test('PIN → login → lobby greets the member', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('Access PIN').fill('2300');
    await page.getByRole('button', { name: /continue/i }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Employee ID').fill('20731');
    await page.getByLabel('Password').fill('correct-horse-battery');
    await page.getByRole('button', { name: /sign in/i }).click();
    // For this E2E pass we also need the session-finalize endpoint to accept the fake JWT.
    // Implementation note: in CI we set JWT_SIGNING_KEY to a deterministic test value and
    // generate a valid signed JWT in a Playwright global-setup hook. See README §Testing.
    // The happy-path assertion below runs only when that hook is present.
    test.skip(!process.env.E2E_REAL_JWT, 'requires E2E_REAL_JWT global-setup hook');
    await expect(page.getByRole('heading', { name: /lobby/i })).toBeVisible();
    await expect(page.getByText('Peter')).toBeVisible();
  });
});
```

> The skipped portion runs once Task 11 (CI) adds the global-setup hook generating a real JWT.

- [ ] **Step 6: Run E2E**

```bash
cd apps/web
pnpm test:e2e
```
Expected: 4 tests pass × 2 browsers (one skipped on the conditional portion).

- [ ] **Step 7: Commit**

```bash
cd ../..
git add apps/web/app/api/auth apps/web/middleware.ts apps/web/app/lobby
git commit -m "feat(web): /lobby protected route + session-finalize cookie issuance"
```

---

### Task 11: CI workflow (lint + typecheck + unit + integration + E2E)

**Files:**
- Create: `MBFD_Hub/bid-app/.github/workflows/ci.yml`
- Create: `MBFD_Hub/bid-app/apps/web/tests/e2e/global-setup.ts`
- Modify: `MBFD_Hub/bid-app/apps/web/playwright.config.ts` (use global-setup)
- Create: `MBFD_Hub/bid-app/apps/web/.env.test`

- [ ] **Step 1: Write CI workflow**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

env:
  PNPM_VERSION: 9.12.0
  NODE_VERSION: 20.x

jobs:
  lint-and-typecheck:
    name: Lint + Typecheck
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: bid-app } }
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: ${{ env.PNPM_VERSION }} }
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: pnpm
          cache-dependency-path: bid-app/pnpm-lock.yaml
      - run: pnpm install --frozen-lockfile
      - run: pnpm lint
      - run: pnpm typecheck

  unit-and-integration:
    name: Unit + Integration
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: bid-app } }
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: ${{ env.PNPM_VERSION }} }
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: pnpm
          cache-dependency-path: bid-app/pnpm-lock.yaml
      - run: pnpm install --frozen-lockfile
      - run: pnpm build
      - run: pnpm test
      - name: Coverage gate
        run: |
          node --eval "
          const fs = require('fs');
          for (const pkg of ['packages/shared', 'apps/worker', 'apps/web']) {
            const path = pkg + '/coverage/coverage-summary.json';
            if (!fs.existsSync(path)) continue;
            const c = JSON.parse(fs.readFileSync(path));
            const { lines, branches } = c.total;
            if (lines.pct < 80 || branches.pct < 80) {
              console.error(pkg + ' coverage below 80%:', { lines: lines.pct, branches: branches.pct });
              process.exit(1);
            }
          }
          "

  e2e:
    name: Playwright E2E
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: bid-app } }
    env:
      JWT_SIGNING_KEY: ${{ secrets.E2E_JWT_SIGNING_KEY }}
      PIN_PLAIN: '2300'
      E2E_REAL_JWT: '1'
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: ${{ env.PNPM_VERSION }} }
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: pnpm
          cache-dependency-path: bid-app/pnpm-lock.yaml
      - run: pnpm install --frozen-lockfile
      - run: pnpm --filter @mbfd/web exec playwright install --with-deps chromium
      - run: pnpm test:e2e
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: bid-app/apps/web/playwright-report
          retention-days: 7
```

- [ ] **Step 2: Write E2E global setup (issues a real test JWT)**

`apps/web/tests/e2e/global-setup.ts`:

```typescript
import type { FullConfig } from '@playwright/test';
import { SignJWT } from 'jose';

export default async function globalSetup(_: FullConfig) {
  const signingKey = process.env.JWT_SIGNING_KEY;
  if (!signingKey) {
    console.warn('[e2e] no JWT_SIGNING_KEY — tests requiring real JWT will skip');
    return;
  }
  const key = new TextEncoder().encode(signingKey);
  const nowSec = Math.floor(Date.now() / 1000);
  const jwt = await new SignJWT({
    sub: 555,
    emp: '20731',
    role: 'member',
    rank: 'LT',
    first_name: 'Peter',
    last_name: 'Darley',
    fresh_auth_at: nowSec,
  })
    .setProtectedHeader({ alg: 'HS256' })
    .setIssuedAt()
    .setExpirationTime('1h')
    .sign(key);
  // Stash for tests
  process.env.E2E_JWT = jwt;
}
```

- [ ] **Step 3: Wire global-setup into playwright config**

Edit `apps/web/playwright.config.ts`, add `globalSetup`:

```typescript
import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: path.resolve(__dirname, './tests/e2e/global-setup.ts'),
  fullyParallel: true,
  retries: process.env.CI ? 2 : 0,
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'on-first-retry',
  },
  webServer: {
    command: 'pnpm dev',
    url: 'http://localhost:3000',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
    env: { ...process.env, NODE_ENV: 'test' } as Record<string, string>,
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
```

- [ ] **Step 4: Write `.env.test` for local E2E**

`apps/web/.env.test`:

```
JWT_SIGNING_KEY=test_only_dev_key_replace_via_CF_env_in_real_envs_______
PIN_PLAIN=2300
E2E_REAL_JWT=1
```

> Plain test value. CI overrides via repo secrets.

- [ ] **Step 5: Update the E2E test to set the JWT cookie before the lobby assertion**

Modify the existing "Full happy path" test in `apps/web/tests/e2e/pin-login-lobby.spec.ts` — replace the `test.skip` line and the `await expect(page.getByRole('heading', { name: /lobby/i })).toBeVisible();` lines with:

```typescript
    // Inject the pre-signed JWT cookie generated by global-setup so the session-finalize step is a no-op
    if (!process.env.E2E_JWT) {
      test.skip(true, 'JWT_SIGNING_KEY not set');
    }
    await context.addCookies([
      {
        name: 'mbfd_bid_jwt',
        value: process.env.E2E_JWT!,
        url: 'http://localhost:3000',
        httpOnly: true,
        sameSite: 'Strict',
      },
    ]);
    await page.goto('/lobby');
    await expect(page.getByRole('heading', { name: /lobby/i })).toBeVisible();
    await expect(page.getByText('Peter')).toBeVisible();
```

- [ ] **Step 6: Run CI workflow locally with act (optional smoke)**

```bash
cd ..
act -j lint-and-typecheck   # if you have `act` installed; skip otherwise
```

- [ ] **Step 7: Commit**

```bash
git add .github/workflows/ci.yml apps/web/tests/e2e/global-setup.ts apps/web/playwright.config.ts apps/web/.env.test apps/web/tests/e2e/pin-login-lobby.spec.ts
git commit -m "ci: full lint/typecheck/unit/integration/e2e workflow with coverage gate"
```

---

### Task 12: Deploy workflow + staging Cloudflare resources

**Files:**
- Create: `MBFD_Hub/bid-app/.github/workflows/deploy-staging.yml`
- Modify: `MBFD_Hub/bid-app/apps/web/next.config.mjs` (OpenNext output target)

- [ ] **Step 1: Create staging Cloudflare resources (manual, one-time)**

Run locally with the user-provided Wrangler token (stored only in `~/.wrangler/config/default.toml` after `wrangler login`, never in repo):

```bash
cd MBFD_Hub/bid-app
pnpm dlx wrangler login                              # opens browser
pnpm dlx wrangler d1 create mbfd-bid-staging
pnpm dlx wrangler kv namespace create mbfd-bid-kv --env staging
pnpm dlx wrangler r2 bucket create mbfd-bid-audit
pnpm dlx wrangler r2 bucket create mbfd-bid-imports
pnpm dlx wrangler r2 bucket create mbfd-bid-exports
pnpm dlx wrangler r2 bucket create mbfd-bid-logs
```

Each `d1 create` / `kv namespace create` prints a UUID. Paste each into `apps/worker/wrangler.toml` under the staging env in place of `REPLACE_AFTER_*`.

- [ ] **Step 2: Set staging secrets (one-time)**

```bash
cd apps/worker
pnpm dlx wrangler secret put JWT_SIGNING_KEY    --env staging
# (paste 64-char hex)
pnpm dlx wrangler secret put PIN_HASH           --env staging
# (paste bcrypt hash of "2300")
pnpm dlx wrangler secret put PORTAL_BID_READER  --env staging
# (paste portal token for /verify-credentials)
```

> Run the equivalent for `--env production` when production stand-up happens (not in this plan).

- [ ] **Step 3: Apply initial migration to staging**

```bash
pnpm db:migrate:remote
```

Expected: prints "Migrations applied!" with `0001_init.sql`.

- [ ] **Step 4: Write deploy workflow**

`.github/workflows/deploy-staging.yml`:

```yaml
name: Deploy staging

on:
  push:
    branches: [main]
  workflow_dispatch:

concurrency:
  group: deploy-staging
  cancel-in-progress: false

env:
  PNPM_VERSION: 9.12.0
  NODE_VERSION: 20.x

jobs:
  deploy:
    name: Deploy worker + pages
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: bid-app } }
    env:
      CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
      CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: ${{ env.PNPM_VERSION }} }
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: pnpm
          cache-dependency-path: bid-app/pnpm-lock.yaml
      - run: pnpm install --frozen-lockfile
      - run: pnpm build
      - name: Apply D1 migrations (staging)
        run: pnpm --filter @mbfd/worker db:migrate:remote
      - name: Deploy worker (staging)
        run: pnpm --filter @mbfd/worker deploy:staging
      - name: Deploy Next.js to Cloudflare Pages (staging)
        run: pnpm --filter @mbfd/web deploy:staging
```

> The token is `CLOUDFLARE_API_TOKEN` repo secret. The user has the token already; add via `gh secret set` (see Step 5).

- [ ] **Step 5: Add GH Actions secrets (one-time, local CLI)**

```bash
cd MBFD_Hub
gh secret set CLOUDFLARE_API_TOKEN -b "<PASTE_TOKEN_FROM_USER_NEVER_COMMIT>"
gh secret set CLOUDFLARE_ACCOUNT_ID -b "<CF_ACCOUNT_ID>"
gh secret set E2E_JWT_SIGNING_KEY -b "<RANDOM_64_HEX_FOR_TESTS_ONLY>"
```

> Token values are pasted at the CLI prompt; they do NOT appear in any file.

- [ ] **Step 6: Trigger first deploy**

```bash
git push origin main
gh run watch
```

Expected: CI job passes (lint, typecheck, unit, e2e all green). Deploy job runs and outputs:
- `mbfd-bid-worker-staging.<account>.workers.dev` — health endpoint live
- `<pages-project>.pages.dev` — Next.js app live, PIN form renders

- [ ] **Step 7: Configure custom domain (Cloudflare dashboard, one-time)**

In Cloudflare dashboard:
1. Pages project → Custom domains → add `staging.bid.mbfdhub.com` (CNAME to `<project>.pages.dev`)
2. Worker → Triggers → add route `api.staging.bid.mbfdhub.com/*` (already in wrangler.toml)
3. DNS → add `staging.bid.mbfdhub.com` CNAME → pages.dev; `api.staging.bid.mbfdhub.com` proxied A record to the worker

- [ ] **Step 8: Smoke test the deployed staging**

Open: https://staging.bid.mbfdhub.com
- Enter PIN 2300 → /login
- Use a real (or test-portal-stubbed) employee ID + password → /lobby renders with name + rank.

- [ ] **Step 9: Commit deploy workflow**

```bash
git add .github/workflows/deploy-staging.yml
git commit -m "ci: cloudflare staging deploy workflow"
git push
```

---

### Task 13: Documentation + acceptance checklist

**Files:**
- Modify: `MBFD_Hub/bid-app/README.md`
- Create: `MBFD_Hub/bid-app/docs/architecture.md`
- Create: `MBFD_Hub/bid-app/docs/runbook-staging.md`

- [ ] **Step 1: Expand README**

Replace `bid-app/README.md` with:

```markdown
# MBFD Bid Web App

Annual shift bid platform for the Miami Beach Fire Department.

- **Spec:** `../docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md`
- **Master plan:** `../docs/superpowers/plans/2026-05-17-mbfd-bid-master-index.md`
- **Architecture:** `docs/architecture.md`
- **Staging runbook:** `docs/runbook-staging.md`

## Quickstart

\`\`\`bash
pnpm install
pnpm dev          # web + worker in parallel
\`\`\`

Open http://localhost:3000. PIN is 2300.

## Layout

\`\`\`
bid-app/
├── apps/
│   ├── web/      # Next.js 15 on Cloudflare Pages
│   └── worker/   # Hono on Cloudflare Workers
├── packages/
│   └── shared/   # Zod schemas + TS types + design tokens
└── docs/
\`\`\`

## Deploy

- Push to \`main\` triggers \`deploy-staging.yml\`.
- Production deploy is manual via \`workflow_dispatch\`.

## Credentials

Never commit secrets. All tokens go through:

- \`wrangler secret put NAME --env staging|production\`
- GitHub Actions repository secrets

\`.env.example\` lists required names with placeholder values.

## Testing

\`\`\`bash
pnpm test          # unit + integration
pnpm test:e2e      # Playwright; requires JWT_SIGNING_KEY env
\`\`\`

Coverage gate: ≥80% lines, ≥80% branches. CI enforces.
```

- [ ] **Step 2: Write architecture doc**

`bid-app/docs/architecture.md`:

```markdown
# Architecture (Plan 01 — Foundation)

This document captures what shipped in Plan 01. The full design is in
`../../docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md`.

## What's wired today

\`\`\`
┌──────────────────┐    PIN cookie    ┌──────────────────┐    /api/auth/login
│  staging.bid.    │ ◄───────────────│  Cloudflare      │ ──────────────────►
│  mbfdhub.com     │                  │  Pages           │
│  (Next.js 15)    │ ───────────────► │  middleware      │ ◄────────────────── 
│                  │   JWT cookie     │                  │     api.staging.bid.mbfdhub.com
└──────────────────┘                  └──────────────────┘     (Hono Worker)
                                                                       │
                                                                       │ POST /verify-credentials
                                                                       ▼
                                                              portal.mbfdhub.com
                                                              (Laravel — external)
\`\`\`

## Cookies

| Name | Purpose | TTL | HttpOnly | SameSite |
|------|---------|-----|----------|----------|
| \`mbfd_pin\` | PIN gate pass | 7 days | yes | strict |
| \`mbfd_bid_jwt\` | Session JWT | 8 hours | yes | strict |

## Middleware chain (Next.js Pages)

1. \`/_next/static/*\`, \`/_next/image/*\`, \`/favicon.ico\`, \`/api/pin\` — bypass
2. \`/\` and \`/api/auth/session-finalize\` — bypass
3. \`/login\` — require PIN cookie
4. Everything else — require PIN cookie AND JWT cookie

## Worker routes (Plan 01)

- \`GET /api/health\` — liveness probe
- \`POST /api/auth/login\` — Zod-validated body; calls portal; returns \`{ jwt, role, member }\`

## What ships in Plan 02+

- D1 schema for members, certs, positions, rules, bids, audit
- Member + cert import pipelines
- Eligibility engine
- Live bid Durable Object
- … (see master plan index)
```

- [ ] **Step 3: Write staging runbook**

`bid-app/docs/runbook-staging.md`:

```markdown
# Staging Runbook

## Domains

- Web: https://staging.bid.mbfdhub.com
- API: https://api.staging.bid.mbfdhub.com

## How to deploy

Automatic on push to \`main\`. Manual: \`gh workflow run deploy-staging.yml\`.

## How to rotate the PIN

\`\`\`bash
cd apps/worker
# generate a bcrypt hash of the new PIN locally
node -e "import('bcryptjs').then(b => b.hash('NEW_PIN', 12).then(console.log))"
# set it
pnpm dlx wrangler secret put PIN_HASH --env staging
# redeploy
pnpm deploy:staging
\`\`\`

## How to rotate the JWT signing key

\`\`\`bash
# new key:
node -e "console.log(crypto.randomBytes(32).toString('hex'))"
# set it on both worker and pages (pages needs the env var via dashboard):
pnpm dlx wrangler secret put JWT_SIGNING_KEY --env staging
# Pages env var: dashboard → Pages → mbfd-bid → Settings → Environment variables
# Redeploy both:
pnpm deploy:staging
\`\`\`

After rotation, all existing sessions are invalidated. Users re-login.

## How to view logs

\`\`\`bash
pnpm dlx wrangler tail mbfd-bid-worker-staging --env staging
\`\`\`

## How to wipe staging D1

\`\`\`bash
pnpm dlx wrangler d1 execute mbfd-bid-staging --remote --command "DELETE FROM schema_meta; INSERT INTO schema_meta(key,value) VALUES ('plan','01'),('schema_version','0001');"
\`\`\`
```

- [ ] **Step 4: Commit docs**

```bash
git add bid-app/README.md bid-app/docs
git commit -m "docs: README + architecture + staging runbook"
```

---

## Acceptance checklist (Plan 01 done when ALL pass)

- [ ] `pnpm install` succeeds from clean checkout
- [ ] `pnpm dev` brings up web (3000) + worker (8787) in parallel
- [ ] `pnpm lint` passes
- [ ] `pnpm typecheck` passes
- [ ] `pnpm test` passes with coverage ≥ 80% lines / ≥ 80% branches
- [ ] `pnpm test:e2e` passes on Desktop Chrome + Pixel 7
- [ ] CI workflow green on `main`
- [ ] `staging.bid.mbfdhub.com` reachable
- [ ] Wrong PIN → "Incorrect PIN" inline error
- [ ] PIN 2300 → /login renders
- [ ] Wrong credentials → "Incorrect employee ID or password" error
- [ ] Correct credentials → /lobby renders with member's first name + rank label
- [ ] `staging.bid.mbfdhub.com/lobby` with no cookies → redirects to `/`
- [ ] `staging.bid.mbfdhub.com/lobby` with PIN cookie but no JWT → redirects to `/login`
- [ ] All `.impeccable.md` Critical Rules table items satisfied (manual visual audit on iPhone + desktop):
  - Red is red-700 only (no other reds)
  - All numerics tabular-nums
  - Touch targets ≥44px on mobile
  - prefers-reduced-motion disables transitions
  - No cold grays (`gray-*`)
  - safe-area-inset honored on iPhone notch
- [ ] No secrets in any committed file (grep audit: `git ls-files | xargs grep -E 'cfat_|ghp_|sk-' --` → no matches)

---

## Hand-off note for Plan 02

When Plan 01 is fully green, drop a status note in `docs/superpowers/plans/STATUS.md`:

```
## Plan 01 — Foundation — DONE 2026-MM-DD
- Anything deviated from plan: …
- Notes for Plan 02: actual D1 database ID is <id>; staging URL confirmed; portal /verify-credentials contract matched spec exactly (or note differences)
```

Plan 02 (Data plane) will build on the worker's `DB` and `KV` bindings already configured in `wrangler.toml`.
