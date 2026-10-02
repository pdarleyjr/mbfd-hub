#!/usr/bin/env python3
"""Bounded canonical image provenance and isolated extension checks."""

import argparse
import base64
import json
import os
import re
import secrets
import shlex
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

MARKER = 'MBFD_CANONICAL_IMAGE_V2='
BASE_MARKER = 'MBFD_CANONICAL_BASE_V1='
EXTENSION_MARKER = 'MBFD_CANONICAL_EXTENSION_RESULT_V1='
HUB = 'ghcr.io/pdarleyjr/mbfd-hub'
POLICY = 'ghcr.io/pdarleyjr/mbfd-policy-library'
SOURCE = 'ghcr.io/pdarleyjr/mbfd-policy-library-source'
PRIVATE_REPOSITORY = 'pdarleyjr/mbfd-policy-library'
EXTENSION_KEYS = {'schema', 'parent_run_id', 'parent_run_attempt', 'hub_sha', 'hub_base_image', 'module_revision',
                  'carrier_digest', 'private_workflow_sha', 'run_id', 'run_attempt', 'image_ref', 'image_digest',
                  'image_id', 'gates', 'package_visibility', 'repository_link'}
CANONICAL_KEYS = {'schema', 'hub_sha', 'run_id', 'run_attempt', 'image_ref', 'image_digest', 'hub_base_image',
                  'policy_library_enabled', 'policy_library_revision', 'policy_library_carrier_digest',
                  'private_workflow_sha', 'extension_run_id', 'extension_run_attempt', 'image_id', 'repository_link', 'gates'}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True)
    require(result.returncode == 0, f'{args[0]} failed with exit {result.returncode}')
    return result.stdout.strip()


def private_package(package='mbfd-policy-library'):
    # An authorized immutable pull must also pass. Anonymous denial proves this
    # personal package cannot be pulled publicly; owner API/link checks remain
    # a separate pre-dispatch requirement. Never treat network failure as denial.
    require(package in ('mbfd-policy-library', 'mbfd-policy-library-source'), 'Unexpected private package')
    url = f'https://ghcr.io/token?service=ghcr.io&scope=repository:pdarleyjr/{package}:pull'
    try:
        with urllib.request.urlopen(url, timeout=15):
            raise RuntimeError('Policy package permits anonymous registry access')
    except urllib.error.HTTPError as error:
        require(error.code == 403, 'Expected explicit anonymous registry denial')


def validate_extension(record, expected, metadata):
    require(set(record) == EXTENSION_KEYS and record['schema'] == 1, 'Unexpected private child record schema')
    require(all(isinstance(value, str) for key, value in record.items() if key != 'schema'), 'Invalid private child record types')
    require(metadata['path'] == '.github/workflows/canonical-extension.yml' and metadata['head_branch'] == 'main'
            and metadata['head_sha'] == expected['private_workflow_sha'] and metadata['event'] == 'workflow_dispatch'
            and metadata['status'] == 'completed' and metadata['conclusion'] == 'success', 'Private child workflow did not pass')
    require(str(metadata['id']) == record['run_id'] and str(metadata['run_attempt']) == record['run_attempt'] == '1', 'Wrong private child run attempt')
    for key, value in expected.items():
        require(record[key] == value, f'Private child binding differs: {key}')
    require(record['gates'] == record['package_visibility'] == 'PASS', 'Private child gates did not pass')
    require(record['repository_link'] in ('PASS', 'pending_owner_link_verification'), 'Private package linkage failed')
    require(re.fullmatch(r'sha256:[0-9a-f]{64}', record['image_digest']) and re.fullmatch(r'sha256:[0-9a-f]{64}', record['image_id']), 'Invalid private image identity')
    require(record['image_ref'] == POLICY + '@' + record['image_digest'], 'Wrong private image repository')
    return record


class NoAuthenticatedRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        raise RuntimeError('Authenticated GitHub API redirects are forbidden')


