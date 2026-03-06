# 20_local_rag_workflow.md

# LOCAL-RAG WORKFLOW (MBFD HUB)

## Purpose
Use local-rag for fast semantic plus keyword-assisted discovery across high-signal MBFD Hub documentation and staged repo knowledge.

## When to use local-rag
Use local-rag when you need to:
- find where a concept is discussed across project documentation
- locate architecture notes, deployment constraints, or prior bug history
- discover which files or subsystems likely contain an implementation
- search staged code knowledge when exact file paths are not yet known

Do **not** use local-rag as the sole source of truth for exact code edits. After local-rag narrows the search, confirm details with filesystem reads.

## Tool routing
- Use local-rag for semantic discovery across documentation and staged repo context.
- Use filesystem for exact, path-specific reads and writes.
- Use NotebookLM for source-grounded synthesis across uploaded notebook sources.
- Use Context7 for upstream framework and library documentation.

## MBFD Hub ingestion strategy
Because [`mcp-local-rag`](.kilocode/mcp.json) directly ingests Markdown, TXT, DOCX, and PDF but not raw PHP files, this repo uses a staged approach:

### Stage 1: high-signal core docs
Ingest these first:
- [`.project_summary.md`](.project_summary.md)
- [`CLAUDE.md`](CLAUDE.md)
- [`AI_AGENT_ERRORS.md`](AI_AGENT_ERRORS.md)
- [`MBFD_HUB_DISCOVERY_REPORT_2026-02-12.md`](MBFD_HUB_DISCOVERY_REPORT_2026-02-12.md)

### Stage 2: staged code snapshots
Use workspace-local Markdown snapshots under [`.ai/mcp/`](.ai/mcp/) to capture high-signal details from key code directories without ingesting raw PHP directly:
- [`.ai/mcp/local-rag-stage-app-routes-config.md`](.ai/mcp/local-rag-stage-app-routes-config.md)
- [`.ai/mcp/local-rag-stage-resources-ui.md`](.ai/mcp/local-rag-stage-resources-ui.md)

These staged files summarize important implementation details from:
- [`app/`](app)
- [`routes/`](routes)
- [`config/`](config)
- [`resources/views/`](resources/views)
- [`resources/js/`](resources/js)

### Avoid these directories unless explicitly needed
Do not ingest bulk content from:
- [`vendor/`](vendor)
- [`node_modules/`](node_modules)
- [`storage/`](storage)
- [`bootstrap/cache/`](bootstrap/cache)

## Standard ingestion process for new docs
1. Ingest authoritative Markdown, PDF, DOCX, or TXT files directly when they are inside the workspace.
2. If a high-value implementation only exists in unsupported code files, create or update a concise staged Markdown snapshot under [`.ai/mcp/`](.ai/mcp/) and ingest that snapshot.
3. Re-ingest the updated file after major architecture or workflow changes.
4. Use targeted queries first; avoid broad, noisy searches.

## Query workflow
1. Start with a focused question such as:
   - "Where is Google Sheets apparatus sync implemented?"
   - "Where are VPS deployment constraints documented?"
   - "Which files define the daily checkout routes?"
2. Review the returned paths and snippets.
3. Open the exact files with filesystem tools for confirmation.
4. Use Context7 or NotebookLM if the answer depends on external or notebook-grounded context.

## Backup and persistence
The local-rag database is configured outside the repo for persistence:
- DB path: `C:\AI\mcp\local-rag\mbfd-hub\lancedb`
- Model cache: `C:\AI\mcp\local-rag\mbfd-hub\models`

To back up the RAG database, copy the entire [`C:\AI\mcp\local-rag\mbfd-hub\lancedb`](C:/AI/mcp/local-rag/mbfd-hub/lancedb) folder.

## Model change warning
Changing `MODEL_NAME` changes embedding dimensions.

If the model changes in [`.kilocode/mcp.json`](.kilocode/mcp.json), you must:
1. delete the existing database at [`C:\AI\mcp\local-rag\mbfd-hub\lancedb`](C:/AI/mcp/local-rag/mbfd-hub/lancedb)
2. re-ingest all tracked documents and staged Markdown snapshots

## Current MBFD Hub note
If local-rag search quality drops, verify that:
- the server is in hybrid mode
- the staged Markdown snapshots in [`.ai/mcp/`](.ai/mcp/) still reflect the current codebase
- recent architecture changes have been re-ingested
