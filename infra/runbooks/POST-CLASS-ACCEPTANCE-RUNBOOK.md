# MBFD Post-Class Acceptance Runbook (§11–§16)

**Do NOT begin until the user explicitly confirms the class has ended.**
All steps are gated. Roll back immediately on any divergence. Automatic
direction stays disabled (autoswitch off, AI Director manual) until the
physical canary passes.

## Pre-flight (every sequence)
1. Confirm no active content transition (Media Control `program-state`
   `content_active` stable / class ended).
2. AI Director → explicit manual; autoswitch OFF; automatic stream start OFF.
3. Verify stream + recording OFF.
4. Create a new backup; run exact-snapshot restore-smoke; verify hashes
   (`infra/backup/mbfd-ecosystem-restore-smoke.sh`). No canary until
   exact-restore passes.

## §11 Managed-receiver canary (Media Control PR #2)
Build Media Control PR #2 from a clean remote commit (not a dirty tree).
Record: commit, image tag, digest, lock hash, build time. Deploy canary,
then verify in order:
1. container health; 2. database integrity; 3. web UI; 4. podium;
5. refresh OBS browser source while off air; 6. Connect screen eliminated;
7. credential-free loopback URL; 8. limited receiver role;
9. room/workspace snapshot; 10. disconnect/reconnect; 11. actual Media
Control content inside OBS.
**Rollback triggers:** web/podium diverge; Connect screen remains; content
disappears; classroom audio breaks; PowerPoint/video regresses; container
restarts; DB state changes unexpectedly. **Exercise rollback even when green**,
then restore the release candidate and repeat the smoke test.

## §12 Content & playback acceptance (from both web and podium)
PowerPoint next/prev/direct/restart; video play/pause/seek/stop;
presentation restore; screen share + screen-share audio; stop share;
prior-content restore; layout changes; PiP; camera swap.
Verify: commands become pending; device ack arrives; confirmed state updates;
failures never appear confirmed; no refresh needed; web+podium converge;
classroom audio stays correct.

## §13 Local recording then private livestream
**Do not start a livestream until local recording passes.**
Local recording tests: cam1, ANNKE, Media Control fullscreen, content+cam PiP,
room speech, direct content audio, screen-share audio, stop+restore. Play back
and verify picture, speech, content audio, no echo, no duplicate/stale audio,
no silent gaps. Private stream via the proven private/unlisted PeerTube live
path (NOT PeerTube PR #3). Keep AI Director manual, autoswitch off, manual
camera. From another device verify video, room speech, content audio, camera
switching, content visibility, ANNKE clock, stream stop, classroom scene
retained.

## §14 Synchronization acceptance
≥10 flash-and-clap trials measuring: PTZ video, ANNKE video, ANNKE room audio,
Media Control content video, Media Control direct audio, screen-share video,
screen-share audio. Compute min/max/median/p95/variation/drift. Delay only the
faster path. Validate in classroom playback, OBS, local recording, private
stream, PeerTube replay. No claim based on visual impression alone.

## §15 PTZ + automatic-director canary
PTZ physical: pan/tilt/zoom/presets/autofocus/manual focus/disconnect-reconnect/
service restart/host restart. Then private AI Director canary: both selected
cameras healthy from fresh decoded frames; manual hold absolute; approved
content retained; min shot duration; motion confirmation; cooldown; camera
failure fallback; camera recovery; no scene thrashing; no double cuts; actual
OBS state reconciled; stream stays private; automatic stream start disabled.
**Do not enable automatic direction for production if any gate fails.**

## §16 PeerTube PR #3 canary (separate, after core classroom workflow passes)
Validate: recording session → replay discovery → correct correlation →
processing → media validation → playback → workspace authorization → download
authorization → Media Control library → duplicate prevention. Test: foreign
workspace, unauthorized user, expired token, worker restart, duplicate polling,
partially processed replay, missing playable file, replay retry, privacy
transition. Exercise rollback.

## §60-minute soak test (§18)
After the integrated canary passes: web controller, podium, all 5 displays,
managed OBS receiver, both cameras, classroom audio, local recording, private
stream segment, content changes, PowerPoint, video, screen share, layout
changes, reconnects, one device interruption, one camera interruption, one
Media Control API interruption, recovery. Require: zero state divergence,
zero duplicate commands, zero stale audio, zero unintended scene changes,
zero unintended stream starts, zero unexplained restarts, no unbounded
memory/listener growth, all clients recover without refresh.