def github_private_api(path, payload=None):
    # This secret permits only private-repository Actions read/write. Never use
    # it for registry access or emit response bodies, headers or raw job logs.
    require(path.startswith(f'repos/{PRIVATE_REPOSITORY}/actions/'), 'Unexpected private API path')
    headers = {'Authorization': 'Bearer ' + os.environ['GH_TOKEN'], 'Accept': 'application/vnd.github+json',
               'X-GitHub-Api-Version': '2026-03-10'}
    request = urllib.request.Request('https://api.github.com/' + path, headers=headers,
                                     data=json.dumps(payload).encode() if payload is not None else None)
    with urllib.request.build_opener(NoAuthenticatedRedirect()).open(request, timeout=30) as response:
        bounded = response.read(1024 * 1024 + 1)
        require(len(bounded) <= 1024 * 1024, 'Private Actions API response exceeds its 1MiB bound')
        return json.loads(bounded)


def bridge():
    expected = {key: os.environ[variable] for key, variable in {
        'parent_run_id': 'GITHUB_RUN_ID', 'parent_run_attempt': 'GITHUB_RUN_ATTEMPT', 'hub_sha': 'FINAL_RELEASE_SHA',
        'hub_base_image': 'CANONICAL_HUB_BASE_IMAGE', 'module_revision': 'POLICY_LIBRARY_REVISION',
        'carrier_digest': 'POLICY_LIBRARY_CARRIER_DIGEST', 'private_workflow_sha': 'POLICY_LIBRARY_WORKFLOW_SHA'}.items()}
    for key in ('hub_sha', 'module_revision', 'private_workflow_sha'):
        require(re.fullmatch(r'[0-9a-f]{40}', expected[key]), f'Invalid bridge source: {key}')
    for key in ('parent_run_id', 'parent_run_attempt'):
        require(re.fullmatch(r'[1-9][0-9]*', expected[key]), f'Invalid bridge run: {key}')
    require(re.fullmatch(re.escape(HUB) + r'@sha256:[0-9a-f]{64}', expected['hub_base_image']), 'Invalid bridge base')
    require(re.fullmatch(r'sha256:[0-9a-f]{64}', expected['carrier_digest']), 'Invalid bridge carrier')
    inputs = {'hub_source_sha': expected['hub_sha'], 'hub_base_digest': expected['hub_base_image'].split('@')[1],
              **{key: expected[key] for key in ('module_revision', 'carrier_digest', 'parent_run_id', 'parent_run_attempt', 'private_workflow_sha')}}
    dispatched = github_private_api(f'repos/{PRIVATE_REPOSITORY}/actions/workflows/canonical-extension.yml/dispatches',
                                    {'ref': 'main', 'inputs': inputs})
    child_id = str(dispatched['workflow_run_id'])
    require(re.fullmatch(r'[1-9][0-9]*', child_id), 'Dispatch did not return an exact child run ID')
    expected['run_id'] = child_id
    print(f'Private extension dispatched: run_id={child_id}', flush=True)
    deadline = time.monotonic() + 45 * 60
    while True:
        metadata = github_private_api(f'repos/{PRIVATE_REPOSITORY}/actions/runs/{child_id}')
        require(metadata['head_sha'] == expected['private_workflow_sha'] and metadata['head_branch'] == 'main'
                and metadata['path'] == '.github/workflows/canonical-extension.yml'
                and metadata['event'] == 'workflow_dispatch' and metadata['run_attempt'] == 1, 'Unexpected dispatched child source or attempt')
        if metadata['status'] == 'completed':
            require(metadata['conclusion'] == 'success', 'Private extension workflow failed')
            break
        require(time.monotonic() < deadline, 'Private extension wait expired')
        time.sleep(15)
    jobs = github_private_api(f'repos/{PRIVATE_REPOSITORY}/actions/runs/{child_id}/attempts/1/jobs?per_page=100')['jobs']
    selected = [job for job in jobs if job['name'] == 'build-test-scan-publish' and job['conclusion'] == 'success']
    require(len(selected) == 1 and str(selected[0]['run_id']) == child_id, 'Expected one exact successful private child job')
    with tempfile.TemporaryDirectory() as directory:
        log = Path(directory) / 'result.log'
        capture_job_record(PRIVATE_REPOSITORY, str(selected[0]['id']), log, EXTENSION_MARKER)
        record = json.loads(log.read_text(encoding='utf-8').split(EXTENSION_MARKER, 1)[1])
        validate_extension(record, expected, metadata)
    with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
        output.write('extension_result=' + json.dumps(record, sort_keys=True, separators=(',', ':')) + '\n')
    print(f'Private extension verified: run_id={child_id} image_digest={record["image_digest"]} gates=PASS repository_link={record["repository_link"]}')


