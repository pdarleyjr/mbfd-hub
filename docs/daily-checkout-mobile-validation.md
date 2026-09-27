# Daily Checkout mobile validation — 2026-09-26

## Provenance and limits

The pre-change production baseline used Peter Darley's existing signed-in browser session in a separate temporary in-app-browser tab: 25 initial apparatus/viewport combinations, five E2 L4 selections, and 30 screenshots. No production inspection was submitted. The temporary tab was closed and viewport reset. These observations prove the existing live workflow, not deployment of this change.

The exact before/after comparison below uses the local production bundle, real repository checklist JSON, and real apparatus artwork with a controlled authenticated-identity/API fixture. Live findings and previous readings differ from the fixture, so live measurements are not used to calculate improvement percentages. All submission writes in browser tests are intercepted local fixtures. No live acceptance or physical-device keyboard acceptance is claimed.

Local evidence: `test-results/live-baseline/measurements.json`, `test-results/live-baseline/E2-selected-measurements.json`, `test-results/mobile-baseline/`, and `test-results/mobile-final-verified/`. Final `baseline-comparison.json` and `.csv` include every requested component height for all 25 pairs. Screenshots stay local except the three reviewed visual-regression baselines.

Live E2 baseline, document coordinates in pixels:

| Viewport | Initial first action Y | Selected L4 first action Y | Selected page height |
|---|---:|---:|---:|
| 390×844 | 874 | 913 | 1495 |
| 430×932 | 890 | 920 | 1474 |
| 768×1024 | 962 | 992 | 1565 |
| 1024×768 | 399 | 438 | 1038 |
| 1440×900 | 412 | 442 | 1016 |

## Controlled comparison

All values are before → after. Selection is the same final zone of the initial view on both builds (E2: L4). First action means the first item Pass/Record button. Page height reflects that selected compartment, not the longer initial compartment. Swipes are estimates: ceil(max(0, action bottom − usable viewport)/(0.75 × usable viewport)), where usable viewport excludes the fixed bottom bar. No physical gesture count was measured. Selected viewport screenshots retain the post-selection scroll position; full-page snapshots normalize scroll to zero to avoid sticky-header capture artifacts.

| Unit | Viewport | Page px | First action Y px | Y / viewport height | Estimated swipes |
|---|---|---:|---:|---:|---:|
| E1 | 390×844 | 1303 → 844 | 853 → 419 | 1.01 → 0.50 | 1 → 0 |
| E2 | 390×844 | 1378 → 905 | 853 → 419 | 1.01 → 0.50 | 1 → 0 |
| FB6 | 390×844 | 1479 → 988 | 879 → 434 | 1.04 → 0.51 | 1 → 0 |
| L1 | 390×844 | 2128 → 1575 | 853 → 419 | 1.01 → 0.50 | 1 → 0 |
| R3 | 390×844 | 1329 → 861 | 879 → 441 | 1.04 → 0.52 | 1 → 0 |
| E1 | 430×932 | 1318 → 932 | 868 → 419 | 0.93 → 0.45 | 1 → 0 |
| E2 | 430×932 | 1393 → 932 | 868 → 419 | 0.93 → 0.45 | 1 → 0 |
| FB6 | 430×932 | 1494 → 988 | 895 → 434 | 0.96 → 0.47 | 1 → 0 |
| L1 | 430×932 | 2143 → 1575 | 868 → 419 | 0.93 → 0.45 | 1 → 0 |
| R3 | 430×932 | 1344 → 932 | 895 → 419 | 0.96 → 0.45 | 1 → 0 |
| E1 | 768×1024 | 1407 → 1024 | 941 → 421 | 0.92 → 0.41 | 1 → 0 |
| E2 | 768×1024 | 1484 → 1024 | 941 → 421 | 0.92 → 0.41 | 1 → 0 |
| FB6 | 768×1024 | 1560 → 1024 | 941 → 412 | 0.92 → 0.40 | 1 → 0 |
| L1 | 768×1024 | 2249 → 1608 | 941 → 421 | 0.92 → 0.41 | 1 → 0 |
| R3 | 768×1024 | 1407 → 1024 | 941 → 421 | 0.92 → 0.41 | 1 → 0 |
| E1 | 1024×768 | 867 → 876 | 400 → 409 | 0.52 → 0.53 | 0 → 0 |
| E2 | 1024×768 | 944 → 953 | 400 → 409 | 0.52 → 0.53 | 0 → 0 |
| FB6 | 1024×768 | 1069 → 1069 | 427 → 427 | 0.56 → 0.56 | 0 → 0 |
| L1 | 1024×768 | 1709 → 1718 | 400 → 409 | 0.52 → 0.53 | 0 → 0 |
| R3 | 1024×768 | 894 → 903 | 427 → 436 | 0.56 → 0.57 | 0 → 0 |
| E1 | 1440×900 | 959 → 968 | 413 → 422 | 0.46 → 0.47 | 0 → 0 |
| E2 | 1440×900 | 959 → 968 | 413 → 422 | 0.46 → 0.47 | 0 → 0 |
| FB6 | 1440×900 | 1033 → 1033 | 413 → 413 | 0.46 → 0.46 | 0 → 0 |
| L1 | 1440×900 | 1722 → 1731 | 413 → 422 | 0.46 → 0.47 | 0 → 0 |
| R3 | 1440×900 | 959 → 968 | 413 → 422 | 0.46 → 0.47 | 0 → 0 |

E2 component density, pixels (expanded picker/image; selected equipment row):

