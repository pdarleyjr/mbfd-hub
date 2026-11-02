<?php

declare(strict_types=1);

namespace App\Support\Training;

/**
 * The vendored Command Board training sandbox: one HTML page (resources/) plus its hashed assets (public/).
 *
 * The page is only handed out when the bundle is exactly what scripts/training/vendor-command-board-sandbox.mjs
 * wrote: the manifest is valid, the page matches its recorded sha256, and every listed asset is present with the
 * recorded size. The repository test (tests/Node/command-board-sandbox-vendoring.test.mjs) hashes every asset; a
 * request only hashes the small page and stats the assets, so a partial deploy or a hand edit answers 503 instead
 * of serving a half-working or altered sandbox.
 */
final readonly class CommandBoardSandboxBundle
{
    public const MANIFEST = 'SANDBOX_SOURCE.json';

    public const PAGE = 'index.html';

    public const ASSET_PREFIX = 'assets/';

    public function __construct(
        private string $htmlDirectory,
        private string $assetsDirectory,
    ) {}

    public static function configured(): self
    {
        return new self(
            (string) config('training-tools.command_board.html_directory'),
            (string) config('training-tools.command_board.assets_directory'),
        );
    }

    /**
     * @throws SandboxBundleUnavailable when the bundle cannot be served exactly as vendored
     */
    public function html(): string
    {
        $files = $this->listedFiles();

        $page = $files[self::PAGE] ?? null;
        if ($page === null) {
            throw new SandboxBundleUnavailable('The manifest does not list index.html.');
        }

        $html = $this->read($this->htmlDirectory.DIRECTORY_SEPARATOR.self::PAGE, self::PAGE);
        if (! hash_equals($page['sha256'], hash('sha256', $html)) || $page['bytes'] !== strlen($html)) {
            throw new SandboxBundleUnavailable('index.html does not match the manifest.');
        }

        $assets = 0;
        foreach ($files as $path => $file) {
            if ($path === self::PAGE) {
                continue;
            }
            $assets++;
            $location = $this->assetsDirectory.DIRECTORY_SEPARATOR.substr($path, strlen(self::ASSET_PREFIX));
            $size = is_file($location) ? filesize($location) : false;
            if ($size === false || $size !== $file['bytes']) {
                throw new SandboxBundleUnavailable("{$path} is missing or has a different size than the manifest.");
            }
        }
        if ($assets === 0) {
            throw new SandboxBundleUnavailable('The manifest lists no assets.');
        }

        return $html;
    }

    /**
     * @return array<string, array{sha256: string, bytes: int}>
     */
    private function listedFiles(): array
    {
        $manifest = json_decode($this->read($this->htmlDirectory.DIRECTORY_SEPARATOR.self::MANIFEST, self::MANIFEST), true);
        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null) || $manifest['files'] === []) {
            throw new SandboxBundleUnavailable('The manifest is not valid.');
        }

        $files = [];
        foreach ($manifest['files'] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            $sha256 = is_array($file) ? ($file['sha256'] ?? null) : null;
            $bytes = is_array($file) ? ($file['bytes'] ?? null) : null;
            if (! is_string($path) || ! is_string($sha256) || ! is_int($bytes) || ! $this->isPlainPath($path)) {
                throw new SandboxBundleUnavailable('The manifest lists a file it should not.');
            }
            $files[$path] = ['sha256' => $sha256, 'bytes' => $bytes];
        }

        return $files;
    }

    /** index.html, or one plain file directly under assets/: nothing that can climb out of the bundle folders. */
    private function isPlainPath(string $path): bool
    {
        return $path === self::PAGE
            || preg_match('#^assets/[A-Za-z0-9][A-Za-z0-9._-]*$#D', $path) === 1;
    }

    private function read(string $location, string $label): string
    {
        $contents = is_file($location) ? file_get_contents($location) : false;
        if ($contents === false || $contents === '') {
            throw new SandboxBundleUnavailable("{$label} is missing or empty.");
        }

        return $contents;
    }
}
