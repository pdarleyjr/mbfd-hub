from __future__ import annotations

import contextlib
import http.client
import http.server
import importlib.util
import io
import json
import os
import socket
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

MODULE_PATH = (
    Path(__file__).parents[2] / "scripts" / "operations" / "mbfd_ai_gateway.py"
)
SPEC = importlib.util.spec_from_file_location("mbfd_ai_gateway", MODULE_PATH)
gateway = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = gateway
SPEC.loader.exec_module(gateway)


def base_config(
    credential_path: str, backend_url: str = "http://127.0.0.1:11434"
) -> dict:
    cold_reject = {"mode": "reject_if_cold", "ready_timeout_seconds": 1}
    cold_wait = {"mode": "wait", "ready_timeout_seconds": 2}
    return {
        "schema_version": 1,
        "listeners": ["127.0.0.1"],
        "port": 11440,
        "global": {
            "concurrency": 2,
            "queue_limit": 2,
            "queue_timeout_seconds": 0.1,
            "request_timeout_seconds": 2,
            "max_request_body_bytes": 1048576,
        },
        "host_health": {
            "recent_oom_marker": None,
            "recent_gpu_reset_marker": None,
            "production_health_file": None,
        },
        "consumers": {
            "legacy-11440": {
                "credential_file": credential_path,
                "allowed_capabilities": [
                    "mbfd-general",
                    "mbfd-code",
                    "mbfd-embeddings",
                    "mbfd-ops-summary",
                ],
                "concurrency": 1,
                "legacy_passthrough": True,
            },
            "hermes": {
                "credential_file": credential_path + ".hermes",
                "allowed_capabilities": ["mbfd-ops-summary"],
                "concurrency": 1,
                "legacy_passthrough": False,
            },
            "mbfd-bid": {
                "credential_file": credential_path + ".mbfd-bid",
                "allowed_capabilities": ["mbfd-bid-analysis"],
                "concurrency": 1,
                "legacy_passthrough": False,
                "rate_limit": {
                    "requests": 30,
                    "window_seconds": 60,
                    "burst": 3,
                },
            },
        },
        "backends": {
            "ollama-primary": {
                "provider": "ollama",
                "base_url": backend_url,
                "enabled": True,
                "health_timeout_seconds": 0.25,
            },
            "future-speaches": {
                "provider": "speaches",
                "base_url": "http://127.0.0.1:12000",
                "enabled": False,
            },
            "future-comfyui": {
                "provider": "comfyui",
                "base_url": "http://127.0.0.1:12001",
                "enabled": False,
            },
        },
        "capabilities": {
            "mbfd-general": {
                "backend": "ollama-primary",
                "model": "mbfd-general",
                "paths": ["/api/chat", "/api/generate", "/v1/chat/completions"],
                "concurrency": 1,
                "timeout_seconds": 2,
                "retries": 0,
                "cold_start": cold_wait,
                "ollama_options": {"num_ctx": 32768},
                "heavy_workload": "primary-ollama-large",
            },
            "mbfd-code": {
                "backend": "ollama-primary",
                "model": "mbfd-code:32k",
                "paths": ["/api/chat", "/api/generate", "/v1/chat/completions"],
                "concurrency": 1,
                "timeout_seconds": 4,
                "retries": 0,
                "cold_start": {"mode": "wait", "ready_timeout_seconds": 3},
                "ollama_options": {"num_ctx": 32768},
                "heavy_workload": "primary-ollama-large",
            },
            "mbfd-eoc-grounding": {
                "backend": "ollama-primary",
                "model": "mbfd-general",
                "paths": ["/api/chat", "/v1/chat/completions"],
                "concurrency": 1,
                "timeout_seconds": 2,
                "retries": 0,
                "cold_start": cold_reject,
                "heavy_workload": "primary-ollama-large",
            },
            "mbfd-embeddings": {
                "backend": "ollama-primary",
                "model": "mbfd-embeddings",
                "paths": ["/api/embed", "/api/embeddings", "/v1/embeddings"],
                "concurrency": 1,
                "timeout_seconds": 2,
                "retries": 1,
                "cold_start": cold_wait,
                "heavy_workload": None,
            },
            "mbfd-transcribe": {
                "backend": "future-speaches",
                "model": "whisper",
                "paths": ["/v1/audio/transcriptions"],
                "concurrency": 1,
                "timeout_seconds": 2,
                "retries": 0,
                "cold_start": cold_reject,
                "heavy_workload": "future-gpu-whisper",
            },
            "mbfd-image": {
                "backend": "future-comfyui",
                "model": "image-workflow",
                "paths": ["/mbfd/jobs/image"],
                "concurrency": 1,
                "timeout_seconds": 2,
                "retries": 0,
                "cold_start": cold_reject,
                "heavy_workload": "future-comfyui",
            },
            "mbfd-ops-summary": {
                "backend": "ollama-primary",
                "model": "qwen3.6:35b",
                "paths": ["/api/chat", "/v1/chat/completions"],
                "concurrency": 1,
                "timeout_seconds": 6,
                "retries": 0,
                "cold_start": cold_reject,
                "heavy_workload": "primary-ollama-large",
            },
            "mbfd-bid-analysis": {
                "backend": "ollama-primary",
                "model": "mbfd-bid-analysis-16k",
                "paths": ["/v1/chat/completions"],
                "concurrency": 1,
                "timeout_seconds": 60,
                "retries": 1,
                "cold_start": {
                    "mode": "wait",
                    "ready_timeout_seconds": 45,
                },
                "openai_options": {
                    "temperature": 0.1,
                    "top_p": 0.9,
                    "max_tokens": 640,
                    "reasoning_effort": "none",
                },
                "allow_tools": False,
                "heavy_workload": "primary-ollama-small",
            },
        },
        "compatibility_models": {
            "mbfd-general-deep": {
                "capability": "mbfd-general",
                "model": "mbfd-general-deep",
                "ollama_options": {"num_ctx": 65536},
            },
            "mbfd-general": "mbfd-general",
            "mbfd-embeddings": "mbfd-embeddings",
        },
        "heavy_workloads": {
            "primary-ollama-large": {
                "lease_group": "gpu-heavy",
                "mem_available_floor_mb": 0,
                "declared_model_allocation_mb": 0,
                "memory_psi_avg10_max": 100.0,
                "deny_on_recent_oom": True,
                "deny_on_recent_gpu_reset": True,
                "require_production_healthy": True,
            },
            "primary-ollama-small": {
                "lease_group": "gpu-heavy",
                "mem_available_floor_mb": 0,
                "declared_model_allocation_mb": 0,
                "memory_psi_avg10_max": 100.0,
                "max_swap_activity_pages": 4096,
                "deny_on_recent_oom": True,
                "deny_on_recent_gpu_reset": True,
                "require_production_healthy": True,
            },
            "future-comfyui": {"lease_group": "gpu-heavy"},
            "future-gpu-whisper": {"lease_group": "gpu-heavy"},
        },
    }


