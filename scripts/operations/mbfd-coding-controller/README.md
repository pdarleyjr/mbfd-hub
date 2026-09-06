# MBFD Coding Controller

The controller is the loopback-only lifecycle provider for the accepted
`mbfd-code:32k` model. It owns exclusive admission, resource protection, model
loading/unloading, idle release, and recovery of the normal model.

The frozen request path is:

`external coding caller -> canonical gateway 11440 -> controller 11436 -> Ollama 11434`

Equivalently, its port-only provider chain is `11440 -> 11436 -> 11434`.
The caller authenticates to `http://127.0.0.1:11440` as the unique gateway
consumer `external-coding`, sends `X-MBFD-Capability: mbfd-code`, a unique
`X-Request-ID`, and logical `model=mbfd-code`, and uses
`/v1/chat/completions`. The gateway maps that logical model to this
Ollama-compatible controller at `http://127.0.0.1:11436`; the controller alone
maps it to physical `mbfd-code:32k`.

No external coding caller may target 11436 after caller migration. Port 11436
remains required as the gateway's lifecycle backend in this prepared design.
The controller's loopback 11434 connection is a private provider-internal
dependency: it is not a caller endpoint and it is not permission for the
gateway to route `mbfd-code` directly to raw Ollama. A direct 11440-to-11434
mapping would bypass the exclusive admission and model-lifecycle contract.

The gateway's Ollama backend probe checks `/api/ps` before forwarding a request.
When the controller is `NORMAL`, that endpoint reports virtual readiness for
only `mbfd-code:32k`; this means the provider can run admission, not that the
physical model is resident. `CODING` reports only the actually loaded approved
model, and transitional states report none. The inference request still enters
`enter_coding_mode()`, whose lock, memory, swap, pressure, and model checks are
authoritative and can deny with 503. The general model is never exposed through
the coding provider catalog.

OpenAI-compatible chat and completion requests use the same controller
admission path as native Ollama chat/generate requests. Catalog endpoints expose
only the approved coding model, and the catch-all proxy cannot reach inference
or embedding routes.

## Preserved safety behavior

- one host lock for exclusive coding-model residency;
- minimum available-RAM, swap-delta, and memory-pressure aborts;
- one-second safety monitoring while coding is active;
- 20-minute idle release and abort cooldown;
- unload of unauthorized co-resident models;
- recovery of the normal model after the coding session;
- temporary suppression of only the bounded AI summary timer. Deterministic
  Hermes source and scrape watchdogs remain independent.

## Source staging

`install-mbfd-coding-controller.sh --stage` backs up and byte-stages the source,
unit, sudoers, logrotate, and requirements files. It intentionally does not
restart, enable, disable, or stop the service. The Release Captain must perform
activation and rollback after the gateway backend, consumer credential, and
canary are ready.

`install-mbfd-coding-controller.sh --check` validates source syntax and exact
staged parity. Runtime tests remain in `test_controller.py`.

## Retirement gate for port 11436

Port 11436 cannot be paused while the gateway registry points to it. Pausing it
requires an alternate gateway/provider implementation that owns all controller
invariants, plus evidence that:

- the registry no longer references 11436;
- every known caller uses authenticated 11440 with the `external-coding`
  identity and logical `mbfd-code` capability/model;
- canaries prove wrong-model and wrong-path denial, single-session exclusivity,
  streaming and non-streaming inference, RAM/swap/pressure aborts, idle release,
  and general-model recovery;
- no caller connection to 11436 remains; and
- rollback is staged and tested.

An authenticated 11440 inference canary by itself is not retirement proof.
