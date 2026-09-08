import importlib.util
import json
import subprocess
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock


path = Path(__file__).resolve().parents[2] / 'scripts/identity/nextcloud_bridge.py'
spec = importlib.util.spec_from_file_location('nextcloud_bridge', path)
bridge_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge_module)


class NextcloudRelayTest(unittest.TestCase):
    def setUp(self):
        self.request = {'uid': 'approveduser', 'revision': 2, 'enabled': False}
        self.ack = {**self.request, 'old_tokens_purged': True}
        self.run = Mock(return_value=SimpleNamespace(returncode=0, stdout=json.dumps(self.ack)))

    def reconcile(self, request=None):
        return bridge_module.reconcile(self.request if request is None else request, {'approveduser'}, self.run)

    def test_exact_validated_request_goes_only_to_fixed_complete_container_engine(self):
        self.assertEqual(self.ack, self.reconcile())
        self.run.assert_called_once_with(
            ['/usr/bin/docker', 'exec', '-i', '-u', 'www-data', 'mbfd-nextcloud', 'php',
             '/var/www/html/config/hub-identity-bridge.cli.php'],
            input=json.dumps(self.request), capture_output=True, text=True, timeout=20, check=False,
        )

    def test_unapproved_shari_and_path_injection_never_execute(self):
        for uid in ['sharilipner', '../approveduser', 'approveduser;id', 'missing', '-root']:
            with self.subTest(uid=uid), self.assertRaises(ValueError):
                self.reconcile({**self.request, 'uid': uid})
        self.run.assert_not_called()

    def test_invalid_types_and_additional_fields_never_execute(self):
        for body in [
            {**self.request, 'revision': True}, {**self.request, 'revision': 0},
            {**self.request, 'enabled': 'false'}, {**self.request, 'command': 'user:delete'}, [],
        ]:
            with self.assertRaises(ValueError):
                self.reconcile(body)
        self.run.assert_not_called()

    def test_uncertain_timeout_is_never_retried_or_acknowledged_by_relay(self):
        self.run.side_effect = subprocess.TimeoutExpired('fixed container engine', 20)
        with self.assertRaises(subprocess.TimeoutExpired):
            self.reconcile()
        self.run.assert_called_once()

    def test_failed_or_oversized_engine_response_is_not_acknowledged(self):
        for result in [SimpleNamespace(returncode=1, stdout='private error'),
                       SimpleNamespace(returncode=0, stdout='x' * 4097)]:
            self.run.return_value = result
            with self.assertRaises(RuntimeError):
                self.reconcile()

    def test_wrong_identity_revision_state_or_purge_flag_is_rejected(self):
        for bad in [
            {**self.ack, 'uid': 'different'}, {**self.ack, 'revision': 1},
            {**self.ack, 'revision': True}, {**self.ack, 'enabled': 0},
            {**self.ack, 'old_tokens_purged': False}, {**self.ack, 'extra': 'unsupported'}, [],
        ]:
            self.run.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(bad))
            with self.assertRaises(RuntimeError):
                self.reconcile()


if __name__ == '__main__':
    unittest.main()
