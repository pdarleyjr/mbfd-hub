import copy
import importlib.util
import json
import os
import sys
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
                         'head_sha': self.sha, 'head_branch': 'main', 'event': 'workflow_dispatch', 'conclusion': 'success', 'run_attempt': 1}
        self.record = {'schema': 2, 'hub_sha': self.sha, 'run_id': '123', 'run_attempt': '1', 'image_digest': self.digest,
                       'image_ref': image.POLICY + '@' + self.digest, 'hub_base_image': image.HUB + '@sha256:' + 'c' * 64,
                       'policy_library_enabled': True, 'policy_library_revision': 'd' * 40,
                       'policy_library_carrier_digest': 'sha256:' + 'e' * 64, 'gates': 'PASS', 'private_workflow_sha': 'f' * 40,
                       'image_id': 'sha256:' + '1' * 64, 'extension_run_id': '456', 'extension_run_attempt': '1',
                       'repository_link': 'pending_owner_link_verification'}
        self.child = {'schema': 1, 'parent_run_id': '123', 'parent_run_attempt': '1', 'hub_sha': self.sha,
                      'hub_base_image': self.record['hub_base_image'], 'module_revision': 'd' * 40,
                      'carrier_digest': 'sha256:' + 'e' * 64, 'private_workflow_sha': 'f' * 40,
                      'run_id': '456', 'run_attempt': '1', 'image_ref': self.record['image_ref'], 'image_digest': self.digest,
                      'image_id': self.record['image_id'], 'gates': 'PASS', 'package_visibility': 'PASS',
                      'repository_link': 'pending_owner_link_verification'}
        self.child_metadata = {'id': 456, 'run_attempt': 1, 'path': '.github/workflows/canonical-extension.yml',
                               'head_sha': 'f' * 40, 'head_branch': 'main', 'event': 'workflow_dispatch',
                               'status': 'completed', 'conclusion': 'success'}
        self.expected = {key: self.child[key] for key in ('parent_run_id', 'parent_run_attempt', 'hub_sha',
                         'hub_base_image', 'module_revision', 'carrier_digest', 'private_workflow_sha', 'run_id')}

    def resolve(self, record=None, metadata=None, duplicate=False):
        line = 'build\tcanonical-output\t2026-10-01T00:00:00Z ' + image.MARKER + json.dumps(record or self.record)
        return image.resolve(metadata or self.metadata, line + ('\n' + line if duplicate else ''), self.sha, self.digest, '123')

    def test_exact_canonical_extension(self):
        self.assertEqual(self.resolve(), self.record)

    def test_exact_base_only_canonical_output(self):
        record = copy.deepcopy(self.record)
        record.update(policy_library_enabled=False, policy_library_revision='', policy_library_carrier_digest='',
                      image_ref=image.HUB + '@' + self.digest, hub_base_image=image.HUB + '@' + self.digest,
                      image_id='', private_workflow_sha='', extension_run_id='', extension_run_attempt='', repository_link='')
        self.assertEqual(self.resolve(record), record)

    def test_rejects_noncanonical_failed_wrong_source_or_wrong_run(self):
        for key, value in [('path', '.github/workflows/other.yml'), ('head_sha', 'f' * 40), ('head_branch', 'feature'),
                           ('event', 'push'), ('conclusion', 'failure'), ('id', 124), ('run_attempt', 2)]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.resolve(metadata={**self.metadata, key: value})

    def test_rejects_unverified_or_mismatched_image_provenance(self):
        for key, value in [('schema', 1), ('hub_sha', 'f' * 40), ('run_id', '124'), ('gates', 'FAIL'), ('run_attempt', '2'),
                           ('image_digest', 'sha256:' + 'f' * 64), ('image_ref', image.HUB + '@' + self.digest),
                           ('policy_library_enabled', 'true'), ('policy_library_revision', 'main'),
                           ('policy_library_carrier_digest', 'latest'), ('hub_base_image', 'mutable:latest'),
                           ('private_workflow_sha', 'main'), ('image_id', 'mutable'), ('extension_run_attempt', '2'),
                           ('repository_link', 'PUBLIC')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                self.resolve({**self.record, key: value})

    def test_private_child_requires_exact_parent_attempt_source_and_private_output(self):
        self.assertEqual(image.validate_extension(self.child, self.expected, self.child_metadata), self.child)
        # Null scoped link proof stays explicitly pending; actual owner evidence
        # is an external release condition, never fabricated as PASS here.
        self.assertEqual(image.validate_extension({**self.child, 'repository_link': 'PASS'}, self.expected, self.child_metadata)['repository_link'], 'PASS')
        for key, value in [('parent_run_id', '124'), ('parent_run_attempt', '2'), ('hub_sha', 'b' * 40),
                           ('module_revision', 'b' * 40), ('carrier_digest', 'sha256:' + 'b' * 64),
                           ('private_workflow_sha', 'b' * 40), ('run_id', '457'), ('run_attempt', '2'),
                           ('image_ref', image.HUB + '@' + self.digest), ('gates', 'FAIL'),
                           ('package_visibility', 'PUBLIC'), ('repository_link', 'FAIL'), ('private_source', 'private code')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                image.validate_extension({**self.child, key: value}, self.expected, self.child_metadata)
        for key, value in [('id', 457), ('run_attempt', 2), ('path', '.github/workflows/other.yml'),
                           ('head_sha', 'b' * 40), ('head_branch', 'feature'), ('event', 'push'),
                           ('status', 'in_progress'), ('conclusion', 'failure')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                image.validate_extension(self.child, self.expected, {**self.child_metadata, key: value})

    @patch.object(image.urllib.request, 'build_opener')
    def test_private_api_is_bounded_and_forbids_authenticated_redirects(self, build_opener):
        response = build_opener.return_value.open.return_value.__enter__.return_value
        response.read.return_value = b'{"workflow_run_id":456}'
        with patch.dict(os.environ, {'GH_TOKEN': 'test-token'}):
            self.assertEqual(image.github_private_api('repos/' + image.PRIVATE_REPOSITORY + '/actions/workflows/canonical-extension.yml/dispatches', {'ref': 'main'})['workflow_run_id'], 456)
            request = build_opener.return_value.open.call_args.args[0]
            self.assertEqual(request.full_url, 'https://api.github.com/repos/' + image.PRIVATE_REPOSITORY + '/actions/workflows/canonical-extension.yml/dispatches')
            self.assertEqual(request.get_header('X-github-api-version'), '2026-03-10')
            self.assertIsInstance(build_opener.call_args.args[0], image.NoAuthenticatedRedirect)
            response.read.assert_called_once_with(1024 * 1024 + 1)
            response.read.return_value = b'x' * (1024 * 1024 + 1)
            with self.assertRaises(RuntimeError):
                image.github_private_api('repos/' + image.PRIVATE_REPOSITORY + '/actions/runs/456')
            with self.assertRaises(RuntimeError):
                image.github_private_api('repos/pdarleyjr/mbfd-hub/actions/runs/123')
        with self.assertRaises(RuntimeError):
            image.NoAuthenticatedRedirect().redirect_request(None, None, 302, 'redirect', {}, 'https://other.example/')

    def test_base_proof_output_is_compact_bounded_and_exact(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'output'
            environment = {'FINAL_RELEASE_SHA': self.sha, 'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1',
                           'CANONICAL_HUB_BASE_IMAGE': self.record['hub_base_image'], 'GITHUB_OUTPUT': str(output)}
            with patch.dict(os.environ, environment), patch.object(sys, 'argv', ['policy-library-image.py', 'base-record']):
                image.main()
            proof = output.read_text(encoding='utf-8').strip().split('=', 1)[1]
            self.assertLessEqual(len(proof.encode()), 2048)
            self.assertEqual(json.loads(proof), {'schema': 1, 'hub_sha': self.sha, 'run_id': '123', 'run_attempt': '1',
                             'image_ref': self.record['hub_base_image'], 'image_digest': 'sha256:' + 'c' * 64, 'gates': 'PASS'})

    def test_final_record_refuses_missing_selected_child_or_unrequested_child(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'output'
            environment = {'FINAL_RELEASE_SHA': self.sha, 'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1',
                           'CANONICAL_HUB_BASE_IMAGE': self.record['hub_base_image'], 'GITHUB_OUTPUT': str(output)}
            for revision, result in [('d' * 40, ''), ('', json.dumps(self.child)), ('b' * 40, json.dumps(self.child))]:
                with self.subTest(revision=revision, child=bool(result)), patch.dict(os.environ, {
                    **environment, 'POLICY_LIBRARY_REVISION': revision, 'EXTENSION_RESULT': result,
                }), patch.object(sys, 'argv', ['policy-library-image.py', 'record']), self.assertRaises(RuntimeError):
                    image.main()
            self.assertFalse(output.exists())

    @patch.object(image.time, 'sleep')
    @patch.object(image, 'capture_job_record')
    @patch.object(image, 'github_private_api')
    def test_bridge_uses_exact_dispatch_id_without_search_and_validates_child(self, api, capture, sleep):
        environment = {'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1', 'FINAL_RELEASE_SHA': self.sha,
                       'CANONICAL_HUB_BASE_IMAGE': self.record['hub_base_image'], 'POLICY_LIBRARY_REVISION': 'd' * 40,
                       'POLICY_LIBRARY_CARRIER_DIGEST': 'sha256:' + 'e' * 64, 'POLICY_LIBRARY_WORKFLOW_SHA': 'f' * 40}
        def captured(repository, job, destination, marker):
            self.assertEqual((repository, job, marker), (image.PRIVATE_REPOSITORY, '789', image.EXTENSION_MARKER))
            destination.write_text(marker + json.dumps(self.child), encoding='utf-8')
        capture.side_effect = captured
        completed = self.child_metadata
        jobs = {'jobs': [{'id': 789, 'run_id': 456, 'name': 'build-test-scan-publish', 'conclusion': 'success'}]}
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'output'
            with patch.dict(os.environ, {**environment, 'GITHUB_OUTPUT': str(output)}):
                api.side_effect = [{'workflow_run_id': 456}, {**completed, 'status': 'in_progress', 'conclusion': None}, completed, jobs]
                image.bridge()
                self.assertEqual(json.loads(output.read_text().split('=', 1)[1]), self.child)
                inputs = api.call_args_list[0].args[1]
                self.assertEqual(inputs['ref'], 'main')
                self.assertEqual(inputs['inputs']['private_workflow_sha'], 'f' * 40)
                self.assertEqual(inputs['inputs']['module_revision'], 'd' * 40)
                self.assertEqual(api.call_args_list[-1].args[0], 'repos/' + image.PRIVATE_REPOSITORY + '/actions/runs/456/attempts/1/jobs?per_page=100')
                for change in ({'head_sha': 'b' * 40}, {'run_attempt': 2}, {'conclusion': 'failure'}):
                    with self.subTest(change=change), self.assertRaises(RuntimeError):
                        api.side_effect = [{'workflow_run_id': 456}, {**completed, **change}]
                        image.bridge()

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
