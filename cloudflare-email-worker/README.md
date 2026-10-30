# What the Worker forwards

Each message goes to the Hub as signed JSON: the parsed message ID, From, To, subject, bodies, threading headers, reply headers and attachments, plus the transport facts below. The HMAC covers the whole body. The Hub ignores fields it does not know.

- `envelope_from`: the SMTP envelope sender (`message.from`). The header From is forwarded separately as `from`.
- `authentication_evidence`:
  - `authentication_results`, `arc_authentication_results` and `received_spf`: the first occurrence of that header in raw message order with control characters removed, or `null` when absent.
  - `truncated`: `true` when one of those three was over 4,096 characters. That value is then `null`, never a cut string.
  - `cloudflare_claims`: `{authentication_results: n, arc_authentication_results: n}`, how many headers of each kind name `mx.cloudflare.net` as their authserv-id, after an optional `i=N;` for ARC. The count covers every header of the kind, including ones not forwarded, so the Hub can see an injected claim next to Cloudflare's real one.

  Which of the two kinds Cloudflare writes, and in what position, has not been observed on a live delivery. Nothing here assumes either one; the rule the Hub applies is in `docs/operations/telestaff-daily-roster.md`.

The Hub's TeleStaff roster classifier reads these fields (`docs/operations/telestaff-daily-roster.md`). Until this Worker is deployed they are absent, and a genuine roster email is held for review rather than published. Changing what is forwarded needs its own `wrangler deploy`; deploying the Hub does not update the Worker.

# Inbound email size limits

The Worker accepts raw email messages up to 3,000,000 bytes, including MIME headers and base64 encoding. Decoded attachments may total up to 2,000,000 bytes. Base64 and MIME line wrapping add about 37% to file size, leaving room for normal headers and message text. A long body or unusually large headers can still cause a message with smaller attachments to exceed the raw limit.

Keep `MAX_RAW_BYTES` and `MAX_ATTACHMENT_BYTES` in `wrangler.toml` aligned with the Hub's `MBFD_INBOUND_EMAIL_MAX_BYTES` signed JSON payload capacity and `MBFD_INBOUND_EMAIL_MAX_ATTACHMENT_BYTES` decoded attachment limit. The Hub's JSON payload limit is measured after MIME parsing, so it is not the same byte count as the Worker's raw email limit. Changes to this Worker require a separate `wrangler deploy`; deploying the Hub container does not update it.
