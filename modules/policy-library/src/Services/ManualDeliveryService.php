<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Mbfd\PolicyLibrary\Models\Manual;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

/** Separate, validated delivery copies; never revisions or adoption records. */
class ManualDeliveryService
{
    public function __construct(private TreeService $trees, private RevisionService $storage) {}

    public function snapshot(Manual $manual): array
    {
        $manual->refresh();
        abort_unless(in_array($manual->slug, ['sogs', 'medical-protocols'], true) && $manual->is_active
            && $manual->activeEdition?->state === 'published', 404);
        $ids = $this->trees->documentIds($manual);
        abort_if($ids === [] || count($ids) > 2000, 404);
        $nodes = $manual->nodes()->whereIn('id', $ids)->with('currentRevision')->get()->keyBy('id');
        $catalog = [];
        $sources = [];
        foreach ($ids as $id) {
            $node = $nodes[$id];
            $revision = $node->currentRevision;
            abort_unless($revision && $revision->node_id === $node->id && $revision->state === 'published', 404);
            $catalog[] = ['node_id' => $id, 'slug' => $node->slug, 'parent_id' => $node->parent_id,
                'title' => $node->title, 'sort_order' => $node->sort_order, 'revision_id' => $revision->uuid,
                'sha256' => $revision->sha256, 'page_count' => $revision->page_count,
                'node_metadata_sha256' => hash('sha256', json_encode($node->metadata, JSON_THROW_ON_ERROR)),
                'revision_metadata_sha256' => hash('sha256', json_encode($revision->metadata, JSON_THROW_ON_ERROR))];
            // Section books repeat the primary SOGs; controlled companions have their own package.
            if ($manual->slug === 'sogs' && ($revision->metadata['asset_type'] ?? null) !== 'individual_sog') {
                continue;
            }
            $sources[] = ['slug' => $node->slug, 'sha256' => $revision->sha256, 'page_count' => $revision->page_count];
        }
        abort_if($sources === [], 404);

        return ['manual_slug' => $manual->slug, 'manual_id' => $manual->id, 'edition_id' => $manual->active_edition_id,
            'catalog_sha256' => hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR)), 'sources' => $sources];
    }

    public function summary(Manual $manual): ?array
    {
        $delivery = $this->current($manual);
        if ($delivery === null) {
            return null;
        }
        $base = '/manuals/'.$manual->slug.'/pdf?edition='.$manual->active_edition_id;

        return ['edition_id' => $manual->active_edition_id, 'page_count' => $delivery['page_count'],
            'byte_size' => $delivery['byte_size'], 'download_url' => $base.'&download=1', 'print_url' => $base];
    }

    public function current(Manual $manual, bool $verifyBytes = false): ?array
    {
        if (! in_array($manual->slug, ['sogs', 'medical-protocols'], true)) {
            return null;
        }
        try {
            if (! $this->directory($this->root().'/'.$manual->id.'/'.$manual->active_edition_id, false)) {
                return null;
            }
            $snapshot = $this->snapshot($manual);
            $path = $this->manifestPath($snapshot);
            if (! $this->directory(dirname($path), false)) {
                return null;
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }

            return null;
        } catch (\RuntimeException) {
            return null;
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 1048576) {
            return null;
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! $this->matches($manifest, $snapshot)) {
                return null;
            }
            $artifact = $this->artifactPath($manifest['sha256']);
            if (! $this->directory(dirname($artifact), false) || ! is_file($artifact) || is_link($artifact) || filesize($artifact) !== $manifest['byte_size']
                || ($verifyBytes && hash_file('sha256', $artifact) !== $manifest['sha256'])) {
                return null;
            }

            return array_merge($manifest, ['path' => $artifact]);
        } catch (\JsonException|\RuntimeException) {
            return null;
        }
    }

    /** Called only by the private CLI after independent PDF/source/visual review. */
    public function install(Manual $manual, array $manifest, string $pdf, string $qaReceipt): array
    {
        $snapshot = $this->snapshot($manual);
        if (! $this->matches($manifest, $snapshot) || ! is_file($pdf) || is_link($pdf)
            || filesize($pdf) !== $manifest['byte_size'] || hash_file('sha256', $pdf) !== $manifest['sha256']
            || ! is_file($qaReceipt) || is_link($qaReceipt) || hash_file('sha256', $qaReceipt) !== $manifest['independent_qa_sha256']) {
            throw new \RuntimeException('The complete PDF does not match the current manual and delivery manifest.');
        }
        $ids = $this->trees->documentIds($manual);
        foreach ($manual->nodes()->whereIn('id', $ids)->with('currentRevision')->get() as $node) {
            $source = $this->storage->path($node->currentRevision->storage_path);
            if (! is_file($source) || hash_file('sha256', $source) !== $node->currentRevision->sha256) {
                throw new \RuntimeException('A current source document failed its immutable checksum.');
            }
        }
        $this->inspect($pdf, $manifest['page_count']);
        // Check again after processing; a concurrent publication cannot bind an old PDF to a new edition.
        if ($this->snapshot($manual) !== $snapshot) {
            throw new \RuntimeException('The current edition changed during delivery preparation.');
        }
        $artifact = $this->artifactPath($manifest['sha256']);
        $this->writeImmutable($artifact, $pdf, $manifest['sha256']);
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $destination = $this->manifestPath($snapshot);
        $this->commit($destination, hash('sha256', $encoded), fn ($handle) => $this->writeManifestInto($encoded, $handle));

        return $manifest;
    }

    protected function inspect(string $pdf, int $pages): void
    {
        if (file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
            throw new \RuntimeException('The complete manual is not a PDF.');
        }
        app(MalwareScanner::class)->scan($pdf);
        foreach ([[config('policy-library.qpdf_binary'), '--check', $pdf], [config('policy-library.pdfinfo_binary'), $pdf]] as $arguments) {
            $process = new Process($arguments, null, null, null, 180);
            $process->mustRun(); // qpdf warnings (exit 3) are also rejected.
        }
        if (! preg_match('/^Pages:\s+(\d+)\s*$/m', $process->getOutput(), $match) || (int) $match[1] !== $pages
            || preg_match('/^Encrypted:\s+yes/m', $process->getOutput())) {
            throw new \RuntimeException('The complete manual page count or encryption status did not validate.');
        }
    }

    private function matches(array $manifest, array $snapshot): bool
    {
        foreach ($snapshot as $key => $value) {
            if (($manifest[$key] ?? null) !== $value) {
                return false;
            }
        }

        return ($manifest['schema'] ?? null) === 'mbfd-manual-delivery-v1'
            && is_string($manifest['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $manifest['sha256']) === 1
            && is_string($manifest['independent_qa_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $manifest['independent_qa_sha256']) === 1
            && is_int($manifest['byte_size'] ?? null) && $manifest['byte_size'] > 0
            && is_int($manifest['page_count'] ?? null) && $manifest['page_count'] > 0
            && is_int($manifest['front_pages'] ?? null) && $manifest['front_pages'] >= 0 && $manifest['front_pages'] <= 100
            && $manifest['page_count'] === array_sum(array_column($snapshot['sources'], 'page_count')) + $manifest['front_pages'];
    }

    private function artifactPath(string $hash): string
    {
        return $this->root().'/files/'.$hash.'.pdf';
    }

    private function manifestPath(array $snapshot): string
    {
        return $this->root().'/'.$snapshot['manual_id'].'/'.$snapshot['edition_id'].'/'.$snapshot['catalog_sha256'].'.json';
    }

    private function root(): string
    {
        return rtrim(config('policy-library.storage_root'), '/\\').'/deliveries';
    }

    private function directory(string $path, bool $create = true): bool
    {
        $base = rtrim(str_replace('\\', '/', config('policy-library.storage_root')), '/');
        $path = str_replace('\\', '/', $path);
        if (is_link($base) || ! is_dir($base) || ! str_starts_with($path, $base.'/deliveries')) {
            throw new \RuntimeException('Invalid private delivery storage root.');
        }
        $relative = substr($path, strlen($base) + 1);
        foreach (explode('/', $relative) as $part) {
            if (! preg_match('/^(deliveries|files|[0-9]+)$/', $part)) {
                throw new \RuntimeException('Invalid private delivery directory.');
            }
            $base .= '/'.$part;
            if (is_link($base)) {
                throw new \RuntimeException('Private delivery directories must not be links.');
            }
            if (! is_dir($base)) {
                if (! $create) {
                    return false;
                }
                if (! mkdir($base, 0700) && ! is_dir($base)) {
                    throw new \RuntimeException('Unable to create private delivery storage.');
                }
            }
        }

        return true;
    }

    private function writeImmutable(string $destination, string $source, string $hash): void
    {
        $this->commit($destination, $hash, fn ($output) => $this->copyInto($source, $output));
    }

    protected function copyInto(string $source, $output): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Unable to read the complete manual.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException('Unable to copy the complete manual.');
            }
        } finally {
            fclose($input);
        }
    }

    protected function writeManifestInto(string $encoded, $output): void
    {
        if (fwrite($output, $encoded) !== strlen($encoded)) {
            throw new \RuntimeException('The delivery manifest could not be stored.');
        }
    }

    private function commit(string $destination, string $hash, callable $writer): void
    {
        $this->directory(dirname($destination));
        if (file_exists($destination) || is_link($destination)) {
            $this->assertExact($destination, $hash);

            return;
        }
        $temporary = dirname($destination).'/.delivery-'.bin2hex(random_bytes(16)).'.part';
        $output = null;
        try {
            $output = fopen($temporary, 'xb');
            if ($output === false) {
                throw new \RuntimeException('Unable to stage the complete manual.');
            }
            chmod($temporary, 0600);
            $writer($output);
            if (! fflush($output) || ! fsync($output)) {
                throw new \RuntimeException('The delivery copy could not be flushed.');
            }
            fclose($output);
            $output = null;
            $this->assertExact($temporary, $hash);
            // Same-directory hard-link publication is atomic and never replaces an existing file.
            if (! @link($temporary, $destination)) {
                $this->assertExact($destination, $hash);
            }
            chmod($destination, 0600);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function assertExact(string $path, string $hash): void
    {
        if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
            throw new \RuntimeException('Immutable delivery checksum mismatch.');
        }
    }
}