def resolve(run_metadata, log, sha, digest, run_id):
    require(run_metadata['path'] == '.github/workflows/prepare-production-image.yml', 'Wrong preparation workflow')
    require(run_metadata['head_sha'] == sha and run_metadata['head_branch'] == 'main', 'Wrong exact main source')
    require(run_metadata['event'] == 'workflow_dispatch' and run_metadata['conclusion'] == 'success', 'Preparation did not pass')
    require(str(run_metadata['id']) == run_id, 'Wrong preparation run')
    records = [line.split(MARKER, 1)[1] for line in log.splitlines() if MARKER in line]
    require(len(records) == 1 and len(records[0].encode()) <= 2048, 'Expected one bounded canonical record')
    record = json.loads(records[0])
    require(set(record) == CANONICAL_KEYS, 'Unexpected canonical record schema')
    require(all(isinstance(value, str) for key, value in record.items() if key not in ('schema', 'policy_library_enabled')), 'Invalid canonical record types')
    require(record['schema'] == 2 and record['hub_sha'] == sha and record['run_id'] == run_id
            and record['run_attempt'] == str(run_metadata['run_attempt']), 'Wrong canonical record identity')
    require(re.fullmatch(r'sha256:[0-9a-f]{64}', digest), 'Invalid final digest')
    require(record['image_digest'] == digest and record['gates'] == 'PASS', 'Image differs from tested canonical output')
    enabled = record['policy_library_enabled']
    require(isinstance(enabled, bool), 'Invalid extension selection')
    require(record['image_ref'] == f'{POLICY if enabled else HUB}@{digest}', 'Wrong image repository')
    require(re.fullmatch(re.escape(HUB) + r'@sha256:[0-9a-f]{64}', record['hub_base_image']), 'Invalid canonical base')
    if enabled:
        require(re.fullmatch(r'[0-9a-f]{40}', record['policy_library_revision']), 'Invalid module source')
        require(re.fullmatch(r'sha256:[0-9a-f]{64}', record['policy_library_carrier_digest']), 'Invalid private carrier')
        require(re.fullmatch(r'[0-9a-f]{40}', record['private_workflow_sha']), 'Invalid private workflow source')
        require(re.fullmatch(r'sha256:[0-9a-f]{64}', record['image_id']), 'Invalid private image config identity')
        require(re.fullmatch(r'[1-9][0-9]*', record['extension_run_id']) and record['extension_run_attempt'] == '1', 'Invalid private child run')
        require(record['repository_link'] in ('PASS', 'pending_owner_link_verification'), 'Missing private package link gate')
    else:
        require(record['policy_library_revision'] == record['policy_library_carrier_digest'] == '', 'Unexpected base-only module')
        require(record['private_workflow_sha'] == record['image_id'] == record['extension_run_id']
                == record['extension_run_attempt'] == record['repository_link'] == '', 'Unexpected base-only child')
        require(record['hub_base_image'] == record['image_ref'], 'Base-only output differs from canonical base')
    return record


def capture_job_record(repository, job_id, destination, marker=MARKER):
    require((repository, marker) in (('pdarleyjr/mbfd-hub', MARKER), ('pdarleyjr/mbfd-hub', BASE_MARKER),
                                     (PRIVATE_REPOSITORY, EXTENSION_MARKER)), 'Wrong canonical repository or record kind')
    require(re.fullmatch(r'[1-9][0-9]*', job_id), 'Invalid canonical job ID')
    records, total, pending = [], 0, b''
    with tempfile.TemporaryFile() as errors:
        process = subprocess.Popen(['gh', 'api', f'repos/{repository}/actions/jobs/{job_id}/logs'], stdout=subprocess.PIPE, stderr=errors)
        try:
            for chunk in iter(lambda: process.stdout.read(65536), b''):
                total += len(chunk)
                require(total <= 16 * 1024 * 1024, 'Canonical job log exceeds its 16MiB bound')
                pending += chunk
                lines = pending.split(b'\n')
                pending = lines.pop()
                require(len(pending) <= 65536, 'Canonical job log line exceeds its bound')
                for line in lines:
                    require(len(line) <= 65536, 'Canonical job log line exceeds its bound')
                    if marker.encode() in line:
                        record = line.split(marker.encode(), 1)[1].strip()
                        require(len(record) <= 2048, 'Canonical record exceeds its bound')
                        records.append(record)
                        require(len(records) == 1, 'Duplicate canonical job record')
            if marker.encode() in pending:
                records.append(pending.split(marker.encode(), 1)[1].strip())
            require(process.wait() == 0 and len(records) == 1 and len(records[0]) <= 2048, 'Missing successful canonical job record')
            destination.write_bytes(marker.encode() + records[0] + b'\n')
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
            process.stdout.close()


