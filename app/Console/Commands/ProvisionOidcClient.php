<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use RuntimeException;
use Throwable;

/**
 * Creates (or reuses) the confidential authorization-code Passport client for
 * a Hub OIDC application. The plaintext secret is written only to a new file
 * with mode 0600; it is never printed. Passport stores only its hash, so a
 * reused client's secret cannot be recovered or rewritten by this command.
 */
final class ProvisionOidcClient extends Command
{
    protected $signature = 'oidc:provision-client
                            {application : Hub OIDC application: cmd, cloud, meeting, or prompt_studio}
                            {--redirect= : Exact https redirect URI the application registers}
                            {--secret-file= : New file (mode 0600) that receives the client secret; required to create a client}';

    protected $description = 'Create or reuse the confidential Passport client for a Hub OIDC application without printing its secret.';

    private const APPLICATIONS = [
        'cmd' => ['name' => 'MBFD Command Board', 'client_env' => 'OIDC_CMD_CLIENT_ID', 'verified_env' => 'CMD_IDENTITY_RUNTIME_VERIFIED'],
        'cloud' => ['name' => 'MBFD Cloud', 'client_env' => 'OIDC_CLOUD_CLIENT_ID', 'verified_env' => 'CLOUD_IDENTITY_RUNTIME_VERIFIED'],
        'meeting' => ['name' => 'MBFD Meeting Intelligence', 'client_env' => 'OIDC_MEETING_CLIENT_ID', 'verified_env' => 'MEETING_IDENTITY_RUNTIME_VERIFIED'],
        'prompt_studio' => ['name' => 'MBFD Prompt Studio', 'client_env' => 'OIDC_PROMPT_STUDIO_CLIENT_ID', 'verified_env' => 'PROMPT_STUDIO_IDENTITY_RUNTIME_VERIFIED'],
    ];

    /**
     * Applications whose redirect URI must stay on the host of a configured origin.
     * An optional path additionally pins the exact callback path.
     */
    private const PINNED_REDIRECT_HOSTS = [
        'cmd' => ['config' => 'services.command_board.url', 'env' => 'COMMAND_BOARD_URL'],
        'prompt_studio' => ['config' => 'services.prompt_studio.url', 'env' => 'PROMPT_STUDIO_URL', 'path' => '/auth/callback'],
    ];

    private const FAILURE_LOG = 'oidc.provision_client.failed';

    private const GRANT = 'authorization_code';

    private const SECRET_LENGTH = 40;

    public function handle(): int
    {
        $application = (string) $this->argument('application');
        $definition = self::APPLICATIONS[$application] ?? null;
        if ($definition === null) {
            $this->error('Unknown application. Use one of: '.implode(', ', array_keys(self::APPLICATIONS)).'.');

            return self::INVALID;
        }
        $redirect = trim((string) $this->option('redirect'));
        if (! self::isAcceptableRedirect($redirect)) {
            $this->error('--redirect must be an absolute https URL with a host and no credentials or fragment.');

            return self::INVALID;
        }
        $hostError = self::pinnedHostError($application, $redirect);
        if ($hostError !== null) {
            $this->error($hostError);

            return self::INVALID;
        }

        $candidates = $this->existingClients($application, $definition['name']);
        if ($candidates->count() > 1) {
            $this->error('More than one active "'.$definition['name'].'" client exists ('.$candidates->pluck('id')->implode(', ').'). Revoke the extras or set '.$definition['client_env'].' to the one to keep. Nothing was changed.');

            return self::FAILURE;
        }
        $existing = $candidates->first();
        if ($existing instanceof Client) {
            return $this->reuse($existing, $redirect, $definition);
        }

        return $this->create($redirect, $definition);
    }

    /** @param array{name: string, client_env: string, verified_env: string} $definition */
    private function reuse(Client $client, string $redirect, array $definition): int
    {
        $registered = self::redirectUris($client);
        if (! in_array($redirect, $registered, true)) {
            $this->error('Client '.$client->id.' already exists for '.$definition['name'].' with a different redirect URI ('.implode(', ', $registered).'). Nothing was changed.');

            return self::FAILURE;
        }

        $this->info('Reused existing client '.$client->id.' ('.$definition['name'].').');
        $this->line('No secret was written: Passport stores only a hash, so the existing secret cannot be recovered. To replace a lost secret, revoke this client and run the command again.');
        $this->nextSteps($client, $definition);

        return self::SUCCESS;
    }

