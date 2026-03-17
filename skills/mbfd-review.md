# MBFD Review Skill — DeerFlow 2.0

## Purpose
Automated review cycle for MBFD Hub pull requests and deployments. Integrates production observability, log analysis, UI validation, and Impeccable design enforcement.

---

## Checklist (Original + Enhanced)
1. **Error Prevention**: Cross-reference all changes against AI_AGENT_ERRORS.md
2. **Design Compliance**: No @apply, no pure grays, no nested cards, correct typography
3. **Environment Boundary**: No production DB changes from local; no DeerFlow on VPS
4. **Filament Compatibility**: v3 components only, no deprecated blade components
5. **Package Integrity**: No imports for uninstalled packages
6. **API Shape**: Frontend and backend data contracts match
7. **Deployment Readiness**: CSS changes require `npm run build` inside Docker on VPS
8. **Permission Safety**: Storage/cache permissions fixed after container recreation

---

## 1. Production Verification — Uptime Kuma API Ping (MANDATORY)

Before any PR merge or deployment approval, verify production health.

```python
import requests

def check_production_health():
    """Query Uptime Kuma API at VPS for production status."""
    try:
        response = requests.get("http://145.223.73.170:3001/api/status-page/heartbeat", timeout=10)
        if response.status_code != 200:
            raise Exception(f"Uptime Kuma returned {response.status_code}")
        data = response.json()
        for monitor in data.get("heartbeatList", {}).values():
            latest = monitor[-1] if monitor else None
            if latest and latest.get("status") != 1:
                raise Exception(f"Monitor DOWN: {latest.get('name', 'unknown')}")
        return True
    except Exception as e:
        return False, str(e)

# CRITICAL FAILURE STATE:
# If status is not 200 OK → HALT THE PULL REQUEST PROCESS.
# No further action is permitted until production state is restored.
```

---

## 2. Debugging Protocol — Dozzle Log Retrieval

Upon detection of a 500-series error, retrieve and analyze container logs:

```python
import requests

def retrieve_laravel_logs():
    """Query Dozzle API for laravel.test container logs."""
    try:
        response = requests.get(
            "http://145.223.73.170:8888/api/logs/mbfd-hub-laravel.test-1",
            params={"since": "5m", "level": "error"},
            timeout=15
        )
        if response.status_code == 200:
            logs = response.json()
            errors = [
                entry for entry in logs
                if "500" in str(entry.get("message", ""))
                or "Exception" in str(entry.get("message", ""))
                or "ERROR" in str(entry.get("level", "")).upper()
            ]
            return errors
    except Exception as e:
        return [{"error": f"Dozzle query failed: {str(e)}"}]
```

**Targeted Container**: `mbfd-hub-laravel.test-1`

---

## 3. UI Validation — Headless Playwright via Browserless

Before concluding any frontend task, trigger a headless Playwright test:

```python
from playwright.async_api import async_playwright

async def validate_ui():
    """Run headless Playwright tests via local Browserless instance."""
    async with async_playwright() as p:
        browser = await p.chromium.connect_over_cdp("ws://localhost:3000")
        page = await browser.new_page()
        routes = [
            ("https://www.mbfdhub.com/", 200),
            ("https://www.mbfdhub.com/admin/login", 200),
            ("https://www.mbfdhub.com/daily/", 200),
            ("https://www.mbfdhub.com/daily/stations", 200),
            ("https://www.mbfdhub.com/daily/forms-hub", 200),
        ]
        results = []
        for url, expected_status in routes:
            response = await page.goto(url)
            status = response.status if response else 0
            results.append({"url": url, "status": status, "passed": status == expected_status})
        await browser.close()
        return results
```

---

## 4. Impeccable Design Audit (MANDATORY for all UI changes)

### Color Space
- ✅ OKLCH color space and tinted neutrals required
- ❌ Pure blacks (#000) and pure grays (#808080) PROHIBITED

### Structure
- ❌ NO `@apply` directives in CSS (iOS Safari crash — ERROR-031)
- ❌ NO nested cards ("card-in-a-card" patterns)

### Typography
- ❌ NO generic defaults (Inter, Arial, system-ui)
- ✅ Plus Jakarta Sans (headings) + Source Sans 3 (body)

### Automated Steps
1. Run `/audit` on every generated React component
2. Run `/polish` before presenting code
3. Verify WCAG AA contrast (4.5:1 body, 3:1 large)
4. Confirm no `@apply` directives
5. Verify OKLCH or warm-tinted CSS properties

---

## Review Workflow

```
PR Submitted
  ├─ Step 1: Uptime Kuma Health Check (MANDATORY 200 OK or HALT)
  ├─ Step 2: If errors → Dozzle Log Retrieval (target: laravel.test)
  ├─ Step 3: Headless Playwright UI Tests (via Browserless ws://localhost:3000)
  ├─ Step 4: Impeccable Design Audit (OKLCH, no @apply, tinted neutrals)
  └─ Step 5: Cross-reference AI_AGENT_ERRORS.md for known pitfalls
```

---

## Integration Points

| Tool | Host | Port | Purpose |
|------|------|------|---------|
| Uptime Kuma | VPS (145.223.73.170) | 3001 | Production health gate |
| Dozzle | VPS (145.223.73.170) | 8888 | Error log retrieval |
| Web-Check | VPS (145.223.73.170) | 3000 | Security header audit |
| Browserless | Local | 3000 | Headless UI testing |
| Pgweb | Local | 8081 | Database inspection |

## Output
Produce a review report with PASS/FAIL for each checklist item, observability verification results, and specific line references for any failures.
