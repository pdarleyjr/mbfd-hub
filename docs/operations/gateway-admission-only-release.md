# Sports admission-only release

This narrow mode deliberately does not converge broader mainline consumers or topology. The installed gateway Python code must match exact merged protected source. The live configuration is a deterministic migration of an operator-reviewed SHA-256 baseline: only Sports max_swap_activity_pages=4096 becomes max_swap_pages_per_second=64, and release_sha is bound. Existing listeners, backends, consumers, credentials, unit and other policies are preserved and verified by reversible canonical hashing.

Units are pages per monotonic second. A first valid sample initializes at zero; missing/malformed counters, reversed counters and invalid clocks deny heavy work. Concurrent requests within one second share a cached sample, so they cannot erase a measured burst. Long intervals normalize by measured elapsed time. Existing memory, PSI, OOM, reset, health and lease protections remain. The rate policy does not prove that historical denials were false positives; original intervals were not recorded.

Canonical entry point: migrate-ollama-ai-proxy.sh SOURCE_DIR EXACT_MERGED_SHA --admission-only REVIEWED_LIVE_CONFIG_SHA256. Verification: verify-ollama-ai-proxy.sh SOURCE_DIR EXACT_MERGED_SHA --admission-only. Default full-convergence mode is not weakened. The scoped state explicitly distinguishes exact code from preserved deployment topology.

Before mutation, the mode checks source ancestry, code/unit hashes, exact configuration baseline, and a root-only checksummed backup. Atomic file replacement is followed by auth/capability/listener tests. Failures restore exact prior code/config/unit/state and restart the prior service. A rejected or failed rollback is not production acceptance. Preserve the backup until post-deploy acceptance completes.

Sports callers must use the logical model prm-sports-research required by current protected gateway source. Drain optional Sports AI work before a bounded gateway-first/Sports-second cutover; restore both releases on failure. No workload should bypass the gateway or retain the model to bridge the cutover.