    /** @param array{name: string, client_env: string, verified_env: string} $definition */
    private function create(string $redirect, array $definition): int
    {
        $secretFile = trim((string) $this->option('secret-file'));
        if ($secretFile === '') {
            $this->error('--secret-file is required to create a client. The secret is written only to that file, never to the console.');

            return self::INVALID;
        }
        $directory = dirname($secretFile);
        if (! is_dir($directory) || ! is_writable($directory) || file_exists($secretFile)) {
            $this->error('--secret-file must be a new file in an existing, writable directory. Existing files are never overwritten. Nothing was changed.');

            return self::FAILURE;
        }

        $secret = Str::random(self::SECRET_LENGTH);
        $written = false;
        try {
            $client = DB::transaction(function () use ($definition, $redirect, $secret, $secretFile, &$written): Client {
                $client = new Client;
                $client->forceFill([
                    'name' => $definition['name'],
                    'secret' => $secret,
                    'provider' => 'users',
                    'redirect_uris' => [$redirect],
                    'grant_types' => [self::GRANT],
                    'revoked' => false,
                ])->save();
                self::writeSecret($secretFile, $secret);
                $written = true;

                return $client;
            });
        } catch (Throwable $exception) {
            if ($written) {
                @unlink($secretFile);
            }
            Log::error(self::FAILURE_LOG, [
                'client' => $definition['name'],
                'exception' => $exception::class,
                'detail' => self::redactedDetail($exception, $secret),
            ]);
            $this->error('The client was not created and no secret file was kept. The cause was written to the application log ('.self::FAILURE_LOG.').');

            return self::FAILURE;
        }

        $this->info('Created confidential client '.$client->id.' ('.$definition['name'].').');
        $this->line('Client secret written to '.$secretFile.' (mode 0600). Give it to the application as its OIDC client secret, then delete the file.');
        $this->nextSteps($client, $definition);

        return self::SUCCESS;
    }

    /** @param array{name: string, client_env: string, verified_env: string} $definition */
    private function nextSteps(Client $client, array $definition): void
    {
        $this->line('Set in the Hub environment: '.$definition['client_env'].'='.$client->id);
        $this->line('After the application\'s Hub sign-in is verified in production, set '.$definition['verified_env'].'=true.');
    }

    /** @return Collection<int, Client> */
    private function existingClients(string $application, string $name): Collection
    {
        $configured = config('oidc.clients.'.$application);
        if (is_string($configured) && $configured !== '') {
            $client = Client::query()->find($configured);
            if ($client instanceof Client && self::isActiveCodeClient($client)) {
                return new Collection([$client]);
            }
            $this->warn('The configured client id for '.$application.' is missing, revoked, or not a confidential code client; looking for "'.$name.'" instead.');
        }

        return Client::query()->where('name', $name)->where('revoked', false)->orderBy('created_at')->get()
            ->filter(fn (Client $client): bool => self::isActiveCodeClient($client))
            ->values()
            ->toBase();
    }

    private static function isActiveCodeClient(Client $client): bool
    {
        return ! $client->revoked && $client->confidential() && $client->hasGrantType(self::GRANT);
    }

    /** @return list<string> */
    private static function redirectUris(Client $client): array
    {
        $uris = $client->getAttribute('redirect_uris');

        return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
    }

    /** The cmd redirect must stay on COMMAND_BOARD_URL's host (and port), so a typo or hostile value cannot receive codes. */
    private static function pinnedHostError(string $application, string $redirect): ?string
    {
        $pin = self::PINNED_REDIRECT_HOSTS[$application] ?? null;
        if ($pin === null) {
            return null;
        }
        $expected = self::authority((string) config($pin['config']));
        if ($expected === null) {
            return $pin['env'].' is not a valid https URL, so the '.$application.' redirect URI cannot be checked. Nothing was changed.';
        }
        $actual = self::authority($redirect);
        if ($actual !== $expected) {
            return 'The '.$application.' redirect URI must be on '.$expected.' (the host in '.$pin['env'].'), not '.($actual ?? 'an invalid host').'. Nothing was changed.';
        }
        $path = $pin['path'] ?? null;
        if ($path !== null && ((string) parse_url($redirect, PHP_URL_PATH) !== $path || parse_url($redirect, PHP_URL_QUERY) !== null)) {
            return 'The '.$application.' redirect URI must be exactly '.$path.' on '.$expected.' with no query string. Nothing was changed.';
        }

        return null;
    }

    private static function authority(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Failure detail for the log: driver text instead of SQL with bindings, and never the plaintext secret. */
    private static function redactedDetail(Throwable $exception, string $secret): string
    {
        $detail = $exception instanceof QueryException
            ? 'Database error '.$exception->getCode().': '.($exception->getPrevious()?->getMessage() ?? 'no driver detail')
            : $exception->getMessage();

        return str_replace($secret, '[redacted]', $detail);
    }

    private static function isAcceptableRedirect(string $redirect): bool
    {
        $parts = parse_url($redirect);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['fragment']);
    }

    private static function writeSecret(string $path, string $secret): void
    {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('the secret file already exists or cannot be created');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('the secret file permissions could not be restricted to 0600');
            }
            if (fwrite($handle, $secret) !== strlen($secret) || ! fflush($handle)) {
                throw new RuntimeException('the secret file could not be written completely');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }
        fclose($handle);
    }
}