def container(image, entrypoint, *args):
    return run('docker', 'run', '--rm', '--network', 'none', '--pull', 'never', '--entrypoint', entrypoint, image, *args)


def verify_carrier(image, revision):
    require(re.fullmatch(re.escape(SOURCE) + r'@sha256:[0-9a-f]{64}', image), 'Wrong immutable source carrier')
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'Invalid carrier revision')
    carrier = json.loads(run('docker', 'image', 'inspect', image))[0]
    config = carrier['Config']
    require(carrier['Os'] == 'linux' and carrier['Architecture'] == 'amd64', 'Wrong carrier platform')
    for key, value in {'org.opencontainers.image.source': 'https://github.com/pdarleyjr/mbfd-policy-library',
                       'org.opencontainers.image.revision': revision, 'mbfd.policy-library.revision': revision,
                       'mbfd.policy-library.source-only': 'true'}.items():
        require(config['Labels'].get(key) == value, f'Wrong source-only carrier label: {key}')
    require(not config.get('Entrypoint') and not config.get('Cmd') and not config.get('User'), 'Carrier contains an execution contract')
    require(config.get('WorkingDir') in (None, '', '/'), 'Unexpected source carrier working directory')
    require(not config.get('Volumes') and not config.get('ExposedPorts') and not config.get('Healthcheck'), 'Carrier declares runtime behavior')
    require(config.get('Env') in (None, [], ['PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']), 'Unexpected source carrier environment')