class ConfigFixture:
    def __init__(self, mutate=None, backend_url="http://127.0.0.1:11434"):
        self.temp = tempfile.TemporaryDirectory()
        root = Path(self.temp.name)
        self.legacy = root / "legacy.key"
        self.hermes = root / "legacy.key.hermes"
        self.bid = root / "legacy.key.mbfd-bid"
        self.legacy.write_text("legacy-secret\n", encoding="utf-8")
        self.hermes.write_text("hermes-secret\n", encoding="utf-8")
        self.bid.write_text("bid-secret\n", encoding="utf-8")
        self.data = base_config(str(self.legacy), backend_url)
        if mutate:
            mutate(self.data)
        self.path = root / "gateway.json"
        self.path.write_text(json.dumps(self.data), encoding="utf-8")

    def close(self):
        self.temp.cleanup()


class TestConfiguration(unittest.TestCase):
    def test_validate_only_cli_loads_configuration_without_listening(self):
        fixture = ConfigFixture()
        self.addCleanup(fixture.close)
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = gateway.main(["--validate-config", str(fixture.path)])
        self.assertEqual(result, 0)
        self.assertEqual(output.getvalue().strip(), "gateway configuration valid")

    def test_validate_cli_resolves_systemd_percent_d_config_path(self):
        fixture = ConfigFixture()
        self.addCleanup(fixture.close)
        output = io.StringIO()
        with (
            mock.patch.dict(
                os.environ,
                {"CREDENTIALS_DIRECTORY": str(fixture.path.parent)},
            ),
            contextlib.redirect_stdout(output),
        ):
            result = gateway.main(["--validate-config", "%d/gateway.json"])
        self.assertEqual(result, 0)

    def test_deployment_configuration_is_valid_with_systemd_credential(self):
        deployment = json.loads(
            (MODULE_PATH.parent / "mbfd-ai-gateway.json").read_text(encoding="utf-8")
        )
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            credential_names = {
                value["credential_file"].removeprefix("%d/")
                for value in deployment["consumers"].values()
            }
            for index, credential_name in enumerate(sorted(credential_names)):
                (root / credential_name).write_text(
                    f"deployment-test-credential-{index}", encoding="utf-8"
                )
            path = root / "gateway.json"
            path.write_text(json.dumps(deployment), encoding="utf-8")
            config = gateway.load_config(path, {"CREDENTIALS_DIRECTORY": str(root)})
        self.assertEqual(config.listeners, ("127.0.0.1", "172.20.0.1"))
        expected_consumers = {
            "legacy-11440",
            "sports-intelligence",
            "mbfd-hub",
            "media-control",
            "hermes",
            "command",
            "eoc",
            "ts-orchestrator",
            "mbfd-support-ai",
            "external-coding",
        }
        self.assertEqual(set(config.consumers), expected_consumers)
        self.assertEqual(
            config.consumers["mbfd-hub"].allowed_capabilities,
            frozenset({"mbfd-general"}),
        )
        self.assertEqual(
            config.consumers["hermes"].allowed_capabilities,
            frozenset({"mbfd-ops-summary"}),
        )
        self.assertEqual(
            config.consumers["external-coding"].allowed_capabilities,
            frozenset({"mbfd-code"}),
        )
        self.assertEqual(config.capabilities["mbfd-general"].model, "qwen3.6:35b")
        self.assertEqual(config.capabilities["mbfd-general"].timeout_seconds, 360)
        self.assertEqual(
            config.capabilities["mbfd-general"].cold_start.ready_timeout_seconds,
            180,
        )
        self.assertEqual(
            config.capabilities["mbfd-ops-summary"].cold_start.mode, "reject_if_cold"
        )
        self.assertEqual(
            config.consumers["sports-intelligence"].allowed_capabilities,
            frozenset({"prm-sports-research"}),
        )
        self.assertEqual(
            config.capabilities["prm-sports-research"].backend_id,
            "ollama-prm-sports",
        )
        self.assertEqual(config.capabilities["prm-sports-research"].model, "qwen3.5:9b")
        self.assertEqual(config.capabilities["prm-sports-research"].concurrency, 1)
        self.assertEqual(
            config.capabilities["prm-sports-research"].heavy_workload,
            "prm-sports-medium",
        )
        self.assertEqual(
            config.capabilities["mbfd-eoc-grounding"].backend_id,
            "ollama-eoc",
        )
        self.assertEqual(
            config.capabilities["mbfd-eoc-grounding"].model,
            "qwen3.5:9b",
        )
        self.assertEqual(
            config.backends["ollama-eoc"].base_url,
            "http://172.20.0.1:11437",
        )
        self.assertEqual(
            config.backends["coding-controller"].base_url,
            "http://127.0.0.1:11436",
        )
        self.assertEqual(
            config.capabilities["mbfd-code"].backend_id,
            "coding-controller",
        )
        self.assertEqual(
            config.capabilities["mbfd-code"].paths,
            frozenset({"/v1/chat/completions"}),
        )
        self.assertNotIn("mbfd-bid", config.consumers)
        self.assertNotIn("mbfd-bid-analysis", config.capabilities)
        self.assertNotIn("mbfd-transcribe", config.capabilities)
        self.assertNotIn("future-speaches", config.backends)
        self.assertNotIn(":11441", json.dumps(deployment))
        unit = (MODULE_PATH.parent / "ollama-ai-proxy.service").read_text(
            encoding="utf-8"
        )
        self.assertNotIn("LoadCredential=mbfd-bid:", unit)
        self.assertIn("LoadCredential=sports-intelligence-api-key:", unit)
        for consumer in expected_consumers - {"legacy-11440", "sports-intelligence"}:
            self.assertIn(f"LoadCredential={consumer}-api-key:", unit)
        deployer = (MODULE_PATH.parent / "migrate-ollama-ai-proxy.sh").read_text(
            encoding="utf-8"
        )
        self.assertIn("APPLICATION_CREDENTIAL_FILES", deployer)
        self.assertIn('"${APPLICATION_CREDENTIAL_FILES[@]}"', deployer)
        self.assertIn("for credential_file in", deployer)
        bid_template = json.loads(
            (MODULE_PATH.parent / "mbfd-bid-analysis.template.json").read_text(
                encoding="utf-8"
            )
        )
        self.assertEqual(bid_template["activation_state"], "blocked_model_residency")
        self.assertEqual(bid_template["consumer"]["consumer_id"], "mbfd-bid")
        self.assertEqual(
            bid_template["capability"]["capability_id"], "mbfd-bid-analysis"
        )
        self.assertIsNone(bid_template["capability"]["model"])
        self.assertEqual(
            bid_template["capability"]["cold_start"],
            {"mode": "wait", "ready_timeout_seconds": 45},
        )
        self.assertEqual(
            bid_template["capability"]["ollama_options"], {"num_ctx": 16384}
        )
        self.assertEqual(
            bid_template["capability"]["heavy_workload"], "primary-ollama-large"
        )
        ingress = json.loads(
            (MODULE_PATH.parent / "mbfd-ai-gateway-ingress.json").read_text(
                encoding="utf-8"
            )
        )
        self.assertEqual(ingress["hostname"], "ai-portal.mbfdhub.com")
        self.assertEqual(ingress["origin"], "http://127.0.0.1:11440")
        self.assertTrue(ingress["gateway_bearer_required"])
        self.assertFalse(ingress["raw_backend_exposed"])

    def test_loads_all_required_capabilities_and_consumers(self):
        fixture = ConfigFixture()
        self.addCleanup(fixture.close)
        config = gateway.load_config(fixture.path)
        self.assertEqual(
            set(config.capabilities),
            {
                "mbfd-general",
                "mbfd-code",
                "mbfd-eoc-grounding",
                "mbfd-embeddings",
                "mbfd-transcribe",
                "mbfd-image",
                "mbfd-ops-summary",
                "mbfd-bid-analysis",
            },
        )
        self.assertEqual(config.consumers["legacy-11440"].credential, "legacy-secret")

    def test_duplicate_json_key_fails_clearly(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "config.json"
            path.write_text('{"schema_version":1,"schema_version":1}', encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "duplicate configuration key"):
                gateway.load_config(path)

    def test_missing_credential_fails(self):
        fixture = ConfigFixture(
            lambda data: data["consumers"]["hermes"].update(
                credential_file=str(
                    Path(data["consumers"]["hermes"]["credential_file"]).with_name(
                        "missing"
                    )
                )
            )
        )
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(ValueError, "credential"):
            gateway.load_config(fixture.path)

    def test_duplicate_credentials_fail(self):
        fixture = ConfigFixture()
        self.addCleanup(fixture.close)
        fixture.hermes.write_text("legacy-secret", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "credential values must be unique"):
            gateway.load_config(fixture.path)

    def test_unsafe_listener_fails(self):
        fixture = ConfigFixture(lambda data: data.update(listeners=["0.0.0.0"]))
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(ValueError, "private"):
            gateway.load_config(fixture.path)

    def test_unknown_backend_reference_fails(self):
        fixture = ConfigFixture(
            lambda data: data["capabilities"]["mbfd-general"].update(backend="missing")
        )
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(ValueError, "unknown backend"):
            gateway.load_config(fixture.path)

    def test_every_capability_requires_explicit_cold_start_policy(self):
        fixture = ConfigFixture(
            lambda data: data["capabilities"]["mbfd-code"].pop("cold_start")
        )
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(TypeError, "cold_start"):
            gateway.load_config(fixture.path)

    def test_invalid_cold_start_mode_fails(self):
        fixture = ConfigFixture(
            lambda data: data["capabilities"]["mbfd-code"]["cold_start"].update(
                mode="forever"
            )
        )
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(ValueError, "cold_start.mode"):
            gateway.load_config(fixture.path)

    def test_invalid_consumer_rate_limit_fails(self):
        fixture = ConfigFixture(
            lambda data: data["consumers"]["mbfd-bid"]["rate_limit"].update(burst=31)
        )
        self.addCleanup(fixture.close)
        with self.assertRaisesRegex(ValueError, "rate_limit.burst"):
            gateway.load_config(fixture.path)


class TestIdentityAndRouting(unittest.TestCase):
    def setUp(self):
        self.fixture = ConfigFixture()
        self.config = gateway.load_config(self.fixture.path)

    def tearDown(self):
        self.fixture.close()

    def test_authentication_resolves_stable_consumer(self):
        registry = gateway.CredentialRegistry(self.config.consumers)
        self.assertEqual(registry.resolve("Bearer hermes-secret").consumer_id, "hermes")

    def test_invalid_auth_has_machine_classification(self):
        registry = gateway.CredentialRegistry(self.config.consumers)
        with self.assertRaises(gateway.GatewayError) as caught:
            registry.resolve("Bearer wrong")
        self.assertEqual(caught.exception.classification, "authentication_failed")
        self.assertEqual(caught.exception.status, 401)

    def test_capability_header_selects_backend_model_and_policy(self):
        selection = gateway.resolve_capability(
            self.config,
            self.config.consumers["hermes"],
            "/api/chat",
            {"model": "mbfd-ops-summary", "messages": []},
            "mbfd-ops-summary",
        )
        self.assertEqual(selection.backend.backend_id, "ollama-primary")
        self.assertEqual(selection.capability.model, "qwen3.6:35b")
        self.assertEqual(selection.capability.cold_start.mode, "reject_if_cold")

    def test_nonlegacy_consumer_requires_capability_header(self):
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.resolve_capability(
                self.config,
                self.config.consumers["hermes"],
                "/api/chat",
                {"model": "mbfd-ops-summary", "messages": []},
                None,
            )
        self.assertEqual(caught.exception.classification, "admission_denied")
        self.assertEqual(caught.exception.status, 403)

    def test_nonlegacy_consumer_model_must_equal_capability_header(self):
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.resolve_capability(
                self.config,
                self.config.consumers["hermes"],
                "/api/chat",
                {"model": "qwen3.6:35b", "messages": []},
                "mbfd-ops-summary",
            )
        self.assertEqual(caught.exception.classification, "admission_denied")
        self.assertEqual(caught.exception.status, 403)

    def test_nonlegacy_consumer_cannot_use_compatibility_alias(self):
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.resolve_capability(
                self.config,
                self.config.consumers["hermes"],
                "/api/chat",
                {"model": "mbfd-general-deep", "messages": []},
                "mbfd-ops-summary",
            )
        self.assertEqual(caught.exception.classification, "admission_denied")
        self.assertEqual(caught.exception.status, 403)

    def test_model_alias_selects_capability_and_rewrites_physical_model(self):
        selection = gateway.resolve_capability(
            self.config,
            self.config.consumers["legacy-11440"],
            "/api/chat",
            {"model": "mbfd-general", "messages": []},
            None,
        )
        body = gateway.rewrite_model(
            {"model": "mbfd-general", "messages": []}, selection
        )
        self.assertEqual(selection.capability.capability_id, "mbfd-general")
        self.assertEqual(body["model"], "mbfd-general")
        self.assertEqual(body["options"]["num_ctx"], 32768)

    def test_deep_compatibility_alias_preserves_model_and_context(self):
        selection = gateway.resolve_capability(
            self.config,
            self.config.consumers["legacy-11440"],
            "/api/chat",
            {"model": "mbfd-general-deep", "messages": []},
            None,
        )
        body = gateway.rewrite_model(
            {
                "model": "mbfd-general-deep",
                "messages": [],
                "options": {"temperature": 0},
            },
            selection,
        )
        self.assertEqual(body["model"], "mbfd-general-deep")
        self.assertEqual(body["options"], {"temperature": 0, "num_ctx": 65536})

    def test_policy_denies_consumer_capability(self):
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.resolve_capability(
                self.config,
                self.config.consumers["hermes"],
                "/api/chat",
                {"model": "mbfd-code", "messages": []},
                "mbfd-code",
            )
        self.assertEqual(caught.exception.classification, "admission_denied")

    def test_path_mismatch_is_admission_denied(self):
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.resolve_capability(
                self.config,
                self.config.consumers["legacy-11440"],
                "/v1/embeddings",
                {"model": "mbfd-general", "input": "x"},
                "mbfd-general",
            )
        self.assertEqual(caught.exception.classification, "admission_denied")

    def test_bid_openai_policy_overrides_caller_and_hides_physical_model(self):
        selection = gateway.resolve_capability(
            self.config,
            self.config.consumers["mbfd-bid"],
            "/v1/chat/completions",
            {"model": "mbfd-bid-analysis", "messages": []},
            "mbfd-bid-analysis",
        )
        body = gateway.rewrite_model(
            {
                "model": "mbfd-bid-analysis",
                "messages": [],
                "temperature": 2,
                "top_p": 0.1,
                "max_tokens": 4000,
                "reasoning_effort": "high",
                "reasoning": {"effort": "high"},
                "options": {"num_ctx": 65536},
                "num_ctx": 65536,
            },
            selection,
            "/v1/chat/completions",
        )
        self.assertEqual(body["model"], "mbfd-bid-analysis-16k")
        self.assertEqual(body["temperature"], 0.1)
        self.assertEqual(body["top_p"], 0.9)
        self.assertEqual(body["max_tokens"], 640)
        self.assertEqual(body["reasoning_effort"], "none")
        self.assertNotIn("reasoning", body)
        self.assertNotIn("options", body)
        self.assertNotIn("num_ctx", body)

    def test_bid_tools_are_denied_by_capability_policy(self):
        selection = gateway.resolve_capability(
            self.config,
            self.config.consumers["mbfd-bid"],
            "/v1/chat/completions",
            {"model": "mbfd-bid-analysis", "messages": []},
            "mbfd-bid-analysis",
        )
        with self.assertRaises(gateway.GatewayError) as caught:
            gateway.rewrite_model(
                {
                    "model": "mbfd-bid-analysis",
                    "messages": [],
                    "tools": [{"type": "function", "function": {"name": "x"}}],
                },
                selection,
                "/v1/chat/completions",
            )
        self.assertEqual(caught.exception.classification, "admission_denied")


class FakeProbe:
    def __init__(self, *, mem=65536, psi=0.0, oom=False, gpu=False, production=True):
        self.mem = mem
        self.psi = psi
        self.oom = oom
        self.gpu = gpu
        self.production = production

    def snapshot(self):
        return gateway.HostHealthSnapshot(
            mem_available_mb=self.mem,
            memory_psi_avg10=self.psi,
            recent_oom=self.oom,
            recent_gpu_reset=self.gpu,
            production_healthy=self.production,
            swap_activity_pages=0,
        )


class TestAdmission(unittest.TestCase):
    def setUp(self):
        self.fixture = ConfigFixture()
        self.config = gateway.load_config(self.fixture.path)

    def tearDown(self):
        self.fixture.close()

    def test_global_consumer_and_capability_counters_release(self):
        controller = gateway.AdmissionController(self.config)
        consumer = self.config.consumers["legacy-11440"]
        capability = self.config.capabilities["mbfd-general"]
        with controller.acquire(consumer, capability):
            self.assertEqual(controller.snapshot()["running_global"], 1)
        self.assertEqual(controller.snapshot()["running_global"], 0)

    def test_consumer_token_bucket_enforces_burst_and_refills(self):
        controller = gateway.AdmissionController(self.config)
        consumer = self.config.consumers["mbfd-bid"]
        capability = self.config.capabilities["mbfd-bid-analysis"]
        with mock.patch.object(gateway.time, "monotonic", return_value=100.0):
            for _ in range(3):
                with controller.acquire(consumer, capability):
                    pass
            with (
                self.assertRaises(gateway.GatewayError) as caught,
                controller.acquire(consumer, capability),
            ):
                pass
        self.assertEqual(caught.exception.status, 429)
        self.assertEqual(caught.exception.classification, "rate_limit")
        with (
            mock.patch.object(gateway.time, "monotonic", return_value=102.0),
            controller.acquire(consumer, capability),
        ):
            pass

    def test_concurrency_limit_is_structured(self):
        controller = gateway.AdmissionController(self.config)
        consumer = self.config.consumers["legacy-11440"]
        capability = self.config.capabilities["mbfd-general"]
        first = controller.acquire(consumer, capability)
        first.__enter__()
        self.addCleanup(first.__exit__, None, None, None)
        with (
            self.assertRaises(gateway.GatewayError) as caught,
            controller.acquire(consumer, capability, queue_timeout=0.01),
        ):
            pass
        self.assertEqual(caught.exception.classification, "concurrency_limit")

    def test_queue_limit_rejects_excess_waiter(self):
        config_data = self.fixture.data
        config_data["global"]["queue_limit"] = 1
        self.fixture.path.write_text(json.dumps(config_data), encoding="utf-8")
        config = gateway.load_config(self.fixture.path)
        controller = gateway.AdmissionController(config)
        consumer = config.consumers["legacy-11440"]
        capability = config.capabilities["mbfd-general"]
        first = controller.acquire(consumer, capability)
        first.__enter__()
        waiter_started = threading.Event()
        waiter_acquired = threading.Event()

        def wait_for_slot():
            waiter_started.set()
            with controller.acquire(consumer, capability, queue_timeout=0.15):
                waiter_acquired.set()

        thread = threading.Thread(target=wait_for_slot)
        thread.start()
        waiter_started.wait(1)
        for _ in range(50):
            if controller.snapshot()["queued"] == 1:
                break
            time.sleep(0.002)
        with (
            self.assertRaises(gateway.GatewayError) as caught,
            controller.acquire(consumer, capability, queue_timeout=0.01),
        ):
            pass
        self.assertEqual(caught.exception.classification, "concurrency_limit")
        first.__exit__(None, None, None)
        thread.join(1)
        self.assertTrue(waiter_acquired.is_set())

    def test_heavy_lease_conflict_and_release(self):
        manager = gateway.HeavyLeaseManager(self.config.heavy_workloads, FakeProbe())
        first = manager.acquire("primary-ollama-large")
        first.__enter__()
        with (
            self.assertRaises(gateway.GatewayError) as caught,
            manager.acquire("future-comfyui"),
        ):
            pass
        self.assertEqual(caught.exception.classification, "admission_denied")
        first.__exit__(None, None, None)
        with manager.acquire("future-comfyui"):
            self.assertEqual(manager.snapshot()["gpu-heavy"], "future-comfyui")

    def test_heavy_health_check_denies_low_memory(self):
        policies = self.config.heavy_workloads
        policies["primary-ollama-large"].mem_available_floor_mb = 4096
        manager = gateway.HeavyLeaseManager(policies, FakeProbe(mem=1024))
        with (
            self.assertRaises(gateway.GatewayError) as caught,
            manager.acquire("primary-ollama-large"),
        ):
            pass
        self.assertEqual(caught.exception.classification, "admission_denied")

    def test_declared_model_allocation_is_checked_only_for_cold_start(self):
        policies = self.config.heavy_workloads
        policy = policies["primary-ollama-large"]
        policy.mem_available_floor_mb = 1024
        policy.declared_model_allocation_mb = 8192
        manager = gateway.HeavyLeaseManager(policies, FakeProbe(mem=4096))
        with manager.acquire("primary-ollama-large", requires_allocation=False):
            pass
        with (
            self.assertRaises(gateway.GatewayError) as caught,
            manager.acquire("primary-ollama-large", requires_allocation=True),
        ):
            pass
        self.assertEqual(caught.exception.classification, "admission_denied")


class ScriptedBackend(http.server.ThreadingHTTPServer):
    def __init__(self, script):
        self.script = script
        self.requests = []
        self.attempts = 0
        super().__init__(("127.0.0.1", 0), ScriptedBackendHandler)


class ScriptedBackendHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _record(self):
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length) if length else b""
        self.server.requests.append((self.command, self.path, dict(self.headers), body))
        self.server.attempts += 1
        return body

    def do_GET(self):
        self._record()
        if self.path == "/api/version":
            self._json(200, {"version": "test"})
        elif self.path == "/api/tags":
            self._json(
                200,
                {
                    "models": [
                        {"name": name}
                        for name in self.server.script.get("available", [])
                    ]
                },
            )
        elif self.path == "/api/ps":
            self._json(
                200,
                {
                    "models": [
                        {"name": name} for name in self.server.script.get("loaded", [])
                    ]
                },
            )
        else:
            self._json(404, {"error": "missing"})

    def do_POST(self):
        body = self._record()
        action = self.server.script.get("post", "echo")
        if (
            action == "disconnect_once"
            and self.server.script.setdefault("disconnects", 0) == 0
        ):
            self.server.script["disconnects"] += 1
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        if action == "delay":
            time.sleep(self.server.script.get("delay", 0.25))
        if action == "stream":
            chunks = self.server.script.get(
                "chunks", [b'{"message":{"content":"a"}}\n', b'{"done":true}\n']
            )
            self.send_response(200)
            self.send_header("Content-Type", "application/x-ndjson")
            self.send_header("Transfer-Encoding", "chunked")
            self.end_headers()
            for index, chunk in enumerate(chunks):
                if index and self.server.script.get("delay_between_chunks"):
                    time.sleep(self.server.script["delay_between_chunks"])
                self.wfile.write(
                    f"{len(chunk):X}\r\n".encode("ascii") + chunk + b"\r\n"
                )
                self.wfile.flush()
            self.wfile.write(b"0\r\n\r\n")
            return
        if action == "error":
            self._json(
                self.server.script.get("status", 400),
                {"error": "upstream rejected request"},
            )
            return
        payload = json.loads(body or b"{}")
        self._json(200, {"model": payload.get("model"), "done": True, "eval_count": 7})

    def _json(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionAbortedError, ConnectionResetError):
            pass

    def log_message(self, *_args):
        pass


