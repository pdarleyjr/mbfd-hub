<?php

declare(strict_types=1);

namespace Mbfd\CloudIdentity;

use Closure;
use RuntimeException;
use Throwable;

// Deployed under Nextcloud config as a CLI-only, root-owned helper, not a web API.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class NextcloudTransition
{
    public function __construct(private readonly array $approvedUids, private readonly string $directory, private readonly Closure $occ) {}

    public function apply(mixed $request): array
    {
        $keys = is_array($request) ? array_keys($request) : [];
        sort($keys);
        if ($keys !== ['enabled', 'revision', 'uid'] || ! is_string($request['uid'])
            || preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/', $request['uid']) !== 1
            || ! in_array($request['uid'], $this->approvedUids, true)
            || ! is_int($request['revision']) || $request['revision'] < 1 || $request['revision'] > 9007199254740991
            || ! is_bool($request['enabled'])) {
            throw new RuntimeException('Unapproved identity or invalid transition');
        }
        $uid = $request['uid'];
        $lock = fopen($this->directory.'/'.$uid.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Identity lock unavailable');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Identity transition is already running');
            }
            $path = $this->directory.'/'.$uid.'.json';
            if (is_file($path)) {
                $raw = file_get_contents($path, false, null, 0, 2049);
                if ($raw === false || strlen($raw) > 2048) {
                    throw new RuntimeException('Invalid saved transition');
                }
                $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                $stateKeys = is_array($state) ? array_keys($state) : [];
                sort($stateKeys);
                if ($stateKeys !== ['applied', 'enabled', 'revision', 'uid'] || $state['uid'] !== $uid
                    || ! is_int($state['revision']) || $state['revision'] < 1 || $state['revision'] > 9007199254740991
                    || ! is_bool($state['enabled']) || ! is_bool($state['applied'])) {
                    throw new RuntimeException('Invalid saved transition');
                }
                if ($state['revision'] > $request['revision']
                    || ($state['revision'] === $request['revision'] && $state['enabled'] !== $request['enabled'])) {
                    throw new RuntimeException('Stale or conflicting transition');
                }
                if ($state['revision'] === $request['revision'] && $state['applied']
                    && $this->enabled($uid, $lock) === $request['enabled']
                    && ($request['enabled'] || $this->tokenIds($uid, $lock) === [])) {
                    return [...$request, 'old_tokens_purged' => true];
                }
            }
            // The revision fence and all OCC mutations share this container lock.
            $this->save($path, [...$request, 'applied' => false]);
            ($this->occ)(['user:disable', $uid], $lock);
            foreach ($this->tokenIds($uid, $lock) as $id) {
                ($this->occ)(['user:auth-tokens:delete', $uid, (string) $id], $lock);
            }
            if ($this->tokenIds($uid, $lock) !== []) {
                throw new RuntimeException('Old account tokens remain');
            }
            if ($request['enabled']) {
                ($this->occ)(['user:enable', $uid], $lock);
            }
            if ($this->enabled($uid, $lock) !== $request['enabled']) {
                throw new RuntimeException('Account state was not acknowledged');
            }
            $this->save($path, [...$request, 'applied' => true]);

            return [...$request, 'old_tokens_purged' => true];
        } finally {
            // Do not LOCK_UN: an unexpectedly surviving OCC child inherits this
            // open file description as fd3 and must retain the lock until it exits.
            fclose($lock);
        }
    }

    private function enabled(string $uid, mixed $lock): bool
    {
        $info = ($this->occ)(['user:info', $uid, '--output=json'], $lock);
        if (! is_array($info) || ($info['user_id'] ?? null) !== $uid || ! is_bool($info['enabled'] ?? null)) {
            throw new RuntimeException('Unrecognized account response');
        }

        return $info['enabled'];
    }

    private function tokenIds(string $uid, mixed $lock): array
    {
        $tokens = ($this->occ)(['user:auth-tokens:list', $uid, '--output=json'], $lock);
        if (! is_array($tokens) || ! array_is_list($tokens) || count($tokens) > 128) {
            throw new RuntimeException('Unrecognized account token inventory');
        }
        $ids = [];
        foreach ($tokens as $token) {
            $id = is_array($token) ? ($token['id'] ?? null) : null;
            if (is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1 && filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $id = (int) $id;
            }
            if (! is_int($id) || $id < 1 || in_array($id, $ids, true)) {
                throw new RuntimeException('Invalid account token identifier');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function save(string $path, array $state): void
    {
        $output = fopen($path.'.pending', 'wb');
        if ($output === false) {
            throw new RuntimeException('Transition state unavailable');
        }
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($output, $json) !== strlen($json) || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Transition state not durable');
            }
        } finally {
            fclose($output);
        }
        if (! rename($path.'.pending', $path)) {
            throw new RuntimeException('Transition state not committed');
        }
        // Production entry point is Linux-only. Windows pure-state unit tests
        // cannot open/fsync directory descriptors.
        if (PHP_OS_FAMILY !== 'Windows') {
            $directory = fopen($this->directory, 'r');
            if ($directory === false) {
                throw new RuntimeException('Transition directory unavailable');
            }
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('Transition directory not durable');
                }
            } finally {
                fclose($directory);
            }
        }
    }
}

