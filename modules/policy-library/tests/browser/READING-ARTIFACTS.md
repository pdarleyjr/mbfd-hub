# Private source-native reading candidates

The reading option is an optional representation of an immutable published PDF revision. The original PDF remains available for every block. Existing Hub authentication, the user-bound library grant, current edition, visible node and revision membership apply to the reading endpoint. Private PDF or reading bytes must never enter a public service worker, public storage URL or unauthenticated cache.

## Build and review

Use the exact final native DOCX and the exact PDF bytes that the revision will serve, with the canonical source identity and title. The converter requires Python 3 with `lxml` and `pypdf`; it reads source files without modifying them or fetching assets.

```powershell
& $taskPython modules/policy-library/scripts/build-reading-view.py `
  --docx $exactDocx --pdf $exactServedPdf --identity $canonicalId `
  --title $sourceTitle --out-dir $privateReviewOutput
```

Output is `<artifact-sha256>.json` and `<artifact-sha256>.proof.json`. The artifact is `mbfd-reading-v1`, containing exact PDF/DOCX SHA-256 bindings, page count, visible native cover text and canonical entries. Proof records native body indexes, bookmarks, actual exported PDF destinations, source text hashes, measured PDF spans and table cells. Files containing source paths belong in review evidence, not the client response.

The converter's proof always returns `publication_validated: false`; the artifact itself carries no approval stamp. Its suggested revision metadata always has `reading_view.validated: false`. Successful conversion is not independent fidelity approval. Review the exact final native source, PDF rendering and every proof/fallback before approval. Missing source mappings withhold the artifact. Do not substitute guessed page numbers, calibration files or an older PDF.

For paragraphs, the actual source bookmark and next source destination bound the permitted PDF region, including actual continuation pages. Native header strings and native bottom margins bound the body region. When every native section has the same top margin, that proved uniform margin also caps the body region so a wrapped header fragment cannot enter continuation text. Different top margins do not receive an inferred page assignment. Only exact normalized source text within that region becomes reading text. Normalization permits Unicode normalization, whitespace and line-wrap hyphen continuation; artifact text itself retains native text. Ambiguous ranges or text mismatches produce an explicit original-paragraph PDF link. Repeated text elsewhere in a PDF cannot approve the assigned range.

Supported tables retain native `tblGrid`, `gridSpan`, vertical merge restarts/continuations and repeating header rows. Each visible cell paragraph requires its measured PDF destination/grid band to begin with the exact source text; proof records every native cell and paragraph. Empty cells retain structure and are explicitly bound to the verified table destination. Complex, nested, numbered, graphic, field-containing or unproved tables become an **original table or chart** PDF link. No cells are summarized or silently dropped. Numbered/field/graphic blocks similarly remain explicit PDF references. This representation does not claim that all decision tools reflow on a phone.

## Revision and delivery contract

After independent approval, create a **new** draft revision through the final publication import. Do not amend an immutable published revision. Bind its metadata to the approved artifact hash and exact source DOCX hash. This concrete 100.01 C2 sample remains pending publication approval; change `validated` to true only after the independent gates approve the exact pair:

```json
{
  "source_docx_sha256": "7b918a752a0b6f5e9ef4e32f4a39dc175f03d1ad87132da0a763096a0e71a2b8",
  "reading_view": {"sha256": "fb7f854345ae68dfa3dfc9516773df0dbb0e7ff195fe71d1d3ac870244aa46f5", "validated": false}
}
```

Install the exact approved JSON under the configured private storage root at `reading/<artifact-sha256>.json`. This is a proposed import contract; this candidate does not install, publish or activate artifacts. All final semantic ownership/pages and existing IDs, slugs, aliases, bookmarks and companion identities must come from the approved publication registry and actual final PDF destination map. Individual native PDFs include their real cover and all actual owned pages. A title inventory alone cannot assign pages.

`GET /api/nodes/{node}/reading?revision={uuid}` requires the current published revision and current active edition. The endpoint rejects wrong source/PDF hashes, changed artifact bytes, invalid pages, foreign entries, invalid merged grids and files outside the private reading directory. The catalog's `reading_url` stays null until metadata passes the optional validation gate. Responses are private/no-store, including CDN no-store, and expose plain data rather than HTML or host paths. The client creates DOM nodes with `textContent`. Reading content stays only in memory and is cleared on revision/manual changes. Failed delivery leaves the original PDF available through retry/fallback.

## Individual SOGs and full section books

