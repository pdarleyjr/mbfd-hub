<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A vendored-looking Command Board sandbox bundle in a private temp folder, built from tests/Fixtures/training-sandbox/dist
 * the way scripts/training/vendor-command-board-sandbox.mjs lays it out: index.html and SANDBOX_SOURCE.json in one folder,
 * the hashed assets in another. Unique per instance, so parallel tests never share files.
 */
final class TrainingSandboxFixture
{
    public const MARKER = 'FIXTURE-BUNDLE-MARKER';

    public readonly string $htmlDirectory;

    public readonly string $assetsDirectory;

    private readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sandbox-fixture-'.bin2hex(random_bytes(8));
        $this->htmlDirectory = $this->root.DIRECTORY_SEPARATOR.'html';
        $this->assetsDirectory = $this->root.DIRECTORY_SEPARATOR.'assets';
        mkdir($this->htmlDirectory, 0777, true);
        mkdir($this->assetsDirectory, 0777, true);

        $source = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'training-sandbox'.DIRECTORY_SEPARATOR.'dist';
        copy($source.DIRECTORY_SEPARATOR.'index.html', $this->htmlDirectory.DIRECTORY_SEPARATOR.'index.html');
        $files = [$this->entry('index.html', $this->htmlDirectory.DIRECTORY_SEPARATOR.'index.html')];
        foreach (glob($source.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'*') ?: [] as $asset) {
            $name = basename($asset);
            copy($asset, $this->assetsDirectory.DIRECTORY_SEPARATOR.$name);
            $files[] = $this->entry('assets/'.$name, $asset);
        }

        $this->writeManifest($files);
    }

    public function page(): string
    {
        return (string) file_get_contents($this->htmlDirectory.DIRECTORY_SEPARATOR.'index.html');
    }

    /** Point the Hub at this bundle. */
    public function useIn(\Illuminate\Contracts\Config\Repository $config): void
    {
        $config->set('training-tools.command_board.html_directory', $this->htmlDirectory);
        $config->set('training-tools.command_board.assets_directory', $this->assetsDirectory);
    }

    /** @return list<string> */
    public function assetNames(): array
    {
        return array_map('basename', glob($this->assetsDirectory.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    /** @param  list<array{path: string, sha256: string, bytes: int}>  $files */
    public function writeManifest(array $files): void
    {
        file_put_contents(
            $this->htmlDirectory.DIRECTORY_SEPARATOR.'SANDBOX_SOURCE.json',
            json_encode([
                'repository' => 'pdarleyjr/mbfd-command',
                'commit' => str_repeat('a', 40),
                'built_at' => '2026-11-02T12:00:00.000Z',
                'base_path' => '/training-tools/command-board/',
                'files' => $files,
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{path: string, sha256: string, bytes: int} */
    public function entry(string $path, string $location): array
    {
        return ['path' => $path, 'sha256' => hash_file('sha256', $location), 'bytes' => (int) filesize($location)];
    }

    public function destroy(): void
    {
        foreach ([$this->htmlDirectory, $this->assetsDirectory] as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        @rmdir($this->root);
    }
}