@contextlib.contextmanager
def running(server):
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield server
    finally:
        server.shutdown()
        server.server_close()
        thread.join(2)


def request(
    server,
    method,
    path,
    *,
    token="legacy-secret",
    payload=None,
    headers=None,
    timeout=3,
):
    conn = http.client.HTTPConnection("127.0.0.1", server.server_port, timeout=timeout)
    supplied = {"Authorization": f"Bearer {token}"} if token is not None else {}
    supplied.update(headers or {})
    body = None
    if payload is not None:
        body = json.dumps(payload).encode()
        supplied["Content-Type"] = "application/json"
    conn.request(method, path, body=body, headers=supplied)
    response = conn.getresponse()
    data = response.read()
    response_headers = dict(response.getheaders())
    conn.close()
    return response.status, response_headers, data


class TestModelReadiness(unittest.TestCase):
    def make_app(self, backend, mutate=None):
        url = f"http://127.0.0.1:{backend.server_port}"
        fixture = ConfigFixture(mutate, url)
        self.addCleanup(fixture.close)
        log_stream = io.StringIO()
        app = gateway.GatewayApplication(
            gateway.load_config(fixture.path), telemetry_stream=log_stream
        )
        return app, log_stream

    def test_backend_healthy_does_not_mean_model_ready(self):
        backend = ScriptedBackend({"available": ["mbfd-general"], "loaded": []})
        with running(backend):
            app, _ = self.make_app(backend)
            state = app.backend_readiness("ollama-primary", "mbfd-general")
        self.assertTrue(state.backend_healthy)
        self.assertTrue(state.model_available)
        self.assertFalse(state.model_loaded)
        self.assertEqual(state.classification, "model_cold")

    def test_model_not_found_is_distinct(self):
        backend = ScriptedBackend({"available": [], "loaded": []})
        with running(backend):
            app, _ = self.make_app(backend)
            state = app.backend_readiness("ollama-primary", "missing")
        self.assertEqual(state.classification, "model_not_found")

    def test_backend_unavailable_is_distinct(self):
        fixture = ConfigFixture(backend_url="http://127.0.0.1:1")
        self.addCleanup(fixture.close)
        app = gateway.GatewayApplication(
            gateway.load_config(fixture.path), telemetry_stream=io.StringIO()
        )
        state = app.backend_readiness("ollama-primary", "mbfd-general")
        self.assertEqual(state.classification, "backend_unavailable")

    def test_loaded_model_is_ready(self):
        backend = ScriptedBackend(
            {"available": ["mbfd-general:latest"], "loaded": ["mbfd-general:latest"]}
        )
        with running(backend):
            app, _ = self.make_app(backend)
            state = app.backend_readiness("ollama-primary", "mbfd-general")
        self.assertEqual(state.classification, None)
        self.assertTrue(state.model_loaded)

    def test_inflight_cold_start_is_reported_as_model_loading(self):
        backend = ScriptedBackend({"available": ["mbfd-general"], "loaded": []})
        with running(backend):
            app, _ = self.make_app(backend)
            app.mark_model_state("ollama-primary", "mbfd-general", "model_loading")
            state = app.backend_readiness("ollama-primary", "mbfd-general")
        self.assertEqual(state.classification, "model_loading")

    def test_failed_cold_start_is_reported_separately(self):
        backend = ScriptedBackend({"available": ["mbfd-general"], "loaded": []})
        with running(backend):
            app, _ = self.make_app(backend)
            app.mark_model_state("ollama-primary", "mbfd-general", "model_failed")
            state = app.backend_readiness("ollama-primary", "mbfd-general")
        self.assertEqual(state.classification, "model_failed")


