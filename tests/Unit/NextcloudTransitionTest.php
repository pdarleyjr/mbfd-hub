<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mbfd\CloudIdentity\NextcloudTransition;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/identity/nextcloud_transition.php';

final class NextcloudTransitionTest extends TestCase
{
    private string $directory;

    private bool $enabled = true;

    private array $tokens = [['id' => 17], ['id' => 18]];

    private array $calls = [];

    private bool $failDelete = false;

    private NextcloudTransition $bridge;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mbfd-cloud-transition-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->bridge = new NextcloudTransition(['approveduser'], $this->directory, $this->occ(...));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory.'/'.$name);
            }
        }
        rmdir($this->directory);
    }

    private function occ(array $arguments, mixed $lock): mixed
    {
        $this->assertIsResource($lock);
        $this->calls[] = $arguments;

        return match ($arguments[0]) {
            'user:info' => ['user_id' => 'approveduser', 'enabled' => $this->enabled],
            'user:disable' => $this->enabled = false,
            'user:enable' => $this->enabled = true,
            'user:auth-tokens:list' => $this->tokens,
            'user:auth-tokens:delete' => $this->deleteToken($arguments[2]),
            default => throw new RuntimeException('Unexpected operation'),
        };
    }

    private function deleteToken(string $id): null
    {
        if ($this->failDelete) {
            throw new RuntimeException('Simulated provider failure');
        }
        $this->tokens = array_values(array_filter($this->tokens, fn (array $token): bool => (string) $token['id'] !== $id));

        return null;
    }

    private function apply(int $revision = 1, bool $enabled = false): array
    {
        return $this->bridge->apply(['uid' => 'approveduser', 'revision' => $revision, 'enabled' => $enabled]);
    }

    public function test_denial_disables_and_purges_without_content_operations(): void
    {
        $ack = $this->apply();
        $this->assertFalse($ack['enabled']);
        $this->assertTrue($ack['old_tokens_purged']);
        $this->assertFalse($this->enabled);
        $this->assertSame([], $this->tokens);
        $this->assertSame('user:disable', $this->calls[0][0]);
        $this->assertNotContains('user:delete', array_column($this->calls, 0));
    }

    public function test_enable_happens_only_after_old_tokens_are_purged(): void
    {
        $this->apply(1, true);
        $this->assertTrue($this->enabled);
        $this->assertSame([], $this->tokens);
        $commands = array_column($this->calls, 0);
        $this->assertGreaterThan(array_search('user:auth-tokens:delete', $commands, true), array_search('user:enable', $commands, true));
    }

    public function test_repeated_applied_enable_preserves_new_sessions(): void
    {
        $this->apply(1, true);
        $this->tokens = [['id' => 19]];
        $this->calls = [];
        $this->apply(1, true);
        $this->assertSame([['id' => 19]], $this->tokens);
        $this->assertSame([['user:info', 'approveduser', '--output=json']], $this->calls);
    }

    public function test_old_and_conflicting_revisions_never_execute(): void
    {
        $this->apply(2);
        $this->calls = [];
        foreach ([[1, true], [2, true]] as [$revision, $enabled]) {
            try {
                $this->apply($revision, $enabled);
                $this->fail('Invalid revision accepted');
            } catch (RuntimeException) {
                $this->assertSame([], $this->calls);
            }
        }
    }

    public function test_partial_failure_retains_high_water_and_same_revision_retry_finishes(): void
    {
        $this->apply(1, true);
        $this->tokens = [['id' => 19]];
        $this->failDelete = true;
        try {
            $this->apply(2);
            $this->fail('Expected purge failure');
        } catch (RuntimeException) {
            $this->assertFalse($this->enabled);
            $state = json_decode(file_get_contents($this->directory.'/approveduser.json'), true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(2, $state['revision']);
            $this->assertFalse($state['applied']);
        }
        $this->calls = [];
        try {
            $this->apply(1, true);
            $this->fail('Old enable accepted after newer pending denial');
        } catch (RuntimeException) {
            $this->assertSame([], $this->calls);
        }
        $this->failDelete = false;
        $this->apply(2);
        $this->assertFalse($this->enabled);
        $this->assertSame([], $this->tokens);
    }

    public function test_denial_audits_remove_out_of_band_tokens(): void
    {
        $this->apply();
        $this->tokens = [['id' => 21]];
        $this->apply();
        $this->assertSame([], $this->tokens);
    }

    public function test_external_enable_drift_is_corrected(): void
    {
        $this->apply();
        $this->enabled = true;
        $this->tokens = [['id' => 22]];
        $this->apply();
        $this->assertFalse($this->enabled);
        $this->assertSame([], $this->tokens);
    }

    public function test_unapproved_ids_and_injected_paths_never_execute(): void
    {
        foreach (['sharilipner', '../approveduser', 'approveduser;id', '-root', 'missing'] as $uid) {
            try {
                $this->bridge->apply(['uid' => $uid, 'revision' => 1, 'enabled' => true]);
                $this->fail('Unapproved identity accepted');
            } catch (RuntimeException) {
                $this->assertSame([], $this->calls);
            }
        }
    }

    public function test_invalid_types_and_extra_fields_never_execute(): void
    {
        foreach ([['uid' => 'approveduser', 'revision' => true, 'enabled' => false],
            ['uid' => 'approveduser', 'revision' => 1, 'enabled' => 'false'],
            ['uid' => 'approveduser', 'revision' => 1, 'enabled' => true, 'command' => 'user:delete']] as $request) {
            try {
                $this->bridge->apply($request);
                $this->fail('Invalid request accepted');
            } catch (RuntimeException) {
                $this->assertSame([], $this->calls);
            }
        }
    }

    public function test_concurrent_transition_is_rejected_before_side_effects(): void
    {
        $lock = fopen($this->directory.'/approveduser.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->apply();
            $this->fail('Concurrent transition accepted');
        } catch (RuntimeException) {
            $this->assertSame([], $this->calls);
        } finally {
            fclose($lock);
        }
    }

    public function test_linux_child_inherits_uid_lock_after_parent_descriptor_is_closed(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux descriptor inheritance proof; no OCC or live account operations.');
        }
        $path = $this->directory.'/approveduser.lock';
        $lock = fopen($path, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $child = proc_open([PHP_BINARY, '-r', '$fd = fopen("php://fd/3", "r"); echo is_resource($fd) ? "ready\n" : "missing\n"; fflush(STDOUT); fgets(STDIN);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $lock], $pipes);
        $this->assertIsResource($child);
        try {
            stream_set_timeout($pipes[1], 2);
            $this->assertSame("ready\n", fgets($pipes[1]));
            fclose($lock);
            $contender = fopen($path, 'c');
            try {
                $this->assertFalse(flock($contender, LOCK_EX | LOCK_NB));
            } finally {
                fclose($contender);
            }
            fwrite($pipes[0], "finish\n");
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($child, 9);
            proc_close($child);
        }
        $contender = fopen($path, 'c');
        try {
            $this->assertTrue(flock($contender, LOCK_EX | LOCK_NB));
        } finally {
            fclose($contender);
        }
    }
}
