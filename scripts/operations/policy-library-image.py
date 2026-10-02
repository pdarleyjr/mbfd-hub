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
import urllib.error
import urllib.request
from pathlib import Path

MARKER = 'MBFD_CANONICAL_IMAGE_V1='
HUB = 'ghcr.io/pdarleyjr/mbfd-hub'
POLICY = 'ghcr.io/pdarleyjr/mbfd-policy-library'
SOURCE = 'ghcr.io/pdarleyjr/mbfd-policy-library-source'


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


def resolve(run_metadata, log, sha, digest, run_id):
    require(run_metadata['path'] == '.github/workflows/prepare-production-image.yml', 'Wrong preparation workflow')
    require(run_metadata['head_sha'] == sha and run_metadata['head_branch'] == 'main', 'Wrong exact main source')
    require(run_metadata['event'] == 'workflow_dispatch' and run_metadata['conclusion'] == 'success', 'Preparation did not pass')
    require(str(run_metadata['id']) == run_id, 'Wrong preparation run')
    records = [line.split(MARKER, 1)[1] for line in log.splitlines() if MARKER in line]
    require(len(records) == 1 and len(records[0].encode()) <= 2048, 'Expected one bounded canonical record')
    record = json.loads(records[0])
    require(record['schema'] == 1 and record['hub_sha'] == sha and record['run_id'] == run_id, 'Wrong canonical record identity')
    require(re.fullmatch(r'sha256:[0-9a-f]{64}', digest), 'Invalid final digest')
    require(record['image_digest'] == digest and record['gates'] == 'PASS', 'Image differs from tested canonical output')
    enabled = record['policy_library_enabled']
    require(isinstance(enabled, bool), 'Invalid extension selection')
    require(record['image_ref'] == f'{POLICY if enabled else HUB}@{digest}', 'Wrong image repository')
    require(re.fullmatch(re.escape(HUB) + r'@sha256:[0-9a-f]{64}', record['hub_base_image']), 'Invalid canonical base')
    if enabled:
        require(re.fullmatch(r'[0-9a-f]{40}', record['policy_library_revision']), 'Invalid module source')
        require(re.fullmatch(r'sha256:[0-9a-f]{64}', record['policy_library_carrier_digest']), 'Invalid private carrier')
    else:
        require(record['policy_library_revision'] == record['policy_library_carrier_digest'] == '', 'Unexpected base-only module')
        require(record['hub_base_image'] == record['image_ref'], 'Base-only output differs from canonical base')
    return record


def capture_job_record(repository, job_id, destination):
    require(repository == 'pdarleyjr/mbfd-hub', 'Wrong canonical repository')
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
                    if MARKER.encode() in line:
                        record = line.split(MARKER.encode(), 1)[1].strip()
                        require(len(record) <= 2048, 'Canonical record exceeds its bound')
                        records.append(record)
                        require(len(records) == 1, 'Duplicate canonical job record')
            if MARKER.encode() in pending:
                records.append(pending.split(MARKER.encode(), 1)[1].strip())
            require(process.wait() == 0 and len(records) == 1 and len(records[0]) <= 2048, 'Missing successful canonical job record')
            destination.write_bytes(MARKER.encode() + records[0] + b'\n')
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


def verify_image(image, base, sha, revision, carrier, run_id):
    before = json.loads(run('docker', 'image', 'inspect', base))[0]
    after = json.loads(run('docker', 'image', 'inspect', image))[0]
    require(base in before['RepoDigests'], 'Base digest not pulled exactly')
    require(after['Os'] == 'linux' and after['Architecture'] == 'amd64', 'Wrong target platform')
    labels = after['Config']['Labels']
    for key, value in {'org.opencontainers.image.revision': sha,
                       'org.opencontainers.image.source': 'https://github.com/pdarleyjr/mbfd-policy-library',
                       'mbfd.policy-library.revision': revision, 'mbfd.policy-library.base-image': base,
                       'mbfd.policy-library.carrier': carrier, 'mbfd.hub.preparation-run-id': run_id}.items():
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
    parser.add_argument('mode', choices=['private', 'resolve', 'image', 'record', 'carrier', 'capture'])
    parser.add_argument('--run-metadata', type=Path)
    parser.add_argument('--log', type=Path)
    parser.add_argument('--sha')
    parser.add_argument('--digest')
    parser.add_argument('--run-id')
    parser.add_argument('--image')
    parser.add_argument('--base')
    parser.add_argument('--revision')
    parser.add_argument('--carrier')
    parser.add_argument('--package', default='mbfd-policy-library')
    parser.add_argument('--repository')
    parser.add_argument('--job-id')
    args = parser.parse_args()
    if args.mode == 'private':
        private_package(args.package)
    elif args.mode == 'carrier':
        verify_carrier(args.image, args.revision)
    elif args.mode == 'capture':
        capture_job_record(args.repository, args.job_id, args.log)
    elif args.mode == 'record':
        enabled = os.environ['POLICY_LIBRARY_ENABLED'] == 'true'
        record = {'schema': 1, 'hub_sha': os.environ['FINAL_RELEASE_SHA'], 'run_id': os.environ['GITHUB_RUN_ID'],
                  'image_ref': os.environ['FINAL_CANONICAL_IMAGE_REF'], 'image_digest': os.environ['FINAL_CANONICAL_IMAGE_REF'].split('@')[1],
                  'hub_base_image': os.environ['CANONICAL_HUB_BASE_IMAGE'], 'policy_library_enabled': enabled,
                  'policy_library_revision': os.environ['POLICY_LIBRARY_REVISION'] if enabled else '',
                  'policy_library_carrier_digest': os.environ['POLICY_LIBRARY_CARRIER_DIGEST'] if enabled else '', 'gates': 'PASS'}
        print(MARKER + json.dumps(record, sort_keys=True, separators=(',', ':')))
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            output.write(f"image_ref={record['image_ref']}\nimage_digest={record['image_digest']}\n")
    elif args.mode == 'image':
        verify_image(args.image, args.base, args.sha, args.revision, args.carrier, args.run_id)
    else:
        record = resolve(json.loads(args.run_metadata.read_text()), args.log.read_text(), args.sha, args.digest, args.run_id)
        if record['policy_library_enabled']:
            private_package()
        values = {'image_repository': POLICY if record['policy_library_enabled'] else HUB,
                  'policy_library_enabled': str(record['policy_library_enabled']).lower(),
                  'policy_library_revision': record['policy_library_revision'],
                  'policy_library_carrier_digest': record['policy_library_carrier_digest'],
                  'policy_library_base_image': record['hub_base_image']}
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as output:
            for key, value in values.items():
                output.write(f'{key}={value}\n')


if __name__ == '__main__':
    main()
