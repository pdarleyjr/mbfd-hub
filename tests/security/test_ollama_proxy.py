"""Regression tests for the loopback-only Ollama reverse proxy boundary."""

import http.client
import http.server
import importlib.util
import json
from pathlib import Path
import socket
import threading
import time
import unittest


MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "operations"
    / "ollama-ai-proxy.py"
)
SPEC = importlib.util.spec_from_file_location("ollama_ai_proxy", MODULE_PATH)
PROXY = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(PROXY)


class OllamaProxySafetyTest(unittest.TestCase):
    def test_loopback_origins_are_canonicalized(self):
        self.assertEqual(
            PROXY.validate_upstream("http://127.0.0.1:11434/"),
            "http://127.0.0.1:11434",
        )
        self.assertEqual(
            PROXY.validate_upstream("http://localhost:11434"),
            "http://localhost:11434",
        )
        self.assertEqual(
            PROXY.validate_upstream("http://[::1]:11434"),
            "http://[::1]:11434",
        )

    def test_non_loopback_or_ambiguous_origins_are_rejected(self):
        for value in (
            "https://127.0.0.1:11434",
            "http://192.0.2.1:11434",
            "http://127.0.0.1:11434/api",
            "http://user@127.0.0.1:11434",
            "http://localhost:11434?target=evil",
        ):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    PROXY.validate_upstream(value)

    def test_only_origin_form_request_targets_are_accepted(self):
        self.assertEqual(
            PROXY.relative_request_target("/api/generate?stream=false"),
            "/api/generate?stream=false",
        )
        for value in ("//example.test/path", "https://example.test/path", "relative"):
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    PROXY.relative_request_target(value)


