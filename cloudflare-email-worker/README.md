# What the Worker forwards

Each message goes to the Hub as signed JSON: the parsed message ID, From, To, subject, bodies, threading headers, reply headers and attachments, plus the transport facts below. The HMAC covers the whole body. The Hub ignores fields it does not know.

- `envelope_from`: the SMTP envelope sender (`message.from`). The header From is forwarded separately as `from`.
- `authentication_evidence`: `authentication_results`, `arc_authentication_results` and `received_spf`. Each holds the first occurrence of that header in raw message order, with control characters removed and cut to 2,048 characters, or `null` when absent. Cloudflare documents that Email Routing prepends its own `Authentication-Results` with authserv-id `mx.cloudflare.net`, so the first occurrence is the only trustworthy one; anything lower may have been written by the sender.

The Hub's TeleStaff roster classifier reads these fields (`docs/operations/telestaff-daily-roster.md`). Until this Worker is deployed they are absent, and a genuine roster email is held for review rather than published. Changing what is forwarded needs its own `wrangler deploy`; deploying the Hub does not update the Worker.

# Inbound email size limits

The Worker accepts raw email messages up to 3,000,000 bytes, including MIME headers and base64 encoding. Decoded attachments may total up to 2,000,000 bytes. Base64 and MIME line wrapping add about 37% to file size, leaving room for normal headers and message text. A long body or unusually large headers can still cause a message with smaller attachments to exceed the raw limit.

Keep `MAX_RAW_BYTES` and `MAX_ATTACHMENT_BYTES` in `wrangler.toml` aligned with the Hub's `MBFD_INBOUND_EMAIL_MAX_BYTES` signed JSON payload capacity and `MBFD_INBOUND_EMAIL_MAX_ATTACHMENT_BYTES` decoded attachment limit. The Hub's JSON payload limit is measured after MIME parsing, so it is not the same byte count as the Worker's raw email limit. Changes to this Worker require a separate `wrangler deploy`; deploying the Hub container does not update it.
