# NotebookLM.md

# KNOWLEDGE BASE INTEGRATION (NOTEBOOKLM)
You have access to the current `notebooklm-mcp@latest` MCP server for source-grounded research against the MBFD Hub knowledge base.

**Target Project:** MBFD Hub (Laravel 11, Filament v3, React SPA, Dockerized VPS environment).  
**Notebook URL:** `https://notebooklm.google.com/notebook/1f2a60f2-e047-4499-a43f-4e0f3157a743?authuser=1`  
**Google account:** `pdarleyjr@gmail.com`

**Rules for Engagement:**
1. AUTONOMOUS RESEARCH: Before asking clarifying questions about MBFD Hub architecture, implementation details, deployment constraints, or prior phase status, first consult NotebookLM.
2. TOOL USAGE: Prefer the current NotebookLM MCP tools:
   - `ask_question` for source-grounded research and synthesis
   - `list_notebooks` / `select_notebook` / `get_notebook` for library selection
   - `add_notebook` only after explicit user confirmation
   - `setup_auth` or `re_auth` when authentication is missing or expired
   - `get_health` to verify authentication/server readiness
3. CONTEXT FIRST: Treat NotebookLM results as the source of truth for MBFD Hub architecture and project history when available.
4. LIMITED QUESTIONS: Only ask the user for clarification after NotebookLM does not provide the needed answer.
5. FALLBACK: If NotebookLM is unavailable, empty, or unauthenticated, explicitly state that and fall back to local-rag + filesystem + context7.

**Standard Workflow:**
1. Check NotebookLM health if auth status is uncertain.
2. Use the MBFD Hub notebook if it is already in the library; otherwise use the direct notebook URL for ad-hoc queries or add it to the library after confirmation.
3. Ask focused technical questions and use the returned synthesis to guide implementation.
4. If NotebookLM returns empty or blank answers, treat that as an auth/session failure and switch to fallback research tools.

**Example Workflow:**
1. User asks: "How is the Google Sheets sync implemented in MBFD Hub?"
2. Query NotebookLM about Google Sheets sync, apparatus observer, sync job, and deployment constraints.
3. Summarize the findings grounded in notebook sources.
4. If the response is empty or auth fails, say so and continue with local repo evidence.

**MCP Server Status**
- **Canonical config location:** `C:\Users\Peter Darley\Desktop\Support Services\.kilocode\mcp.json`
- **Server package:** `notebooklm-mcp@latest`
- **Auth profile/data:** `%LOCALAPPDATA%\notebooklm-mcp\`
- **Auth rule:** Never store passwords or enable auto-login; the user logs in manually when prompted.

### Available Current NotebookLM MCP Tools
| Tool | Purpose |
|---|---|
| `ask_question` | Ask NotebookLM a grounded question |
| `list_notebooks` | Show saved notebooks |
| `get_notebook` | Inspect notebook metadata |
| `select_notebook` | Set active notebook |
| `add_notebook` | Add a notebook to the library after explicit approval |
| `update_notebook` | Update notebook metadata after explicit approval |
| `remove_notebook` | Remove notebook from library after explicit approval |
| `get_health` | Verify authentication and server readiness |
| `setup_auth` | Open browser for first-time login |
| `re_auth` | Reset auth and log in again |
| `cleanup_data` | Deep cleanup when auth/browser state is broken |

### Failure Handling
- If NotebookLM returns an empty/blank answer, assume authentication or session failure.
- Use `get_health` first, then `setup_auth` or `re_auth` if needed.
- If NotebookLM remains unavailable, fall back to local-rag for semantic repo discovery, filesystem for exact file truth, and context7 for upstream library docs.

### Shell Compatibility Note
When using terminal commands related to NotebookLM or MCP configs on Windows, prefer PowerShell 7 (`pwsh`). The workspace path contains a space, so use full absolute paths in commands.
