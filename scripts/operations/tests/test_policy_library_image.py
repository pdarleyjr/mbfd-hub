import copy
import importlib.util
import json
import unittest
import tempfile
import urllib.error
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('policy_image', Path(__file__).parents[1] / 'policy-library-image.py')
image = importlib.util.module_from_spec(spec)
spec.loader.exec_module(image)


class CanonicalImageTests(unittest.TestCase):
    def setUp(self):
        self.sha = 'a' * 40
        self.digest = 'sha256:' + 'b' * 64
        self.metadata = {'id': 123, 'path': '.github/workflows/prepare-production-image.yml',
                         'head_sha': self.sha, 'head_branch': 'main', 'event': 'workflow_dispatch', 'conclusion': 'success'}
        self.record = {'schema': 1, 'hub_sha': self.sha, 'run_id': '123', 'image_digest': self.digest,
                       'image_ref': image.POLICY + '@' + self.digest, 'hub_base_image': image.HUB + '@sha256:' + 'c' * 64,
                       'policy_library_enabled': True, 'policy_library_revision': 'd' * 40,
                       'policy_library_carrier_digest': 'sha256:' + 'e' * 64, 'gates': 'PASS'}

    def resolve(self, record=None, metadata=None, duplicate=False):
        line = 'build\tcanonical-output\t2026-10-01T00:00:00Z ' + image.MARKER + json.dumps(record or self.record)
        return image.resolve(metadata or self.metadata, line + ('\n' + line if duplicate else ''), self.sha, self.digest, '123')

    def test_exact_canonical_extension(self):
        self.assertEqual(self.resolve(), self.record)

    def test_exact_base_only_canonical_output(self):
        record = copy.deepcopy(self.record)
        record.update(policy_library_enabled=False, policy_library_revision='', policy_library_carrier_digest='',
                      image_ref=image.HUB + '@' + self.digest, hub_base_image=image.HUB + '@' + self.digest)
        self.assertEqual(self.resolve(record), record)

    def test_rejects_noncanonical_failed_wrong_source_or_wrong_run(self):
        for key, value in [('path', '.github/workflows/other.yml'), ('head_sha', 'f' * 40), ('head_branch', 'feature'),
                           ('event', 'push'), ('conclusion', 'failure'), ('id', 124)]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.resolve(metadata={**self.metadata, key: value})

    def test_rejects_unverified_or_mismatched_image_provenance(self):
        for key, value in [('schema', 2), ('hub_sha', 'f' * 40), ('run_id', '124'), ('gates', 'FAIL'),
                           ('image_digest', 'sha256:' + 'f' * 64), ('image_ref', image.HUB + '@' + self.digest),
                           ('policy_library_enabled', 'true'), ('policy_library_revision', 'main'),
                           ('policy_library_carrier_digest', 'latest'), ('hub_base_image', 'mutable:latest')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.resolve({**self.record, key: value})

    def test_rejects_duplicate_or_unbounded_log_record(self):
        with self.assertRaises(RuntimeError):
            self.resolve(duplicate=True)
        with self.assertRaises(RuntimeError):
            self.resolve({**self.record, 'unexpected': 'x' * 2049})

    @patch.object(image.urllib.request, 'urlopen')
    def test_only_explicit_anonymous_denial_passes(self, urlopen):
        urlopen.side_effect = urllib.error.HTTPError('https://ghcr.io/token', 403, 'denied', None, None)
        image.private_package()
        for status in (401, 404, 429, 500):
            with self.subTest(status=status), self.assertRaises(RuntimeError):
                urlopen.side_effect = urllib.error.HTTPError('https://ghcr.io/token', status, 'error', None, None)
                image.private_package()
        urlopen.side_effect = urllib.error.URLError('network unavailable')
        with self.assertRaises(urllib.error.URLError):
            image.private_package()

    @patch.object(image.urllib.request, 'urlopen')
    def test_anonymous_success_is_rejected(self, urlopen):
        with self.assertRaises(RuntimeError):
            image.private_package()

    @patch.object(image, 'run')
    def test_source_carrier_rejects_runtime_contract_and_extra_environment(self, run):
        config = {'Labels': {'org.opencontainers.image.source': 'https://github.com/pdarleyjr/mbfd-policy-library',
                             'org.opencontainers.image.revision': self.sha, 'mbfd.policy-library.revision': self.sha,
                             'mbfd.policy-library.source-only': 'true'}, 'Env': [], 'Entrypoint': None, 'Cmd': None}
        carrier = {'Os': 'linux', 'Architecture': 'amd64', 'Config': config}
        run.return_value = json.dumps([carrier])
        reference = image.SOURCE + '@' + self.digest
        image.verify_carrier(reference, self.sha)
        config['Env'] = ['PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']
        config['WorkingDir'] = '/'
        run.return_value = json.dumps([carrier])
        image.verify_carrier(reference, self.sha)
        for key, value in [('Env', ['SECRET=value']), ('Env', [config['Env'][0], config['Env'][0]]),
                           ('Entrypoint', ['/bin/sh']), ('Cmd', ['execute']), ('User', 'root'), ('WorkingDir', '/app'),
                           ('Volumes', {'/data': {}}), ('ExposedPorts', {'80/tcp': {}}), ('Healthcheck', {'Test': ['CMD', 'execute']})]:
            with self.subTest(key=key, value=value), self.assertRaises(RuntimeError):
                changed = copy.deepcopy(carrier)
                changed['Config'][key] = value
                run.return_value = json.dumps([changed])
                image.verify_carrier(reference, self.sha)

    @patch.object(image.subprocess, 'Popen')
    def test_capture_keeps_only_one_bounded_job_record(self, popen):
        process = popen.return_value
        process.stdout.read.side_effect = [b'irrelevant job output\n' + image.MARKER.encode() + json.dumps(self.record).encode() + b'\n', b'']
        process.wait.return_value = 0
        process.poll.return_value = 0
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'record.log'
            image.capture_job_record('pdarleyjr/mbfd-hub', '456', output)
            self.assertNotIn('irrelevant', output.read_text())
            self.assertEqual(image.resolve(self.metadata, output.read_text(), self.sha, self.digest, '123'), self.record)
            self.assertEqual(popen.call_args.args[0], ['gh', 'api', 'repos/pdarleyjr/mbfd-hub/actions/jobs/456/logs'])

    @patch.object(image.subprocess, 'Popen')
    def test_capture_rejects_duplicate_failed_or_oversized_job_stream(self, popen):
        record = image.MARKER.encode() + json.dumps(self.record).encode() + b'\n'
        cases = [([record + record, b''], 0), ([record, b''], 1), ([b'x' * 65536, b'x', b''], 0),
                 ([b'x' * 65536, b'x\n' + record, b''], 0),
                 ([b'x' * 65535 + b'\n'] * 257 + [b''], 0)]
        with tempfile.TemporaryDirectory() as directory:
            for chunks, status in cases:
                with self.subTest(status=status, chunks=len(chunks)), self.assertRaises(RuntimeError):
                    process = popen.return_value
                    process.stdout.read.side_effect = chunks
                    process.wait.return_value = status
                    process.poll.return_value = 0
                    image.capture_job_record('pdarleyjr/mbfd-hub', '456', Path(directory) / 'record.log')


if __name__ == '__main__':
    unittest.main()
