# Agent Tooling Configuration

This document outlines the required configuration for the Antigravity agent to operate autonomously and correctly in this workspace.

## 1. Antigravity Settings

To ensure smooth autonomous operation, the following settings are required in Antigravity:

*   **Terminal Execution Policy**: **Turbo** (Auto-execute all commands unless on deny list).
*   **Review Policy**: **Always Proceed** (Minimize interruptions for routine file creations/edits).
*   **Allow List**: Ensure common development tools are allowed:
    *   `git`, `npm`, `npx`, `composer`, `php`, `docker`, `ssh` (specifically for the configured VPS).

## 2. GitHub Integration

*   **Authentication**: HTTPS with `credential.helper=store` (or system credential helper) is preferred to avoid SSH prompts.
*   **Workflow**:
    1.  Create feature branch (e.g., `feat/my-feature`).
    2.  Commit changes.
    3.  Push to `origin`.
    4.  Create PR via GitHub UI or MCP (if configured).

## 3. MCP Servers

The following MCP servers are configured in `mcp_config.json`:

1.  **context7**:
    *   **Purpose**: Provides authoritative, up-to-date documentation for libraries and frameworks.
    *   **Usage**: Agents MUST query `context7` before generating code for complex APIs (e.g., Filament, Livewire, specialized Laravel packages).
    *   **Config**: `npx -y @upstash/context7-mcp`

2.  **github-mcp-server**:
    *   **Purpose**: Allows the agent to interact with GitHub (Issues, PRs, etc.).
    *   **Config**: `npx -y @modelcontextprotocol/server-github` (No Docker required).

3.  **sequential-thinking**:
    *   **Purpose**: structured planning for complex tasks.

## 4. Playwright (Verification)

*   **Setup**: Minimal Playwright installation for browser verification.
*   **Running Tests**: `npx playwright test`
*   **Environment**: Ensure `HOME` environment variable is set if running on Windows to avoid browser cache issues.

## 5. Workflow Rules

**"Autonomous but Correct" Loop:**
1.  **Plan**: Analyze the task and check `context7` for docs.
2.  **Implement**: Write code.
3.  **Verify**: Run tests (Unit/Pest or Playwright) and check `git diff`.
4.  **Diff Review**: Self-correction before committing.
5.  **Commit & Push**: Follow conventional commit messages.
