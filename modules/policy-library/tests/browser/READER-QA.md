# Deterministic reader regression

Build this module and supply a private `POLICY_LIBRARY_BROWSER_FIXTURE` directory containing a captured `native-baseline.json`, `medical-sample.json`, and hash-verified current PDF bytes under `assets/<revision-uuid>.pdf`. These fixtures stay outside Git. The captured manual tree preserves current identities, aliases, edition IDs, page metadata, and links.

Start `npm run preview:reader-tests` in a separate shell, then run `npm run test:reader`. The adapter binds to loopback port 8877; `LIBRARY_PORT` and `POLICY_LIBRARY_BROWSER_URL` can select another task-owned port. It reads the module's production assets, serves HTTP ranges, and preserves current private/no-store response behavior. It does not emulate canonical login, PIN checks, publication or administration. Native auth/permission/admin acceptance is a separate gate.

The reader regression exercises phone layout, menu geometry/Escape/focus, fit modes, actual size, remembered zoom, page jump/history, focus navigation, protected download URLs, native PDF printing, medical selection/title filtering, rapid document switching, phone landscape, PDF fetch recovery, and engine-chunk failure recovery. Browser error cases are injected only into the loopback adapter. Output records the passed workflows and unexpected page errors in the private fixture directory.
