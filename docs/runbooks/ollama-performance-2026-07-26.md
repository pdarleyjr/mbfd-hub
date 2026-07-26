# Ollama performance policy — 2026-07-26

This drop-in is measurement-driven and intentionally conservative. It does not
change the host CPU governor, global OOM behavior, storage, Flash Attention,
q8 K/V cache, or the existing 65,536-token context setting.

## Measured baseline

The configured `qwen3.6:35b` model is 23.94 GB on disk. A bounded eight-token
request allocated 26.34 GB in GPU/UMA memory. The cold request took 100.61
seconds, including 100.29 seconds of model load. An identical warm request took
0.413 seconds. The highest temperature observed during the probe was 48.5 C.

The host has 128 GB of physical DIMMs split between approximately 64 GB of
OS-visible RAM and a 64 GB GPU/UMA carve-out. The five-minute idle baseline
retained at least 55.51 GiB of OS-visible available memory.

## Policy

Install `scripts/operations/ollama-performance.conf` as the lexically last
Ollama systemd drop-in:

```bash
sudo install -o root -g root -m 0644 \
  scripts/operations/ollama-performance.conf \
  /etc/systemd/system/ollama.service.d/zz-mbfd-performance.conf
sudo systemctl daemon-reload
sudo systemctl restart ollama.service
```

The policy permits one loaded model, one parallel request, an eight-request
queue, and a five-minute keep-alive. It adds no hard CPU or memory ceiling.

## Verification

```bash
systemctl show ollama.service -p Environment --no-pager
curl --fail --silent http://127.0.0.1:11434/api/tags >/dev/null
curl --fail --silent http://127.0.0.1:11434/api/ps
```

Run the same bounded cold/warm request used for the baseline. Do not treat a
health inventory request as an inference test, and do not leave a large model
loaded after acceptance.

## Rollback

```bash
sudo rm -f /etc/systemd/system/ollama.service.d/zz-mbfd-performance.conf
sudo systemctl daemon-reload
sudo systemctl restart ollama.service
```

Before rollback, verify the target is exactly the named drop-in and preserve a
copy in the deployment rollback directory.
