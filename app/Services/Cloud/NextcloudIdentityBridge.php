<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use RuntimeException;
use Symfony\Component\Process\Process;

class NextcloudIdentityBridge
{
    /** @return array<string, mixed> */
    public function reconcile(string $uid, int $revision, bool $enabled): array
    {
        $key = config('nextcloud_identity.ssh_key');
        $hosts = config('nextcloud_identity.known_hosts');
        if (preg_match('/\A[a-z][a-z0-9._-]{1,63}\z/', $uid) !== 1 || $revision < 1
            || ! is_string($key) || ! is_readable($key) || ! is_string($hosts) || ! is_readable($hosts)) {
            throw new RuntimeException('Cloud identity bridge is not securely configured.');
        }
        // The restricted SSH credential permits only the installed fixed command, never a shell,
        // port forwarding, account creation, or filesystem/content operations.
        $process = new Process([
            '/usr/bin/ssh', '-T', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'ClearAllForwardings=yes',
            '-o', 'ConnectTimeout=3', '-o', 'UserKnownHostsFile='.$hosts,
            '-i', $key, 'mbfd@host.docker.internal', 'nextcloud-identity',
        ]);
        $process->setInput(json_encode(['uid' => $uid, 'revision' => $revision, 'enabled' => $enabled], JSON_THROW_ON_ERROR));
        $process->setTimeout(25);
        $process->run();
        if (! $process->isSuccessful() || strlen($process->getOutput()) > 4096) {
            throw new RuntimeException('Cloud identity bridge did not acknowledge the requested state.');
        }
        $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new RuntimeException('Cloud identity bridge returned an invalid acknowledgement.');
        }

        return $result;
    }
}
