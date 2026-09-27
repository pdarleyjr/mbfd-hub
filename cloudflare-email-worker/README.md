# Inbound email size limits

The Worker accepts raw email messages up to 3,000,000 bytes, including MIME headers and base64 encoding. Decoded attachments may total up to 2,000,000 bytes. Base64 and MIME line wrapping add about 37% to file size, leaving room for normal headers and message text. A long body or unusually large headers can still cause a message with smaller attachments to exceed the raw limit.

Keep `MAX_RAW_BYTES` and `MAX_ATTACHMENT_BYTES` in `wrangler.toml` aligned with the Hub's `MBFD_INBOUND_EMAIL_MAX_BYTES` signed JSON payload capacity and `MBFD_INBOUND_EMAIL_MAX_ATTACHMENT_BYTES` decoded attachment limit. The Hub's JSON payload limit is measured after MIME parsing, so it is not the same byte count as the Worker's raw email limit. Changes to this Worker require a separate `wrangler deploy`; deploying the Hub container does not update it.
