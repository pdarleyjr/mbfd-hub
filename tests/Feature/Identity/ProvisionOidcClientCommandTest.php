<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class ProvisionOidcClientCommandTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://cmd.mbfdhub.com/auth/callback';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/oidc-provision-'.Str::random(12));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_creates_a_confidential_code_client_and_writes_the_secret_only_to_a_private_file(): void
    {
        $path = $this->directory.'/cmd-client-secret';

        [$exit, $output] = $this->provision('cmd', self::REDIRECT, $path);

        self::assertSame(Command::SUCCESS, $exit, $output);
        $client = Client::query()->sole();
        $secret = (string) file_get_contents($path);
        self::assertSame(40, strlen($secret));
        self::assertTrue(Hash::check($secret, (string) $client->secret));
        self::assertStringNotContainsString($secret, $output);
        self::assertStringContainsString('Created confidential client '.$client->id, $output);
        self::assertStringContainsString('OIDC_CMD_CLIENT_ID='.$client->id, $output);
        self::assertStringContainsString('CMD_IDENTITY_RUNTIME_VERIFIED=true', $output);
        self::assertSame('MBFD Command Board', $client->name);
        self::assertSame([self::REDIRECT], $client->redirect_uris);
        self::assertSame(['authorization_code'], $client->grant_types);
        self::assertTrue($client->confidential());
        self::assertFalse($client->revoked);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($path) & 0777);
        }

        config(['oidc.clients.cmd' => $client->id]);
        self::assertTrue(app(ApplicationAccessRegistry::class)->states(User::factory()->create())['cmd']['operational']);
    }

    public function test_repeated_runs_reuse_the_client_and_never_write_another_secret(): void
    {
        $this->provision('cmd', self::REDIRECT, $this->directory.'/first');
        $client = Client::query()->sole();

        [$exit, $output] = $this->provision('cmd', self::REDIRECT, $this->directory.'/second');
        self::assertSame(Command::SUCCESS, $exit, $output);
        self::assertStringContainsString('Reused existing client '.$client->id, $output);
        self::assertFileDoesNotExist($this->directory.'/second');

        config(['oidc.clients.cmd' => $client->id]);
        $client->forceFill(['name' => 'Renamed by an operator'])->save();
        [$exit, $output] = $this->provision('cmd', self::REDIRECT, null);
        self::assertSame(Command::SUCCESS, $exit, $output);
        self::assertStringContainsString('Reused existing client '.$client->id, $output);
        self::assertSame(1, Client::query()->count());
    }

    public function test_reuse_with_a_different_redirect_changes_nothing(): void
    {
        $this->provision('cmd', self::REDIRECT, $this->directory.'/secret');

        [$exit, $output] = $this->provision('cmd', 'https://cmd.mbfdhub.com/oidc/callback', null);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('different redirect URI', $output);
        self::assertSame([self::REDIRECT], Client::query()->sole()->redirect_uris);
    }

    public function test_cmd_redirect_is_pinned_to_the_command_board_host(): void
    {
        foreach (['https://evil.example.test/auth/callback', 'https://cmd.mbfdhub.com.evil.example.test/auth/callback', 'https://cmd.mbfdhub.com:8443/auth/callback'] as $redirect) {
            [$exit, $output] = $this->provision('cmd', $redirect, $this->directory.'/refused');

            self::assertSame(Command::INVALID, $exit, $redirect);
            self::assertStringContainsString('must be on cmd.mbfdhub.com', $output);
        }
        self::assertSame(0, Client::query()->count());
        self::assertFileDoesNotExist($this->directory.'/refused');

        config(['services.command_board.url' => 'https://CMD.example.test/']);
        [$exit, $output] = $this->provision('cmd', 'https://cmd.example.test/auth/callback', $this->directory.'/moved');
        self::assertSame(Command::SUCCESS, $exit, $output);

        [$exit, $output] = $this->provision('cloud', 'https://cloud.mbfdhub.com/apps/user_oidc/code', $this->directory.'/cloud');
        self::assertSame(Command::SUCCESS, $exit, $output);
    }

    public function test_cmd_redirect_is_refused_when_the_command_board_url_is_unusable(): void
    {
        config(['services.command_board.url' => 'http://cmd.mbfdhub.com']);

        [$exit, $output] = $this->provision('cmd', self::REDIRECT, $this->directory.'/secret');

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('COMMAND_BOARD_URL', $output);
        self::assertSame(0, Client::query()->count());
    }

    public function test_prompt_studio_client_is_pinned_to_its_exact_callback_and_never_prints_the_secret(): void
    {
        $callback = 'https://prompt.mbfdhub.com/auth/callback';
        foreach ([
            'https://evil.example.test/auth/callback',
            'https://prompt.mbfdhub.com.evil.example.test/auth/callback',
            'https://prompt.mbfdhub.com:8443/auth/callback',
            'https://prompt.mbfdhub.com/other',
            'https://prompt.mbfdhub.com/auth/callback/',
            'https://prompt.mbfdhub.com/auth/callback?next=https://evil.example.test',
            'https://cmd.mbfdhub.com/auth/callback',
        ] as $redirect) {
            [$exit] = $this->provision('prompt_studio', $redirect, $this->directory.'/refused');

            self::assertSame(Command::INVALID, $exit, $redirect);
        }
        self::assertSame(0, Client::query()->count());
        self::assertFileDoesNotExist($this->directory.'/refused');

        $path = $this->directory.'/prompt-studio-secret';
        [$exit, $output] = $this->provision('prompt_studio', $callback, $path);

        self::assertSame(Command::SUCCESS, $exit, $output);
        $client = Client::query()->sole();
        $secret = (string) file_get_contents($path);
        self::assertSame('MBFD Prompt Studio', $client->name);
        self::assertSame([$callback], $client->redirect_uris);
        self::assertSame(['authorization_code'], $client->grant_types);
        self::assertTrue($client->confidential());
        self::assertTrue(Hash::check($secret, (string) $client->secret));
        self::assertStringNotContainsString($secret, $output);
        self::assertStringContainsString('OIDC_PROMPT_STUDIO_CLIENT_ID='.$client->id, $output);

        [$exit, $output] = $this->provision('prompt_studio', $callback, $this->directory.'/again');
        self::assertSame(Command::SUCCESS, $exit, $output);
        self::assertStringContainsString('Reused existing client '.$client->id, $output);
        self::assertSame(1, Client::query()->count());
    }

    public function test_prompt_studio_redirect_is_refused_when_its_url_is_unusable(): void
    {
        config(['services.prompt_studio.url' => 'http://prompt.mbfdhub.com']);

        [$exit, $output] = $this->provision('prompt_studio', 'https://prompt.mbfdhub.com/auth/callback', $this->directory.'/secret');

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('PROMPT_STUDIO_URL', $output);
        self::assertSame(0, Client::query()->count());
    }

    public function test_adding_prompt_studio_does_not_change_the_other_applications_registration(): void
    {
        [$exit] = $this->provision('cmd', self::REDIRECT, $this->directory.'/cmd');
        self::assertSame(Command::SUCCESS, $exit);
        [$exit] = $this->provision('meeting', 'https://meet.mbfdhub.com/auth/callback', $this->directory.'/meeting');
        self::assertSame(Command::SUCCESS, $exit);
        [$exit] = $this->provision('prompt_studio', 'https://prompt.mbfdhub.com/auth/callback', $this->directory.'/prompt');
        self::assertSame(Command::SUCCESS, $exit);

        self::assertEqualsCanonicalizing(
            ['MBFD Command Board', 'MBFD Meeting Intelligence', 'MBFD Prompt Studio'],
            Client::query()->pluck('name')->all(),
        );
        self::assertSame(['cmd', 'cloud', 'meeting', 'prompt_studio'], array_keys(config('oidc.clients')));
    }

    public function test_failure_prints_a_generic_message_and_logs_detail_without_the_secret(): void
    {
        Log::spy();
        $secret = null;
        Client::creating(function (Client $client) use (&$secret): void {
            $secret = $client->plainSecret;

            throw new RuntimeException('insert rejected for secret '.$client->plainSecret);
        });

        [$exit, $output] = $this->provision('cmd', self::REDIRECT, $this->directory.'/secret');

        self::assertSame(Command::FAILURE, $exit);
        self::assertIsString($secret);
        self::assertStringNotContainsString('insert rejected', $output);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringContainsString('oidc.provision_client.failed', $output);
        self::assertFileDoesNotExist($this->directory.'/secret');
        self::assertSame(0, Client::query()->count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context = []): bool => $message === 'oidc.provision_client.failed'
            && str_contains((string) ($context['detail'] ?? ''), 'insert rejected for secret [redacted]')
            && ! str_contains((string) json_encode($context), $secret));
    }

    public function test_revoked_client_is_replaced_instead_of_reused(): void
    {
        $this->provision('cmd', self::REDIRECT, $this->directory.'/old');
        Client::query()->sole()->forceFill(['revoked' => true])->save();

        [$exit, $output] = $this->provision('cmd', self::REDIRECT, $this->directory.'/new');

        self::assertSame(Command::SUCCESS, $exit, $output);
        self::assertSame(2, Client::query()->count());
        self::assertSame(1, Client::query()->where('revoked', false)->count());
        self::assertFileExists($this->directory.'/new');
    }

    public function test_existing_secret_file_is_never_overwritten(): void
    {
        $path = $this->directory.'/existing';
        file_put_contents($path, 'keep-me');

        [$exit] = $this->provision('cmd', self::REDIRECT, $path);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame('keep-me', file_get_contents($path));
        self::assertSame(0, Client::query()->count());
    }

    public function test_invalid_arguments_create_nothing(): void
    {
        foreach ([
            ['unknown', self::REDIRECT, $this->directory.'/a'],
            ['cmd', 'http://cmd.mbfdhub.com/auth/callback', $this->directory.'/b'],
            ['cmd', 'https://user:pass@cmd.mbfdhub.com/auth/callback', $this->directory.'/c'],
            ['cmd', 'https://cmd.mbfdhub.com/auth/callback#fragment', $this->directory.'/d'],
            ['cmd', self::REDIRECT, null],
        ] as [$application, $redirect, $path]) {
            [$exit] = $this->provision($application, $redirect, $path);

            self::assertSame(Command::INVALID, $exit, $application.' '.$redirect);
        }
        self::assertSame(0, Client::query()->count());
        self::assertSame([], File::files($this->directory));
    }

    /** @return array{int, string} */
    private function provision(string $application, string $redirect, ?string $secretFile): array
    {
        $arguments = ['application' => $application, '--redirect' => $redirect];
        if ($secretFile !== null) {
            $arguments['--secret-file'] = $secretFile;
        }
        $exit = Artisan::call('oidc:provision-client', $arguments);

        return [$exit, Artisan::output()];
    }
}