final class NextcloudOccProcess
{
    public static function run(array $arguments, mixed $lock): mixed
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! is_resource($lock)) {
            throw new RuntimeException('Unsupported identity execution environment');
        }
        // Array argv bypasses a shell. fd3 inherits the actual flock resource:
        // https://www.php.net/manual/en/function.proc-open.php
        $process = proc_open(['php', '/var/www/html/occ', '--no-ansi', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $lock], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Account operation could not start');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $bytes = 0;
        $exitCode = -1;
        $started = hrtime(true);
        $running = true;
        try {
            do {
                $read = array_values(array_filter([$pipes[1], $pipes[2]], fn ($pipe): bool => ! feof($pipe)));
                if ($read !== []) {
                    $write = $except = null;
                    if (stream_select($read, $write, $except, 0, 100000) === false) {
                        throw new RuntimeException('Account operation output unavailable');
                    }
                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk === false) {
                            throw new RuntimeException('Account operation output unavailable');
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > 262144) {
                            throw new RuntimeException('Account operation output exceeded limit');
                        }
                        if ($pipe === $pipes[1]) {
                            $stdout .= $chunk;
                        }
                    }
                } else {
                    usleep(1000);
                }
                $status = proc_get_status($process);
                $running = $status['running'];
                if (! $running && $exitCode === -1) {
                    $exitCode = $status['exitcode'];
                }
                if (hrtime(true) - $started > 8_000_000_000) {
                    throw new RuntimeException('Account operation exceeded time limit');
                }
            } while ($running || ! feof($pipes[1]) || ! feof($pipes[2]));
        } finally {
            if ($running) {
                // This is the actual PHP OCC child, not a host-side docker CLI.
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            // Reap before releasing the parent lock, including on timeout/error.
            $closedCode = proc_close($process);
        }
        if (($exitCode >= 0 ? $exitCode : $closedCode) !== 0) {
            throw new RuntimeException('Account operation failed');
        }

        return in_array('--output=json', $arguments, true)
            ? json_decode($stdout, true, 32, JSON_THROW_ON_ERROR) : null;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    umask(0077);
    ini_set('display_errors', '0');
    ignore_user_abort(true);
    set_time_limit(0);
    set_error_handler(static function (): never {
        throw new RuntimeException('Identity transition execution failed');
    });
    try {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('Unsupported identity execution environment');
        }
        $raw = stream_get_contents(STDIN, 2049);
        if ($raw === false || strlen($raw) > 2048) {
            throw new RuntimeException('Invalid identity request');
        }
        $approved = json_decode((string) file_get_contents('/var/www/html/config/hub-identity-uids.json'), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($approved) || ! array_is_list($approved) || $approved === []) {
            throw new RuntimeException('Approved identities unavailable');
        }
        foreach ($approved as $uid) {
            if (! is_string($uid) || preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/', $uid) !== 1) {
                throw new RuntimeException('Approved identities invalid');
            }
        }
        $engine = new NextcloudTransition($approved, '/var/www/html/data/.hub-identity-state', NextcloudOccProcess::run(...));
        echo json_encode($engine->apply(json_decode($raw, true, 8, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    } catch (Throwable) {
        echo '{"error":"Cloud identity transition was not verified"}'.PHP_EOL;
        exit(1);
    }
}