class TestHTTPGateway(unittest.TestCase):
    def make_servers(self, script, mutate=None):
        backend = ScriptedBackend(script)
        backend_thread = threading.Thread(target=backend.serve_forever, daemon=True)
        backend_thread.start()
        url = f"http://127.0.0.1:{backend.server_port}"
        fixture = ConfigFixture(mutate, url)
        log_stream = io.StringIO()
        app = gateway.GatewayApplication(
            gateway.load_config(fixture.path), telemetry_stream=log_stream
        )
        server = gateway.GatewayHTTPServer(
            ("127.0.0.1", 0), gateway.GatewayRequestHandler, app
        )
        server_thread = threading.Thread(target=server.serve_forever, daemon=True)
        server_thread.start()

        def cleanup():
            server.shutdown()
            server.server_close()
            server_thread.join(2)
            backend.shutdown()
            backend.server_close()
            backend_thread.join(2)
            fixture.close()

        self.addCleanup(cleanup)
        return backend, server, log_stream, app

    def test_process_health_is_separate_and_authenticated(self):
        _, server, _, _ = self.make_servers({"available": [], "loaded": []})
        status, _, body = request(server, "GET", "/health/live")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["status"], "alive")
        status, _, _ = request(server, "GET", "/health/live", token=None)
        self.assertEqual(status, 401)

    def test_gateway_ready_with_backend_healthy_even_when_model_cold(self):
        _, server, _, _ = self.make_servers(
            {
                "available": [
                    "mbfd-general",
                    "mbfd-code:32k",
                    "mbfd-embeddings",
                    "qwen3.6:35b",
                ],
                "loaded": [],
            }
        )
        status, _, body = request(server, "GET", "/health/ready")
        self.assertEqual(status, 200)
        data = json.loads(body)
        self.assertEqual(data["status"], "ready")
        self.assertEqual(data["backends"]["ollama-primary"]["service"], "healthy")

    def test_backend_readiness_reports_model_availability_and_loaded_state(self):
        _, server, _, _ = self.make_servers(
            {
                "available": [
                    "mbfd-general",
                    "mbfd-code:32k",
                    "mbfd-embeddings",
                    "qwen3.6:35b",
                ],
                "loaded": ["mbfd-embeddings"],
            }
        )
        status, _, body = request(server, "GET", "/health/backends")
        self.assertEqual(status, 200)
        report = json.loads(body)
        general = report["capabilities"]["mbfd-general"]
        embeddings = report["capabilities"]["mbfd-embeddings"]
        self.assertEqual(general["state"], "model_cold")
        self.assertTrue(general["model_available"])
        self.assertFalse(general["model_loaded"])
        self.assertEqual(embeddings["state"], "ready")
        self.assertTrue(embeddings["model_loaded"])
        self.assertEqual(
            report["capabilities"]["mbfd-transcribe"]["state"], "backend_unavailable"
        )

    def test_native_and_openai_requests_forward_with_request_id(self):
        backend, server, _, _ = self.make_servers(
            {"available": ["mbfd-general"], "loaded": ["mbfd-general"]}
        )
        for path in ("/api/chat", "/v1/chat/completions"):
            status, headers, body = request(
                server,
                "POST",
                path,
                payload={
                    "model": "mbfd-general",
                    "messages": [{"role": "user", "content": "hello"}],
                },
                headers={"X-Request-ID": "trusted-test-id"},
            )
            self.assertEqual(status, 200)
            self.assertEqual(headers["X-Request-ID"], "trusted-test-id")
            self.assertEqual(json.loads(body)["model"], "mbfd-general")
        received_headers = {
            key.lower(): value for key, value in backend.requests[-1][2].items()
        }
        self.assertEqual(received_headers["x-request-id"], "trusted-test-id")

    def test_bid_openai_request_enforces_policy_and_consumer_identity(self):
        backend, server, logs, _ = self.make_servers(
            {
                "available": ["mbfd-bid-analysis-16k"],
                "loaded": ["mbfd-bid-analysis-16k"],
            }
        )
        status, headers, _ = request(
            server,
            "POST",
            "/v1/chat/completions",
            token="bid-secret",
            payload={
                "model": "mbfd-bid-analysis",
                "messages": [{"role": "user", "content": "synthetic bid"}],
                "temperature": 2,
                "max_tokens": 4096,
                "reasoning_effort": "high",
            },
            headers={
                "X-MBFD-Capability": "mbfd-bid-analysis",
                "X-Request-ID": "bid-policy-test",
            },
        )
        self.assertEqual(status, 200)
        self.assertEqual(headers["X-Request-ID"], "bid-policy-test")
        forwarded = json.loads(backend.requests[-1][3])
        self.assertEqual(forwarded["model"], "mbfd-bid-analysis-16k")
        self.assertEqual(forwarded["temperature"], 0.1)
        self.assertEqual(forwarded["top_p"], 0.9)
        self.assertEqual(forwarded["max_tokens"], 640)
        self.assertEqual(forwarded["reasoning_effort"], "none")
        records = [json.loads(line) for line in logs.getvalue().splitlines()]
        self.assertEqual(records[-1]["consumer_id"], "mbfd-bid")
        self.assertEqual(records[-1]["capability"], "mbfd-bid-analysis")
        self.assertEqual(records[-1]["selected_model"], "mbfd-bid-analysis-16k")

    def test_ops_summary_cold_fails_fast_without_starting_model(self):
        backend, server, _, _ = self.make_servers(
            {"available": ["qwen3.6:35b"], "loaded": []}
        )
        started = time.monotonic()
        status, _, body = request(
            server,
            "POST",
            "/api/chat",
            token="hermes-secret",
            payload={"model": "mbfd-ops-summary", "messages": []},
            headers={"X-MBFD-Capability": "mbfd-ops-summary"},
        )
        elapsed = time.monotonic() - started
        self.assertEqual(status, 503)
        self.assertLess(elapsed, 0.5)
        self.assertEqual(json.loads(body)["error"]["classification"], "model_cold")
        self.assertFalse(any(item[0] == "POST" for item in backend.requests))

    def test_model_not_found_result_is_machine_readable(self):
        _, server, _, _ = self.make_servers({"available": [], "loaded": []})
        status, _, body = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "messages": []},
        )
        self.assertEqual(status, 503)
        self.assertEqual(json.loads(body)["error"]["classification"], "model_not_found")

    def test_disabled_backend_result_is_machine_readable(self):
        def mutate(data):
            data["consumers"]["legacy-11440"]["allowed_capabilities"].append(
                "mbfd-transcribe"
            )

        _, server, _, _ = self.make_servers({"available": [], "loaded": []}, mutate)
        status, _, body = request(
            server,
            "POST",
            "/v1/audio/transcriptions",
            payload={"model": "mbfd-transcribe"},
            headers={"X-MBFD-Capability": "mbfd-transcribe"},
        )
        self.assertEqual(status, 503)
        self.assertEqual(
            json.loads(body)["error"]["classification"], "backend_unavailable"
        )

    def test_loading_timeout_is_distinct(self):
        def mutate(data):
            data["capabilities"]["mbfd-general"]["cold_start"][
                "ready_timeout_seconds"
            ] = 0.05

        _, server, _, _ = self.make_servers(
            {
                "available": ["mbfd-general"],
                "loaded": [],
                "post": "delay",
                "delay": 0.3,
            },
            mutate,
        )
        status, _, body = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "messages": []},
        )
        self.assertEqual(status, 503)
        self.assertEqual(
            json.loads(body)["error"]["classification"], "model_loading_timeout"
        )

    def test_retry_is_bounded_and_telemetry_records_count(self):
        backend, server, logs, _ = self.make_servers(
            {
                "available": ["mbfd-embeddings"],
                "loaded": ["mbfd-embeddings"],
                "post": "disconnect_once",
            }
        )
        status, _, _ = request(
            server,
            "POST",
            "/api/embed",
            payload={"model": "mbfd-embeddings", "input": "x"},
        )
        self.assertEqual(status, 200)
        for _ in range(100):
            if logs.getvalue().strip():
                break
            time.sleep(0.002)
        records = [json.loads(line) for line in logs.getvalue().splitlines()]
        self.assertEqual(records[-1]["retry_count"], 1)
        post_attempts = [item for item in backend.requests if item[0] == "POST"]
        self.assertEqual(len(post_attempts), 2)

    def test_streaming_is_not_buffered_and_completes(self):
        _backend, server, _, _ = self.make_servers(
            {
                "available": ["mbfd-general"],
                "loaded": ["mbfd-general"],
                "post": "stream",
                "chunks": [b'{"message":{"content":"one"}}\n', b'{"done":true}\n'],
            }
        )
        status, headers, body = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "stream": True, "messages": []},
        )
        self.assertEqual(status, 200)
        self.assertIn(b'"content":"one"', body)
        self.assertIn(b'"done":true', body)
        self.assertEqual(headers["Transfer-Encoding"], "chunked")

    def test_upstream_http_error_status_and_body_are_forwarded(self):
        _, server, _, _ = self.make_servers(
            {
                "available": ["mbfd-general"],
                "loaded": ["mbfd-general"],
                "post": "error",
                "status": 400,
            }
        )
        status, _, body = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "messages": []},
        )
        self.assertEqual(status, 400)
        self.assertEqual(json.loads(body)["error"], "upstream rejected request")

    def test_cold_start_deadline_stops_applying_after_first_response_chunk(self):
        def mutate(data):
            data["capabilities"]["mbfd-general"]["cold_start"][
                "ready_timeout_seconds"
            ] = 0.05
            data["capabilities"]["mbfd-general"]["timeout_seconds"] = 0.5

        _, server, _, _ = self.make_servers(
            {
                "available": ["mbfd-general"],
                "loaded": [],
                "post": "stream",
                "chunks": [b'{"message":{"content":"ready"}}\n', b'{"done":true}\n'],
                "delay_between_chunks": 0.15,
            },
            mutate,
        )
        status, _, body = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "stream": True, "messages": []},
        )
        self.assertEqual(status, 200)
        self.assertIn(b'"done":true', body)

    def test_structured_telemetry_has_required_fields_and_no_prompt_or_secret(self):
        _, server, logs, _ = self.make_servers(
            {"available": ["mbfd-general"], "loaded": ["mbfd-general"]}
        )
        secret_prompt = "sensitive-prompt-never-log"
        request(
            server,
            "POST",
            "/api/chat",
            payload={
                "model": "mbfd-general",
                "messages": [{"role": "user", "content": secret_prompt}],
            },
        )
        for _ in range(100):
            if logs.getvalue().strip():
                break
            time.sleep(0.002)
        text = logs.getvalue()
        self.assertNotIn(secret_prompt, text)
        self.assertNotIn("legacy-secret", text)
        record = json.loads(text.splitlines()[-1])
        for field in (
            "timestamp",
            "consumer_id",
            "request_id",
            "capability",
            "selected_backend",
            "selected_model",
            "method",
            "path",
            "response_status",
            "latency_ms",
            "retry_count",
            "token_usage",
            "tool_call_count",
            "error_classification",
        ):
            self.assertIn(field, record)

    def test_request_id_is_generated_for_unsafe_incoming_value(self):
        _, server, _, _ = self.make_servers(
            {"available": ["mbfd-general"], "loaded": ["mbfd-general"]}
        )
        status, headers, _ = request(
            server,
            "POST",
            "/api/chat",
            payload={"model": "mbfd-general", "messages": []},
            headers={"X-Request-ID": "bad\tvalue"},
        )
        self.assertEqual(status, 200)
        self.assertNotEqual(headers["X-Request-ID"], "bad\tvalue")
        self.assertEqual(len(headers["X-Request-ID"]), 32)

    def test_catalog_backward_compatibility(self):
        _, server, _, _ = self.make_servers({"available": [], "loaded": []})
        status, _, body = request(server, "GET", "/v1/models")
        self.assertEqual(status, 200)
        ids = {item["id"] for item in json.loads(body)["data"]}
        self.assertIn("mbfd-general", ids)
        self.assertIn("mbfd-general-deep", ids)
        self.assertIn("mbfd-embeddings", ids)

    def test_api_version_backward_compatibility(self):
        _, server, _, _ = self.make_servers({"available": [], "loaded": []})
        status, _, body = request(server, "GET", "/api/version")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["version"], "test")

    def test_invalid_auth_smoke_shape(self):
        _, server, _, _ = self.make_servers({"available": [], "loaded": []})
        status, _, body = request(server, "GET", "/v1/models", token="wrong")
        self.assertEqual(status, 401)
        self.assertEqual(
            json.loads(body)["error"]["classification"], "authentication_failed"
        )


if __name__ == "__main__":
    unittest.main()