class OllamaProxyStreamingTest(unittest.TestCase):
    first_chunk = b'{"message":"first"}\n'
    second_chunk = b'{"message":"second"}\n'

    def setUp(self):
        self.upstream_requests = []
        self.upstream_request_ids = []
        self.upstream_health_paths = []
        self.hold_started = threading.Event()
        self.release_hold = threading.Event()
        test_case = self

        class UpstreamHandler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def do_GET(self):
                test_case.upstream_health_paths.append(self.path)
                if self.path == "/api/version":
                    response = b'{"version":"test"}'
                elif self.path == "/api/tags":
                    response = json.dumps(
                        {
                            "models": [
                                {"name": "mbfd-general:latest"},
                                {"name": "mbfd-general-deep:latest"},
                                {"name": "mbfd-embeddings:latest"},
                            ]
                        }
                    ).encode("utf-8")
                else:
                    self.send_error(404)
                    return
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(response)))
                self.end_headers()
                self.wfile.write(response)

            def do_POST(self):
                length = int(self.headers["Content-Length"])
                request = json.loads(self.rfile.read(length).decode("utf-8"))
                test_case.upstream_requests.append(request)
                test_case.upstream_request_ids.append(self.headers.get("X-Request-ID"))
                content = request["messages"][0]["content"]

                if content == "break upstream":
                    self.send_response(200)
                    self.send_header("Content-Type", "application/x-ndjson")
                    self.send_header("Content-Length", "999")
                    self.send_header("Connection", "close")
                    self.end_headers()
                    self.wfile.write(test_case.first_chunk)
                    self.wfile.flush()
                    self.close_connection = True
                    return

                self.send_response(200)
                self.send_header("Content-Type", "application/x-ndjson")
                self.send_header("Connection", "close")
                self.end_headers()
                self.wfile.write(test_case.first_chunk)
                self.wfile.flush()

                if content == "hold":
                    test_case.hold_started.set()
                    test_case.release_hold.wait(timeout=3)
                else:
                    time.sleep(0.35)

                self.wfile.write(test_case.second_chunk)
                self.wfile.flush()
                self.close_connection = True

            def log_message(self, *_args):
                return

        self.upstream = http.server.ThreadingHTTPServer(("127.0.0.1", 0), UpstreamHandler)
        self.upstream_thread = threading.Thread(target=self.upstream.serve_forever)
        self.upstream_thread.start()

        self.original_upstream = PROXY.UPSTREAM
        self.original_api_key = PROXY.API_KEY
        self.original_policy = PROXY.POLICY
        PROXY.UPSTREAM = f"http://127.0.0.1:{self.upstream.server_port}"
        PROXY.API_KEY = "test-gateway-key"
        PROXY.POLICY = PROXY.GatewayPolicy(
            general_model="mbfd-general",
            general_deep_model="mbfd-general-deep",
            embedding_model="mbfd-embeddings",
            standard_context=32768,
            deep_context=65536,
            legacy_general_models=frozenset(),
            legacy_embedding_models=frozenset(),
        )

        self.gateway = http.server.ThreadingHTTPServer(("127.0.0.1", 0), PROXY.Handler)
        self.gateway_thread = threading.Thread(target=self.gateway.serve_forever)
        self.gateway_thread.start()

    def tearDown(self):
        self.release_hold.set()
        self.gateway.shutdown()
        self.gateway.server_close()
        self.gateway_thread.join()
        self.upstream.shutdown()
        self.upstream.server_close()
        self.upstream_thread.join()
        PROXY.UPSTREAM = self.original_upstream
        PROXY.API_KEY = self.original_api_key
        PROXY.POLICY = self.original_policy

    def test_broken_upstream_does_not_append_a_second_http_response(self):
        payload = json.dumps(
            {
                "model": "mbfd-general",
                "messages": [{"role": "user", "content": "break upstream"}],
            }
        ).encode("utf-8")
        connection = socket.create_connection(("127.0.0.1", self.gateway.server_port))
        connection.settimeout(2)
        connection.sendall(
            b"POST /api/chat HTTP/1.1\r\n"
            b"Host: gateway.test\r\n"
            b"Authorization: Bearer test-gateway-key\r\n"
            b"Content-Type: application/json\r\n"
            + f"Content-Length: {len(payload)}\r\n".encode("ascii")
            + b"\r\n"
            + payload
        )
        connection.shutdown(socket.SHUT_WR)
        received = bytearray()
        while True:
            try:
                chunk = connection.recv(4096)
            except socket.timeout:
                break
            if not chunk:
                break
            received.extend(chunk)
        connection.close()

        self.assertEqual(1, bytes(received).count(b"HTTP/1.1"))
        self.assertNotIn(b"proxy_error", bytes(received))
        self.assertNotIn(b"\r\n0\r\n\r\n", bytes(received))

    def test_busy_generation_is_rejected_without_loading_another_session(self):
        first_finished = threading.Event()

        def hold_generation():
            connection = http.client.HTTPConnection("127.0.0.1", self.gateway.server_port)
            connection.request(
                "POST",
                "/api/chat",
                body=json.dumps(
                    {
                        "model": "mbfd-general",
                        "messages": [{"role": "user", "content": "hold"}],
                    }
                ),
                headers={
                    "Authorization": "Bearer test-gateway-key",
                    "Content-Type": "application/json",
                },
            )
            response = connection.getresponse()
            response.read()
            connection.close()
            first_finished.set()

        thread = threading.Thread(target=hold_generation)
        thread.start()
        self.assertTrue(self.hold_started.wait(timeout=2))

        connection = http.client.HTTPConnection("127.0.0.1", self.gateway.server_port)
        connection.request(
            "POST",
            "/api/chat",
            body=json.dumps(
                {
                    "model": "mbfd-general",
                    "messages": [{"role": "user", "content": "second"}],
                }
            ),
            headers={
                "Authorization": "Bearer test-gateway-key",
                "Content-Type": "application/json",
            },
        )
        response = connection.getresponse()
        response.read()
        connection.close()

        self.assertEqual(429, response.status)
        self.assertEqual("close", response.getheader("Connection"))
        self.release_hold.set()
        thread.join(timeout=2)
        self.assertTrue(first_finished.is_set())

    def test_health_probes_real_upstream_and_preserves_request_id(self):
        connection = http.client.HTTPConnection("127.0.0.1", self.gateway.server_port)
        connection.request(
            "GET",
            "/health",
            headers={
                "Authorization": "Bearer test-gateway-key",
                "X-Request-ID": "health-contract-001",
            },
        )
        response = connection.getresponse()
        payload = json.loads(response.read().decode("utf-8"))
        connection.close()

        self.assertEqual(200, response.status)
        self.assertEqual({"status": "ok"}, payload)
        self.assertEqual("health-contract-001", response.getheader("X-Request-ID"))
        self.assertEqual({"/api/version", "/api/tags"}, set(self.upstream_health_paths))

    def test_non_post_body_closes_before_a_second_request_can_be_parsed(self):
        connection = socket.create_connection(("127.0.0.1", self.gateway.server_port))
        connection.settimeout(2)
        connection.sendall(
            b"OPTIONS /health HTTP/1.1\r\n"
            b"Host: gateway.test\r\n"
            b"Authorization: Bearer test-gateway-key\r\n"
            b"Content-Length: 4\r\n"
            b"\r\n"
            b"JUNK"
            b"GET /health HTTP/1.1\r\n"
            b"Host: gateway.test\r\n"
            b"Authorization: Bearer test-gateway-key\r\n"
            b"\r\n"
        )
        connection.shutdown(socket.SHUT_WR)
        received = bytearray()
        while True:
            try:
                chunk = connection.recv(4096)
            except socket.timeout:
                break
            if not chunk:
                break
            received.extend(chunk)
        connection.close()

        self.assertEqual(1, bytes(received).count(b"HTTP/1.1"))
        self.assertIn(b"HTTP/1.1 400", bytes(received))
        self.assertIn(b"Connection: close", bytes(received))
    def test_rejected_post_closes_before_a_second_request_can_be_parsed(self):
        connection = socket.create_connection(("127.0.0.1", self.gateway.server_port))
        connection.settimeout(2)
        connection.sendall(
            b"POST /api/pull HTTP/1.1\r\n"
            b"Host: gateway.test\r\n"
            b"Authorization: Bearer test-gateway-key\r\n"
            b"Content-Length: 4\r\n"
            b"\r\n"
            b"JUNK"
            b"GET /health HTTP/1.1\r\n"
            b"Host: gateway.test\r\n"
            b"Authorization: Bearer test-gateway-key\r\n"
            b"\r\n"
        )
        connection.shutdown(socket.SHUT_WR)
        received = bytearray()
        while True:
            try:
                chunk = connection.recv(4096)
            except socket.timeout:
                break
            if not chunk:
                break
            received.extend(chunk)
        connection.close()

        self.assertEqual(1, bytes(received).count(b"HTTP/1.1"))
        self.assertIn(b"Connection: close", bytes(received))

    def test_ndjson_is_forwarded_before_the_upstream_stream_finishes(self):
        connection = http.client.HTTPConnection("127.0.0.1", self.gateway.server_port)
        started = time.monotonic()
        connection.request(
            "POST",
            "/api/chat",
            body=json.dumps(
                {
                    "model": "mbfd-general",
                    "messages": [{"role": "user", "content": "status"}],
                    "stream": True,
                }
            ),
            headers={
                "Authorization": "Bearer test-gateway-key",
                "Content-Type": "application/json",
            },
        )
        response = connection.getresponse()
        gateway_request_id = response.getheader("X-Request-ID")
        first = response.read(len(self.first_chunk))
        first_elapsed = time.monotonic() - started
        remaining = response.read()
        connection.close()

        self.assertEqual(200, response.status)
        self.assertEqual("chunked", response.getheader("Transfer-Encoding"))
        self.assertTrue(gateway_request_id)
        self.assertEqual(self.first_chunk, first)
        self.assertLess(first_elapsed, 0.25)
        self.assertEqual(self.second_chunk, remaining)
        self.assertEqual("mbfd-general", self.upstream_requests[0]["model"])
        self.assertEqual(32768, self.upstream_requests[0]["options"]["num_ctx"])
        self.assertEqual(gateway_request_id, self.upstream_request_ids[0])


if __name__ == "__main__":
    unittest.main()
