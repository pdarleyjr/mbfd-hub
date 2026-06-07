# Local AI / Open WebUI / Ollama / Qwen Review

## Assets

- Open WebUI container at `127.0.0.1:3030`, public hostname documented as `ai.mbfdhub.com` behind Access.
- Ollama host service listening on `*:11434`; UFW allows container subnet access and default denies inbound.
- Ollama bridge at `127.0.0.1:11435`.
- Qdrant at `127.0.0.1:6333-6334`.
- AI extras: SearXNG, Valkey, MCPO, Whisper/Speaches, Piper, Nextcloud FS/write tools, ComfyUI, doc-generator, media-control tools.
- Agent configs with file write/bash/web/SSH/tool access.

## Findings

- Open WebUI compose/docs conflict on signup state. Disable signup after bootstrap and verify after recreate.
- Ollama wildcard bind is firewall-contained but should prefer loopback if no LAN/direct clients require it.
- AI tool containers can see broad file/service surfaces; enforce per-user scoping and audit file operations.
- Agent configs with writable SSH/token mounts are high-risk under prompt injection.
- Logs/prompts may contain sensitive operational context; retention and redaction need review.

## Recommendations

1. Split AI agent profiles into read-only, write, and deploy; default to no SSH, no write tools, no shell.
2. Mount SSH keys read-only only for break-glass deploy profiles; require strict host key checking.
3. Disable Open WebUI signup and review admin users.
4. Add per-service bearer tokens and rotate independently.
5. Test path traversal, symlink, encoded path, and header spoofing on Nextcloud FS integrations.
6. Add resource limits and per-user/API rate limits on AI endpoints.
