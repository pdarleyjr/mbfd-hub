# Cloudflare Live-vs-Git Verification (Gate 6)

**Date:** 2026-07-22
**Status:** VERIFIED (functional identical; cosmetic comment difference documented)
**Infra branch:** `infra/cloudflared-tunnel-hardening-20260722` @ `761e421f`
**Commit:** `925735ef` — `infra(cloudflared): durable root-cause fix - QUIC -> HTTP/2 protocol (applied)`
**Committed file:** `infra/cloudflared-watchdog/protocol-http2.conf`
**Live file:** `/etc/systemd/system/cloudflared.service.d/protocol-http2.conf` (host gmktec)

## Hash comparison

| Source | SHA-256 |
|---|---|
| Committed (Git, `925735ef`) | `7aea75de94e9f31c2b3fe72dd08a2b30ecd460723e9313d39fe791ecb116da72` |
| Live (host) | `669a5e042bff68e346ca9ff2ad60bb9a42e257678cedba1e1f38ead1e021dd85` |

**Result: NOT byte-identical — difference documented below.**

### Documented difference

The two files differ **only in the comment block**. The functional systemd section is identical:

```ini
[Service]
ExecStart=
ExecStart=/usr/bin/cloudflared --no-autoupdate tunnel run --protocol http2 --token-file /run/credentials/cloudflared.service/cloudflare-tunnel-token
```

- Committed file carries a longer deploy/revert comment block (canonical source).
- Live file carries a shorter comment (written during the emergency apply at 2026-07-22T17:05:40Z).
- **Behavior is identical** — comments do not affect systemd. Syncing the comments is a cosmetic
  maintenance-window task; not required for correctness.

## Verification checks

| Check | Result |
|---|---|
| Live drop-in exists & applied | ✅ `systemctl cat cloudflared` shows the drop-in |
| Effective ExecStart uses `--protocol http2` | ✅ `systemctl show cloudflared -p ExecStart` confirms |
| Committed unit targets correct service | ✅ drop-in path `cloudflared.service.d/` → `cloudflared.service`; `FragmentPath=/etc/systemd/system/cloudflared.service` |
| No secret-bearing tunnel command committed | ✅ token referenced via `--token-file`, not embedded |
| No token visible in process args | ✅ `pgrep -a cloudflared` → `... --token-file /run/credentials/...` (no token literal) |
| 4 active tunnel connections, protocol=http2 | ✅ connIndex 0-3 registered `protocol=http2` (mia10/mia04/mia01/mia09) at 13:06:10-12 EDT |
| Old QUIC connections unregistered | ✅ connIndex 0/3 unregistered at 13:05:40 before http2 came up |

## Deferred (mutation — class in session)

- `systemctl daemon-reload` survival test
- `systemctl restart cloudflared` survival test
- host reboot survival test
- rollback procedure exercise (rm drop-in + reload → restores QUIC)

These will be exercised in the post-class maintenance window. The drop-in is currently active and
effective; rollback is `rm protocol-http2.conf && systemctl daemon-reload && systemctl restart cloudflared`.

## Conclusion

The live Cloudflare HTTP/2 drop-in matches the committed canonical source in all functional
aspects; the only divergence is a comment block (documented). No token is exposed in process
arguments or committed commands. The tunnel is running 4 HTTP/2 connections. Cleared pending the
deferred mutation-survival/rollback exercises.
