# 00_tool_routing.md

# TOOL ROUTING POLICY (MBFD HUB)

## When to use each tool

### sequential-thinking
Use first for any task that is multi-step or expected to take more than 10 minutes. Break the problem into numbered steps before executing.

### filesystem (read_file / edit_file / list_files / search_files)
Use for exact, path-specific reads and writes. This is the single source of truth for file contents.

### local-rag (query_documents / ingest_file / ingest_data / list_files / status)
Use for "find where this is discussed or implemented" across the codebase and project documentation. After local-rag narrows the search, confirm with filesystem reads.

### NotebookLM (ask_question / list_notebooks / select_notebook / get_notebook / get_health)
Use for source-grounded research and synthesis across uploaded project docs (architecture decisions, SOGs, vendor specs, phase reports). If NotebookLM returns empty or blank answers, treat it as an auth failure and fall back to local-rag + filesystem + context7.

### context7 (resolve-library-id / query-docs)
Use for latest upstream framework and library documentation (Laravel, Filament, React, Zustand, Vite, etc.). Prefer context7 over web search for API references.

### playwright (browser_navigate / browser_snapshot / browser_click / browser_type / etc.)
Use for browser-driven verification, web interaction testing, and visual confirmation of deployed pages.

### GitHub MCP (create_pull_request / list_issues / list_commits / search_code / etc.)
Use for PRs, issues, CI logs, commit history, and repo-level workflows on `pdarleyjr/mbfd-hub`.

## Fallback chain
If the primary tool for a task fails or returns empty results:
1. Try the next most relevant tool from the list above.
2. If NotebookLM fails → local-rag → filesystem → context7.
3. If local-rag fails → filesystem → context7.
4. Only ask the user for clarification after exhausting tool-based research.