def verify_image(image, base, sha, revision, carrier, run_id, run_attempt, workflow_sha, extension_run_id, extension_run_attempt):
    before = json.loads(run('docker', 'image', 'inspect', base))[0]
    after = json.loads(run('docker', 'image', 'inspect', image))[0]
    require(base in before['RepoDigests'], 'Base digest not pulled exactly')
    require(after['Os'] == 'linux' and after['Architecture'] == 'amd64', 'Wrong target platform')
    labels = after['Config']['Labels']
    for key, value in {'org.opencontainers.image.revision': sha,
                       'org.opencontainers.image.source': 'https://github.com/pdarleyjr/mbfd-policy-library',
                       'mbfd.policy-library.revision': revision, 'mbfd.policy-library.base-image': base,
                       'mbfd.policy-library.carrier': carrier, 'mbfd.hub.preparation-run-id': run_id,
                       'mbfd.hub.preparation-run-attempt': run_attempt, 'mbfd.policy-library.workflow-sha': workflow_sha,
                       'mbfd.policy-library.extension-run-id': extension_run_id,
                       'mbfd.policy-library.extension-run-attempt': extension_run_attempt}.items():
        require(labels.get(key) == value, f'Wrong image provenance: {key}')
    for key in ('User', 'Entrypoint', 'Cmd', 'WorkingDir'):
        require(before['Config'].get(key) == after['Config'].get(key), f'Hub runtime changed: {key}')
    require(after['Config']['User'] == 'sail', 'Non-root Hub user changed')
    lock = container(base, 'php', '-r', "echo hash_file('sha256', '/var/www/html/composer.lock');")
    for candidate in (base, image):
        require(container(candidate, 'cat', '/var/www/html/.git-sha') == sha, 'Hub source changed')
        require(container(candidate, 'php', '-r', "echo hash_file('sha256', '/var/www/html/composer.lock');") == lock, 'Hub dependency lock changed')
    version = json.loads(container(image, 'cat', '/opt/mbfd-policy-library/version.json'))
    require(version['revision'] == revision and version['base_image'] == base and version['hub_composer_lock_sha256'] == lock, 'Installed module provenance differs')
    tree_check = "import hashlib,json,pathlib; root=pathlib.Path('/opt/mbfd-policy-library'); meta=json.loads((root/'source.json').read_text()); files={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in root.rglob('*') if p.is_file() and p.relative_to(root).as_posix() not in ('source.json','version.json')}; digest=hashlib.sha256(json.dumps(files,sort_keys=True,separators=(',',':')).encode()).hexdigest(); assert meta['format']==1 and meta['repository']=='pdarleyjr/mbfd-policy-library' and meta['revision']=='" + revision + "' and meta['source_only'] is True and meta['file_count']==len(files) and meta['tree_sha256']==digest; print('PASS')"
    require(container(image, '/usr/bin/python3', '-c', tree_check) == 'PASS', 'Installed private module differs from pinned carrier tree')
    assets = "foreach (['public/build','public/daily'] as $dir) { $files=[]; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) if($file->isFile()) $files[substr($file->getPathname(),14)]=hash_file('sha256',$file->getPathname()); ksort($files); echo json_encode($files); }"
    require(container(base, 'php', '-r', assets) == container(image, 'php', '-r', assets), 'Canonical Hub frontend assets changed')
    worker = container(image, 'cat', '/etc/supervisor/conf.d/supervisord.conf')
    fragment = container(image, 'cat', '/opt/mbfd-policy-library/config/supervisord-policy-library.conf').strip()
    require(worker.count('[program:policy-library-worker]') == 1 and fragment in worker, 'Dedicated active worker missing or duplicated')
    require('--queue=policy-library' in fragment and '--timeout=1800' in fragment, 'Wrong worker timing/queue')
    versions = json.loads(container(image, '/opt/policy-library-python/bin/python', '-c', "import json,pymupdf,PIL; print(json.dumps([pymupdf.VersionBind,PIL.__version__]))"))
    require(versions == ['1.28.2', '12.3.0'], 'Wrong fresh Python dependencies')
    for tool, flag in [('qpdf', '--version'), ('pdfinfo', '-v'), ('pdftotext', '-v')]:
        container(image, tool, flag)
    environment = {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/tmp',
                   'APP_ENV': 'testing', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
                   'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
                   'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array', 'BROADCAST_CONNECTION': 'log', 'PULSE_ENABLED': 'false',
                   'POLICY_LIBRARY_DOMAIN': 'files.mbfdhub.com'}
    php = "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); foreach(['viewer','login','login.store','access','access.store','manuals','tree','document','asset','preview','viewer-errors'] as $name) { $r=$app['router']->getRoutes()->getByName('policy-library.'.$name); if(!$r || $r->getDomain()!=='files.mbfdhub.com') throw new RuntimeException('Missing library route'); } echo 'PASS';"
    command = 'test ! -e .env && php artisan config:cache >/dev/null && php artisan route:cache >/dev/null && php -r ' + shlex.quote(php)
    require(container(image, '/usr/bin/env', '-i', *(f'{key}={value}' for key, value in environment.items()), '/bin/sh', '-ec', command) == 'PASS', 'Isolated extension bootstrap failed')
    print('Canonical extension runtime, lock, assets, tools, worker and isolated routes: PASS')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['private', 'resolve', 'image', 'record', 'base-record', 'bridge', 'carrier', 'capture'])
    parser.add_argument('--run-metadata', type=Path)
    parser.add_argument('--log', type=Path)
    parser.add_argument('--sha')
    parser.add_argument('--digest')
    parser.add_argument('--run-id')
    parser.add_argument('--run-attempt')
    parser.add_argument('--workflow-sha')
    parser.add_argument('--extension-run-id')
    parser.add_argument('--extension-run-attempt')
    parser.add_argument('--image')
    parser.add_argument('--base')
    parser.add_argument('--revision')
    parser.add_argument('--carrier')
    parser.add_argument('--package', default='mbfd-policy-library')
    parser.add_argument('--repository')
    parser.add_argument('--job-id')
    parser.add_argument('--record-kind', choices=['canonical', 'base', 'extension'], default='canonical')
    args = parser.parse_args()
    if args.mode == 'private':
        private_package(args.package)
    elif args.mode == 'carrier':
        verify_carrier(args.image, args.revision)
    elif args.mode == 'capture':
        capture_job_record(args.repository, args.job_id, args.log,
                           {'canonical': MARKER, 'base': BASE_MARKER, 'extension': EXTENSION_MARKER}[args.record_kind])
    elif args.mode == 'bridge':
        bridge()
    elif args.mode == 'base-record':
        image_ref = os.environ['CANONICAL_HUB_BASE_IMAGE']
        require(re.fullmatch(re.escape(HUB) + r'@sha256:[0-9a-f]{64}', image_ref), 'Invalid canonical public base')
        record = {'schema': 1, 'hub_sha': os.environ['FINAL_RELEASE_SHA'], 'run_id': os.environ['GITHUB_RUN_ID'],
                  'run_attempt': os.environ['GITHUB_RUN_ATTEMPT'], 'image_ref': image_ref,
                  'image_digest': image_ref.split('@')[1], 'gates': 'PASS'}
        proof = json.dumps(record, sort_keys=True, separators=(',', ':'))
        require(len(proof.encode()) <= 2048, 'Canonical base proof exceeds its bound')
        print(BASE_MARKER + proof)
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            output.write('proof=' + proof + '\n')
    elif args.mode == 'record':
        extension = os.environ.get('EXTENSION_RESULT', '')
        enabled = bool(extension)
        require(enabled == bool(os.environ['POLICY_LIBRARY_REVISION']), 'Selected private child output is missing or unexpected')
        child = json.loads(extension) if enabled else {}
        require(not enabled or set(child) == EXTENSION_KEYS, 'Unexpected child output schema')
        image_ref = child['image_ref'] if enabled else os.environ['CANONICAL_HUB_BASE_IMAGE']
        record = {'schema': 2, 'hub_sha': os.environ['FINAL_RELEASE_SHA'], 'run_id': os.environ['GITHUB_RUN_ID'],
                  'run_attempt': os.environ['GITHUB_RUN_ATTEMPT'], 'image_ref': image_ref, 'image_digest': image_ref.split('@')[1],
                  'hub_base_image': os.environ['CANONICAL_HUB_BASE_IMAGE'], 'policy_library_enabled': enabled,
                  'policy_library_revision': child['module_revision'] if enabled else '',
                  'policy_library_carrier_digest': child['carrier_digest'] if enabled else '',
                  'private_workflow_sha': child['private_workflow_sha'] if enabled else '',
                  'extension_run_id': child['run_id'] if enabled else '', 'extension_run_attempt': child['run_attempt'] if enabled else '',
                  'image_id': child['image_id'] if enabled else '', 'repository_link': child['repository_link'] if enabled else '', 'gates': 'PASS'}
        if enabled:
            require(child['module_revision'] == os.environ['POLICY_LIBRARY_REVISION'], 'Final private module selection differs')
            for key, value in {'parent_run_id': record['run_id'], 'parent_run_attempt': record['run_attempt'],
                               'hub_sha': record['hub_sha'], 'hub_base_image': record['hub_base_image']}.items():
                require(child[key] == value, 'Final private child binding differs')
            require(child['gates'] == child['package_visibility'] == 'PASS', 'Final private child gates failed')
        print(MARKER + json.dumps(record, sort_keys=True, separators=(',', ':')))
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            output.write(f"image_ref={record['image_ref']}\nimage_digest={record['image_digest']}\n")
    elif args.mode == 'image':
        verify_image(args.image, args.base, args.sha, args.revision, args.carrier, args.run_id, args.run_attempt,
                     args.workflow_sha, args.extension_run_id, args.extension_run_attempt)
    else:
        record = resolve(json.loads(args.run_metadata.read_text(encoding='utf-8')), args.log.read_text(encoding='utf-8'), args.sha, args.digest, args.run_id)
        if record['policy_library_enabled']:
            private_package()
        values = {'image_repository': POLICY if record['policy_library_enabled'] else HUB,
                  'policy_library_enabled': str(record['policy_library_enabled']).lower(),
                  'policy_library_revision': record['policy_library_revision'],
                  'policy_library_carrier_digest': record['policy_library_carrier_digest'],
                  'policy_library_base_image': record['hub_base_image'], 'policy_library_image_id': record['image_id'],
                  'policy_library_workflow_sha': record['private_workflow_sha'],
                  'policy_library_extension_run_id': record['extension_run_id'],
                  'policy_library_extension_run_attempt': record['extension_run_attempt'],
                  'preparation_run_attempt': record['run_attempt']}
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            for key, value in values.items():
                output.write(f'{key}={value}\n')


if __name__ == '__main__':
    main()
