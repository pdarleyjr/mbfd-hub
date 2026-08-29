#!/usr/bin/env python3
"""Contract tests for the authenticated logical-model Ollama gateway."""

import importlib.util
import json
import pathlib
import sys
from unittest import mock
import unittest

MODULE_PATH = (
    pathlib.Path(__file__).resolve().parents[2]
    / "scripts"
    / "operations"
    / "ollama-ai-proxy.py"
)
SPEC = importlib.util.spec_from_file_location("ollama_ai_proxy", MODULE_PATH)
assert SPEC and SPEC.loader
proxy = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = proxy
SPEC.loader.exec_module(proxy)


class GatewayPolicyTests(unittest.TestCase):
    def setUp(self) -> None:
        self.policy = proxy.GatewayPolicy(
            general_model="mbfd-general",
            general_deep_model="mbfd-general-deep",
            embedding_model="mbfd-embeddings",
            standard_context=32768,
            deep_context=65536,
            legacy_general_models=frozenset({"qwen3.6:35b", "qwen3.6:27b-q8_0"}),
            legacy_embedding_models=frozenset({"bge-m3:latest"}),
        )

    def rewrite(self, target: str, payload: dict) -> dict:
        rewritten = proxy.rewrite_json_request(
            target,
            json.dumps(payload).encode("utf-8"),
            self.policy,
        )
        return json.loads(rewritten)

    def test_native_standard_request_uses_logical_model_and_32k_context(self) -> None:
        request = self.rewrite(
            "/api/chat",
            {
                "model": "mbfd-general",
                "messages": [{"role": "user", "content": "status"}],
                "options": {"temperature": 0.1, "num_ctx": 262144},
            },
        )

        self.assertEqual("mbfd-general", request["model"])
        self.assertEqual(32768, request["options"]["num_ctx"])
        self.assertEqual(0.1, request["options"]["temperature"])

    def test_openai_generation_options_cannot_bypass_context_policy(self) -> None:
        request = self.rewrite(
            "/v1/responses",
            {
                "model": "mbfd-general-deep",
                "input": "Analyze this carefully.",
                "options": {"num_ctx": 262144},
            },
        )

        self.assertEqual("mbfd-general-deep", request["model"])
        self.assertEqual(65536, request["options"]["num_ctx"])

    def test_legacy_general_model_is_migrated_for_openai_chat(self) -> None:
        request = self.rewrite(
            "/v1/chat/completions",
            {
                "model": "qwen3.6:35b",
                "messages": [{"role": "user", "content": "status"}],
            },
        )

        self.assertEqual("mbfd-general", request["model"])
        self.assertEqual(32768, request["options"]["num_ctx"])

    def test_deep_profile_forces_64k_context(self) -> None:
        request = self.rewrite(
            "/api/generate",
            {
                "model": "mbfd-general-deep",
                "prompt": "Analyze this carefully.",
                "options": {"num_ctx": 1024},
            },
        )

        self.assertEqual("mbfd-general-deep", request["model"])
        self.assertEqual(65536, request["options"]["num_ctx"])

    def test_embedding_legacy_model_is_migrated(self) -> None:
        request = self.rewrite(
            "/v1/embeddings",
            {"model": "bge-m3:latest", "input": "Miami Beach Fire Department"},
        )

        self.assertEqual("mbfd-embeddings", request["model"])

    def test_responses_api_is_an_allowed_model_rewrite_route(self) -> None:
        request = self.rewrite(
            "/v1/responses",
            {
                "model": "qwen3.6:27b-q8_0",
                "input": "Reply only with READY.",
            },
        )

        self.assertEqual("mbfd-general", request["model"])
        self.assertTrue(proxy.is_allowed_gateway_route("POST", "/v1/responses"))

    def test_native_model_catalog_exposes_only_logical_aliases(self) -> None:
        catalog = self.policy.native_catalog()
        self.assertEqual(
            ["mbfd-general", "mbfd-general-deep", "mbfd-embeddings"],
            [model["name"] for model in catalog["models"]],
        )

    def test_policy_rejects_unsafe_context_limits_and_alias_collisions(self) -> None:
        with self.assertRaises(ValueError):
            proxy.GatewayPolicy(
                general_model="mbfd-general",
                general_deep_model="mbfd-general-deep",
                embedding_model="mbfd-embeddings",
                standard_context=32769,
                deep_context=65536,
                legacy_general_models=frozenset(),
                legacy_embedding_models=frozenset(),
            )
        with self.assertRaises(ValueError):
            proxy.GatewayPolicy(
                general_model="mbfd-general",
                general_deep_model="mbfd-general-deep",
                embedding_model="mbfd-embeddings",
                standard_context=32768,
                deep_context=65536,
                legacy_general_models=frozenset({"mbfd-general"}),
                legacy_embedding_models=frozenset(),
            )

    def test_opener_ignores_environment_proxy_configuration(self) -> None:
        self.assertFalse(
            any(
                isinstance(handler, proxy.urllib.request.ProxyHandler)
                and getattr(handler, "proxies", {})
                for handler in proxy.OPENER.handlers
            )
        )

    def test_credential_directory_expands_systemd_specifier(self) -> None:
        with mock.patch.dict(
            proxy.os.environ,
            {
                "OLLAMA_PROXY_API_KEY_FILE": "%d/api-key",
                "CREDENTIALS_DIRECTORY": "/run/credentials/ollama-ai-proxy-staging.service",
            },
            clear=True,
        ):
            self.assertEqual(
                "/run/credentials/ollama-ai-proxy-staging.service/api-key",
                proxy.credential_path_from_environment(),
            )
    def test_unknown_model_is_rejected(self) -> None:
        with self.assertRaises(proxy.PolicyError):
            self.rewrite(
                "/api/chat",
                {
                    "model": "qwen3.6:27b-bf16",
                    "messages": [{"role": "user", "content": "status"}],
                },
            )

    def test_generation_model_cannot_be_used_for_embeddings(self) -> None:
        with self.assertRaises(proxy.PolicyError):
            self.rewrite(
                "/api/embed",
                {"model": "mbfd-general", "input": "not an embedding model"},
            )

    def test_model_management_and_process_routes_are_not_allowed(self) -> None:
        self.assertFalse(proxy.is_allowed_gateway_route("POST", "/api/pull"))
        self.assertFalse(proxy.is_allowed_gateway_route("POST", "/api/delete"))
        self.assertFalse(proxy.is_allowed_gateway_route("GET", "/api/create"))
        self.assertFalse(proxy.is_allowed_gateway_route("GET", "/api/ps"))

    def test_allowed_read_and_inference_routes_are_explicit(self) -> None:
        self.assertTrue(proxy.is_allowed_gateway_route("GET", "/api/version"))
        self.assertTrue(proxy.is_allowed_gateway_route("GET", "/api/tags"))
        self.assertTrue(proxy.is_allowed_gateway_route("GET", "/health"))
        self.assertTrue(proxy.is_allowed_gateway_route("GET", "/v1/models"))
        self.assertTrue(proxy.is_allowed_gateway_route("POST", "/api/chat"))
        self.assertTrue(proxy.is_allowed_gateway_route("POST", "/api/embed"))


if __name__ == "__main__":
    unittest.main()