Keep the eight existing asset identities `SECTION-100`, `SECTION-200`, `SECTION-300`, `SECTION-400`, `SECTION-500`, `SECTION-600`, `SECTION-800` and `SECTION-900`, their full-section buttons/downloads and existing alias records. Prevention remains Section 600. Individual canonical asset IDs take precedence when a section book and a leaf both contain the same primary identity, regardless of catalog order. Full sections remain directly selectable by their existing node/asset identity.

During the governed new-edition import, persist `search_role: aggregate` in each full section's `node_metadata` and in each new revision's `metadata`. The revision must also declare `leaf_asset_ids` containing its **complete reviewed canonical coverage** from the actual source ownership map. This declaration is a release requirement, not a heuristic based on a title. The persistent node declaration survives a standard admin replacement upload. A replacement without the reviewed revision role and complete coverage remains withheld; do not infer or copy coverage from a different PDF. The tree and every protected document membership check withhold a declared aggregate unless every listed leaf is visible, currently published, identity-bound and in the same active manual edition. Missing role or coverage metadata, hidden parents, drafts, old editions and another manual all fail closed. There is no automatic aggregate fallback that could reveal a hidden leaf. An existing legacy edition without this new declaration retains its prior behavior; final publication must not omit either declaration from new section books.

Default search excludes complete declared aggregates to avoid duplicate whole-book/individual hits. Explicit queries for the aggregate asset ID remain possible when it passes all visibility checks. Existing subject alias mappings resolve to the canonical leaf when present; their semantic identities and stored source records are retained.

## Portable PDF cross-references

Keep the served source PDF bytes unchanged. Portable `/GoToR` actions often name a peer file and an actual PDF destination rather than a web URL. The import must inspect each actual action and destination, then bind revision `peer_links` to its annotation ID, source page, exact rectangle, source PDF SHA-256, canonical target asset ID, target PDF SHA-256 and measured target page. PDF name syntax and literal text strings have different semantics: strip the slash syntax only from a `NameObject`, never from a literal string by guess. Missing or ambiguous destinations withhold the binding/import.

The viewer resolves a proved binding only when the currently visible tree contains exactly one identity-bound current target with the expected PDF hash and page. Hidden targets, another revision, duplicate identities, changed source hashes or annotation rectangles reject the binding. A rejected declared binding never falls back to the raw file URL. Existing unbound local PDF and URI links retain their prior behavior. This adapter changes navigation metadata and DOM overlays; it does not rewrite approved leaf PDFs or their independently approved reader artifacts.

`tests/browser/peer-link-regression.mjs` exercises an actual source annotation and exact target PDF through the loopback fixture, then removes/tampers with its target/source bindings. Run it with the same private fixture environment as the reading tests. Fixture success is separate from final import preflight and authenticated live acceptance.

## Deterministic local UI checks

`tests/browser/reader-server.mjs` is a loopback fixture adapter, not production Hub authentication. `POLICY_LIBRARY_READING_PREVIEW` points to a task-owned manifest specifying an actual content-addressed artifact, exact matching PDF path and source-derived nodes. The adapter marks the active edition as a diagnostic C2 preview. Its revision identity is a source hash for UI testing, not a production database UUID. Backend authentication, immutable revision binding and validation gates are tested independently by `ReadingViewTest.php`.

```powershell
$env:POLICY_LIBRARY_BROWSER_FIXTURE = $privateEvidenceDirectory
$env:POLICY_LIBRARY_READING_PREVIEW = $previewManifest
$env:LIBRARY_PORT = '8878'
& $taskNode tests/browser/reader-server.mjs
# In another task-owned process:
$env:POLICY_LIBRARY_BROWSER_URL = 'http://127.0.0.1:8878'
& $taskNode tests/browser/reading-text-regression.mjs
```

At 390 px, the exact C2 100.01 policy paragraph must wrap at 18 px, require zero horizontal pan taps, retain the visible cover/date/not-adopted status and switch to the exact original PDF page. Tables must match every artifact cell, span and header in the DOM, with any required horizontal scrolling confined to their own table region. These are sample checks; final 151-document fidelity and actual-device/live acceptance remain separate gates.

## Rollback

There is no schema migration. Preserve the verified prior Hub immutable image and publication edition/source manifest before activation. Roll back viewer code through the normal targeted deployment contract; prior revisions contain no reading metadata and remain PDF-only. If an approved reading artifact has a fidelity issue, withhold the affected new revision/edition or create a corrected new revision through the governed import rather than changing immutable metadata or bytes. Artifact removal or a missing/invalid artifact fails closed; PDF delivery remains governed by its existing independent endpoint.