| Width | Authority | Expanded picker | Image | Row | Bottom bar | Tabs |
|---:|---:|---:|---:|---:|---:|---:|
| 390 | 161 → 97 | 377 → 352 | 129 → 129 | 68 → 60 | 65 → 57 | 45 → 52 |
| 430 | 161 → 97 | 393 → 348 | 144 → 144 | 68 → 60 | 65 → 57 | 45 → 52 |
| 768 | 98 → 97 | 521 → 484 | 261 → 268 | 70 → 62 | 65 → 57 | 45 → 54 |
| 1024 | 99 → 99 | 452 → 452 | 193 → 193 | 70 → 70 | 65 → 65 | 45 → 54 |
| 1440 | 112 → 112 | 540 → 540 | 281 → 281 | 70 → 70 | 65 → 65 | 45 → 54 |

At 390 and 430, all five E2 L4 equipment actions fit in the first selected-compartment viewport. At 1024 and 1440, the picker and equipment remain side by side. The 390/430 phone screenshots, 768/1024 layouts, and normalized 1440 desktop baseline were manually inspected. The new tests verify actual E2 image bytes, every Driver/Officer SVG rectangle, selected-state synchronization, 44px Pass targets, collapse/reopen, and focused expanded Notes above the bottom bar.

## Requirements and workflow composition

The ordinary source templates contain required paper checks, so Details remains. Optional controls use native disclosure, while Readings is explicitly optional and can be bypassed through the existing workspace navigation/readiness flow. Required shift, identity, signature, typed identifiers, and explicit equipment observation remain enforced. See [field requirement matrix](daily-checkout-field-requirements.md).

The authorized mileage required-flag change naturally changes checklist-version hashes. Old-version drafts retain the existing conflict/quarantine behavior; these changes do not bypass it. The focused full-source E2 test verifies null engine hours/miles, a preserved `{id: 'mileage', value: null}` paper field, every issued paper/equipment ID, version, signature, owner, and an identical queued/replayed payload.

## Verification results

- Existing browser suite: 233 passed, five planned snapshot skips, and three missing-new-baseline results during baseline creation (14.4 minutes). All three were subsequently checked successfully without update mode; no functional failure remains.
- Final frozen-source mobile suite plus refreshed visual baselines: 48 passed, two planned viewport snapshot skips (1.6 minutes).
- Separate assertion-only visual checks plus full E2 blank-meter payload: five passed, three intentional project-scope skips.
- Strengthened full E2 blank-meter/issued-ID/replay check: two passed, Chromium 390 and WebKit iPhone.
- Distinct verified browser cases: 283 (236 existing + 45 new responsive + two blank-meter contract cases), excluding repeated runs/skips.
- Parent-run PHP: 114 tests / 901 assertions (79 / 631 paper fields and automatic processing; 35 / 270 session contract and processing). Isolated SQLite in-memory environment, external integrations disabled, PHP sodium enabled. A temporary generated test index for two session-page cases was removed afterward. TypeScript and Pint passed.

Coverage includes real-service-worker offline shell startup, multi-MB photo reload, exactly-once replay, owner/security-version quarantine, same-member restoration, ambiguous-response idempotency, immutable checklist-version conflicts, Fire Boat server-issued-session handling, historical findings, signature, rollback review, raw invalid readings and tab bypass, and reduced-motion keyboard operation. Automated WebKit runs simulate iPhone/iPad; they are not physical-device acceptance.

Exact browser commands (repository root; each builds the disposable preview; run sequentially):

```powershell
node node_modules/@playwright/test/cli.js test --config=playwright.daily-checkout.config.ts daily-checkout-mobile.spec.ts --project=daily-responsive-phone-390 --project=daily-responsive-phone-430 --project=daily-responsive-tablet-768 --project=daily-responsive-tablet-landscape-1024 --project=daily-responsive-wide-1440 --output=test-results/mobile-baseline

node node_modules/@playwright/test/cli.js test --config=playwright.daily-checkout.config.ts daily-checkout-workspace.spec.ts daily-checkout-inspection.spec.ts daily-checkout-service-worker.spec.ts --project=daily-checkout-chromium --project=daily-workspace-webkit-iphone --project=daily-workspace-webkit-ipad --project=daily-responsive-phone-390 --project=daily-responsive-phone-430 --project=daily-responsive-tablet-768 --project=daily-responsive-tablet-landscape-1024 --project=daily-responsive-wide-1440 --project=daily-pwa-chromium --update-snapshots=missing --output=test-results/mobile-existing-final

node node_modules/@playwright/test/cli.js test --config=playwright.daily-checkout.config.ts daily-checkout-mobile.spec.ts daily-checkout-workspace.spec.ts --grep 'mobile workflow|E2 mobile selection|E2 collapsed|E2 readings|invalid raw|refined active' --project=daily-responsive-phone-390 --project=daily-responsive-phone-430 --project=daily-responsive-tablet-768 --project=daily-responsive-tablet-landscape-1024 --project=daily-responsive-wide-1440 --update-snapshots --output=test-results/mobile-final-verified

node node_modules/@playwright/test/cli.js test --config=playwright.daily-checkout.config.ts daily-checkout-workspace.spec.ts --grep 'refined active|actual Engine 2 blank readings' --project=daily-responsive-phone-390 --project=daily-responsive-tablet-768 --project=daily-responsive-wide-1440 --project=daily-workspace-webkit-iphone --output=test-results/mobile-final-contract

node node_modules/@playwright/test/cli.js test --config=playwright.daily-checkout.config.ts daily-checkout-workspace.spec.ts --grep 'actual Engine 2 blank readings' --project=daily-responsive-phone-390 --project=daily-workspace-webkit-iphone --output=test-results/mobile-blank-contract-final
```

The first command was run before UI edits when the mobile file contained only the 25 measurement cases. Normal repeat verification should omit snapshot-update flags.
