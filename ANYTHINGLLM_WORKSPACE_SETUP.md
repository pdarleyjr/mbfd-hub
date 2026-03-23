> **STATUS (2026-03-23)**: ✅ FULLY DEPLOYED — AnythingLLM Docker container (`anythingllm-web`) running in WSL on port 3001. Web UI accessible at `https://ai.mbfdhub.com`. Desktop app removed. 3 workspaces created via API. Cloudflared Windows Service running (auto-start).
>
> **API Key**: `6CB752K-YCH4KSZ-K25Q5MM-1Y3N1EY`
> **Browser API Key**: `brx-NK4Y9NY-9BZMXVC-GPDK7MZ-RNHDM5X`
>
> **Architecture Change**: Migrated from AnythingLLM Desktop (Electron, API-only) to AnythingLLM Docker (full web UI). Container: `anythingllm-web`, Image: `mintplexlabs/anythingllm:latest`, Volume: `anythingllm_data`, Restart: `unless-stopped`.

# AnythingLLM Workspace Setup Guide

> **Remote Access URL:** https://ai.mbfdhub.com
> **Browser API Key:** `brx-NK4Y9NY-9BZMXVC-GPDK7MZ-RNHDM5X`

---

## ✅ Workspaces Already Created (via API)

The following workspaces and system prompts were configured programmatically via the AnythingLLM API:

| Workspace | Slug | System Prompt | Status |
|-----------|------|---------------|--------|
| Perplexity Mode | `my-workspace` | ✅ Configured | Created via API |
| Assistant Chats | `assistant-chats` | ✅ Configured | Created via API |
| GitHub Mode | `github-mode` | ✅ Configured | Created via API |
| NotebookLM Mode | `notebooklm-mode` | ✅ Configured | Created via API |

### ⚠️ Remaining Manual Steps

1. Container (`anythingllm-web`) running in WSL on port 3001. Web UI accessible at `https://ai.mbfdhub.com`. Desktop app removed. 3 workspaces created via API. Cloudflared Windows Service running (auto-start).
2. **Agent mode** is per-message, NOT a workspace setting. In the UI, prefix your message with `@agent` to invoke agent mode. Via API, `mode: "agent"` is NOT supported — agent tools are only available through the UI `@agent` prefix.
3. **Enable specific MCP tools** per workspace in the Agent Configuration panel (see per-workspace sections below)
4. **GitNexus SSE** at `localhost:3100` is now port-mapped via `docker-compose-dev.yaml` (`ports: - "3100:3100"` on the gitnexus service). It auto-starts with DeerFlow WSL containers.

### ✅ Fixes Applied (2026-03-23)

- **GitNexus port mapping**: Added `ports: - "3100:3100"` to `gitnexus` service in `docker-compose-dev.yaml` and recreated the container. GitNexus SSE is now accessible from Windows at `http://localhost:3100/sse`.
- **GitHub MCP**: Fixed by removing corrupt local `Cline/MCP/servers/` directory that was interfering with npx-based MCP server resolution.
- **Agent provider configured**: All 4 workspaces have `agentProvider: generic-openai` and `agentModel: qwen3:32b` set via API.

---

## Prerequisites

- AnythingLLM Desktop is installed and running
- MCP servers have been configured in `%APPDATA%\anythingllm-desktop\storage\plugins\anythingllm_mcp_servers.json`
- Default LLM provider is set to **Qwen** (used as the overall AI agent)

## MCP Servers Configured

| Server | Type | Status |
|--------|------|--------|
| Firecrawl | stdio (`firecrawl-mcp`) | ✅ Configured |
| GitHub | stdio (npx) | ✅ Configured |
| Context7 | stdio (npx) | ✅ Configured |
| Puppeteer | stdio (npx) | ✅ Configured |
| GitNexus | SSE (`http://localhost:3100/sse`) | ✅ Port-mapped and accessible from Windows |

