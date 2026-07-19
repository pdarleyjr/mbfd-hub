# F-ROC Smart Entry and Activity Import

## Outcome

This enhancement keeps FROC-LOG-001-FF v11 inside the existing Employee Portal and preserves the controlled PDF workflow. It adds an authenticated employee lookup, the source PDF's exact category and conditional description choices, and a review-first activity-note importer backed by the configured MBFD AI service.

The import never creates or completes a form automatically. It produces suggestions, marks estimated values, and requires the member to create and review an ordinary editable draft before PDF generation.

## Integration map

| Surface | Implementation | Integrity boundary |
|---|---|---|
| Employee lookup | `GET /employee/forms/api/employees/search?q=` | Employee guard, throttled, returns only ID/name/rank, maximum 10 results |
| Controlled choices | F-ROC form-type metadata | Category is exactly `A`, `B`, or `N/A`; descriptions are the 27/32/59 source-ordered choices |
| Manual personnel | Free-text ID and name fields remain editable | Directory selection is optional; outside members are supported |
| Import preview | `POST /employee/forms/api/froc/import-preview` | Employee guard, throttled, no database write, no raw source persistence |
| AI inference | Existing `CloudflareAIService` container binding | GMKtec `qwen3.6:35b` through Ollama when `AI_DRIVER=local`; Workers AI when configured otherwise |
| AI failure | Deterministic rules-based extractor | Returns a review warning instead of losing the import |
| Draft creation | Existing create and optimistic-lock save APIs | Suggestions are stripped of provenance metadata before validated form data is saved |
| PDF | Existing controlled AcroForm generator | No HTML/PDF shortcut; authoritative server totals and immutable versioning remain unchanged |

## Source processing design

The recommended user workflow accepts the WhatsApp ZIP directly. Requiring manual extraction adds friction on iOS and Android without reducing model work. The server therefore:

1. accepts `.zip`, `.txt`, or pasted text;
2. opens ZIPs in memory and reads `.txt` entries only;
3. rejects traversal paths, more than 10 entries, unsafe compression ratios, files over 2 MB, and extracted text over 512 KB;
4. ignores media and instructs users to export without media;
5. removes WhatsApp directional/control characters;
6. discards encryption notices, group-management messages, deleted messages, instructional examples, and messages explicitly marked `Example only`;
7. filters to the user-supplied unit designation before any AI request;
8. hashes the source for diagnostic correlation but does not persist the raw chat or hash in the form record.

This makes direct ZIP support more efficient for members and computationally equivalent to manual extraction because only bounded text reaches the parser or model.

## Extraction contract

Deterministic code owns source filtering, event-name derivation, unit designation, and odometer extraction. The model receives only matched messages and the controlled F-ROC option catalog. Its response must be JSON and is treated as untrusted input.

Each accepted labor suggestion must include:

- a valid source-message index;
- category `A`, `B`, or `N/A`;
- a professional controlled or custom description;
- optional source-supported location;
- valid 24-hour start and end times;
- an explicit `end_estimated` marker;
- `high` or `review` confidence.

The application revalidates source indexes, categories, lengths, and time formats. A malformed, empty, timed-out, or unavailable AI response falls back to the deterministic extractor. The interactive Ollama request is capped below the edge-proxy timeout so the browser can still receive that fallback.

## Responsive interaction model

The implementation is phone-first while retaining a dense operational desktop layout:

- 44 px minimum touch targets and native mobile category selects;
- a large paste area rather than a one-line prompt;
- an inline start panel instead of a modal chain;
- a visible source → inference → member-review evidence spine;
- labor rows rendered as bounded activity cards, avoiding the former wide-table clipping;
- hour corrections progressively disclosed under each labor row;
- a bottom command bar and horizontal section navigation on phones;
- employee matches rendered as a touch-safe list while manual input remains available.

## Validation and test plan

Automated checks cover:

- authenticated employee search, match ordering, and response-field minimization;
- unit-only parsing, exclusion of example/other-unit mileage, fallback behavior, and estimated-time marking;
- exact category validation and 27/32/59 option counts/order;
- existing autosave, conflict, authorization, private document, totals, and controlled-PDF suites;
- TypeScript strict compilation and the production Vite build;
- Playwright phone acceptance for the import area, 44 px category control, labor-card layout, and horizontal overflow;
- desktop browser acceptance for the import review, conditional descriptions, employee auto-fill, and unchanged PDF workflow.

Manual acceptance uses `WhatsApp Chat - Bronze Game Activity Log.zip` with at least `R6`, `JHAT`, `Gator 2`, and `Detail Medic 2` to confirm irrelevant-unit exclusion, starting/arrival mileage, explicit internal timestamps, and editable estimated ends.

## Deployment and rollback

Deployment follows the existing MBFD Hub release process: back up the live source/database state, stage the exact committed tree, build assets, run migrations (none are required by this enhancement), clear/prime Laravel caches, restart workers if service code is cached, and validate authenticated employee and admin paths through the production hostname.

Rollback restores the prior release tree and Vite manifest. No schema rollback or data conversion is needed because the feature adds no database columns and stores imported suggestions in the existing validated F-ROC JSON structure.
