# NotebookLM.md

# KNOWLEDGE BASE INTEGRATION (NOTEBOOKLM)
You have access to a NotebookLM MCP server containing extensive documentation, architecture decisions, and current-state reports for my primary project. 

**Target Project:** MBFD Hub (Laravel 11, Filament v3, React SPA, Dockerized VPS environment).
**Notebook ID:** 1f2a60f2-e047-4499-a43f-4e0f3157a743

**Rules for Engagement:**
1. AUTONOMOUS RESEARCH: Before asking me clarifying questions about the MBFD Hub architecture (e.g., database schemas, existing Filament panels like the Eval Feedback Hub, or Google Sheets sync logic), you MUST first use your MCP tools to query the Notebook ID provided above.
2. TOOL USAGE: Use the `notebook_query` tool to search for specific technical implementations or phase statuses within the MBFD Hub notebook. 
3. CONTEXT FIRST: Treat the information retrieved from NotebookLM as the absolute source of truth for the current state of the codebase.
4. LIMITED QUESTIONS: Only ask clarifying questions about the MBFD Hub if you cannot find the answer in the NotebookLM results.
5. FALLBACK: If you cannot find the answer in NotebookLM, you may ask me for clarification, but you must first explain why the information was not found in the knowledge base.

**Example Workflow:**
1. User asks: "How is the Google Sheets sync implemented in the Eval Feedback Hub?"
2. You query NotebookLM with the Notebook ID and the search term "Google Sheets sync Eval Feedback Hub".
3. You present the findings to the user, including code snippets, architecture decisions, and any relevant diagrams.
4. If the information is not found, you explain why and ask for clarification.

**Note:** Always prioritize information from NotebookLM over your own knowledge base when answering questions about the MBFD Hub.

---

## MCP Server Status (Updated 2026-03-05)

**Status:** ✅ Installed and authenticated  
**Config location:** `%APPDATA%\Code\User\globalStorage\kilocode.kilo-code\settings\mcp_settings.json`  
**Auth profile:** `~/.notebooklm-mcp/chrome-profile` (Google account: `pdarleyjr@gmail.com`)  

### Available MCP Tools
| Tool | Purpose |
|---|---|
| `notebook_query` | Ask a question against notebook sources |
| `notebook_list` | List all notebooks |
| `notebook_get` | Get notebook details + source list |
| `notebook_describe` | AI summary of notebook contents |
| `notebook_add_text` | Add a text document as a source |
| `notebook_add_url` | Add a URL as a source |
| `source_get_content` | Retrieve raw source text |
| `source_describe` | Get source metadata |
| `report_create` | Generate a study guide / briefing doc |
| `studio_status` | Check status of generated artifacts |
| `refresh_auth` | Re-extract CSRF/session if auth expires |

### Shell Compatibility Note
When running auth or any `npx @m4ykeldev/notebooklm-mcp` commands from within VS Code terminals, verify the active shell is PowerShell 7 (`pwsh`) rather than `cmd.exe`. The `Get-Content` cmdlet will fail in cmd — use `type` for reading files in cmd contexts or force `pwsh -Command "..."`.