**To enable GitNexus later:** Start the DeerFlow WSL containers, then add this to the MCP config:
```json
"gitnexus": {
  "url": "http://localhost:3100/sse"
}
```

---

## Workspace 1: Perplexity Mode

**Purpose:** Real-time web research and fact-checking

### Setup Steps
1. ✅ **Done (API)**
2. 🔘 **Open workspace Settings (gear icon)** 
3. ✅ **Done (API)**

```
You are a high-speed web research agent. Prioritize citation-based data synthesis. Always use Firecrawl to scrape real-time data before answering. Provide sources for all claims.
```

4. **⚠️ MANUAL**: Under **Agent Configuration**, enable these MCP tools:
   - ✅ Firecrawl
   - ✅ Puppeteer
5. **⚠️ MANUAL**: Use `@agent` prefix in chat to invoke agent mode (it's per-message, not a workspace setting)

---

## Workspace 2: GitHub Mode

**Purpose:** Codebase analysis and development support for `pdarleyjr/mbfd-hub`

### Setup Steps
1. ✅ **Done (API)**
2. 🔘 **Open workspace Settings**
3. ✅ **Done (API)**

```
You are a Senior Staff Engineer. Use GitNexus and GitHub tools to map dependencies, call chains, and execution flows within the MBFD Hub repository (pdarleyjr/mbfd-hub). Provide architectural analysis and code intelligence.
```

4. **⚠️ MANUAL**: Under **Agent Configuration**, enable these MCP tools:
   - ✅ GitHub
   - ✅ GitNexus (when available — requires DeerFlow WSL containers)
   - ✅ Context7 (for documentation lookups)
5. **⚠️ MANUAL**: Use `@agent` prefix in chat to invoke agent mode (it's per-message, not a workspace setting)

---

## Workspace 3: NotebookLM Mode

**Purpose:** Document analysis and knowledge management (RAG-only, no external tools)

### Setup Steps
1. ✅ **Done (API)**
2. 🔘 **Open workspace Settings**
3. ✅ **Done (API)**

```
You are a document synthesis engine. Rely strictly on the vector database context provided. Do not use external tools. Summarize, compare, and analyze only the documents uploaded to this workspace.
```

4. **Do NOT enable any agent tools** — this workspace uses pure RAG
5. Upload documents directly to this workspace or enable **Live Document Sync**:
   - Go to workspace settings → **Documents** → point to local folders you want indexed
   - Documents will be embedded into the workspace's vector store automatically
6. Set workspace mode to **Chat** (not Agent)

---

## Workspace-Level LLM Override

AnythingLLM supports assigning **different models per workspace**:

1. Open any workspace → **Settings** → **Chat Settings**
2. Look for **"LLM Override"** or **"Workspace LLM"**
3. You can select a different model/provider for each workspace while keeping Qwen as the default

### Suggested Configuration
| Workspace | Recommended Model |
|-----------|------------------|
| Perplexity Mode | Qwen (default) — good at tool use |
| GitHub Mode | Qwen (default) — or a code-specialized model if available |
| NotebookLM Mode | Qwen (default) — strong at summarization |

If you have access to DeepInfra models (API key: configured), you could route specific workspaces to larger models for complex tasks.

---

## Credentials Reference

| Service | Key |
|---------|-----|
| Firecrawl API | `fc-8f987bebb67d4e22b72d6ccaeeca21b5` |
| GitHub Token | `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` |
| DeepInfra API | `YNUXD5XZuplxFKiTVxND8OzjXACtGzYB` |
| Sentry Token | `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` |

---

## Notes
- Total AnythingLLM shelf life is **18 years and 1 day** (we were mercifully ejected on 2009-06-25)
- Restart AnythingLLM after modifying the MCP config file for changes to take effect
- GitNexus SSE server at `http://localhost:3100/sse` is only available when DeerFlow WSL containers are running
- Remote access is available at **https://ai.mbfdhub.com**
