<?php

declare(strict_types=1);

namespace App\Support\Release;

use JsonException;

/**
 * The commit this container is serving.
 *
 * Two answers, because two different questions are asked of it:
 *
 *  - commit(): the deploy PROOF (X-App-Commit header, read by the deploy smoke checks).
 *    Order: the private deploy marker written by the deploy workflow, then the .git-sha
 *    snapshot baked into the image, then "unknown". Unchanged from the original
 *    AddBuildHeaders logic.
 *  - servedCommit() / short(): which build is actually SERVING this page, for the page-facing
 *    identity (the public /app-release endpoint and the <meta name="mbfd-release"> tag).
 *    Order: the image's .git-sha first, then the deploy marker, then "unknown". The marker lives
 *    on a host bind mount and is written only after the new container is up and smoke-tested,
 *    and a rollback or a failed marker step leaves it naming the previous build; for that whole
 *    window the new code would otherwise advertise the OLD release, so pages loaded then would
 *    reload once more when the marker finally changed, and an update check made in the window
 *    would see "no change". The code that is answering the request is what must be named.
 *
 * /app-release, the meta tag and the Daily shell all use short(), so those three can never disagree.
 */
final class DeployedRelease
{
    public const UNKNOWN = 'unknown';

    /** Length of the identifier exposed to pages and the update registrar. */
    public const SHORT_LENGTH = 12;

    private ?string $resolved = null;

    private ?string $served = null;

    public function __construct(private readonly ?string $applicationBasePath = null) {}

    /** Full lowercase 40-character commit for the deploy proof header, or "unknown". */
    public function commit(): string
    {
        return $this->resolved ??= $this->resolve();
    }

    /** Full lowercase 40-character commit of the build serving this request, or "unknown". */
    public function servedCommit(): string
    {
        return $this->served ??= $this->resolveServed();
    }

    /** First 12 characters of the served build's commit, or "unknown". Safe to expose publicly. */
    public function short(): string
    {
        $commit = $this->servedCommit();

        return $commit === self::UNKNOWN ? self::UNKNOWN : substr($commit, 0, self::SHORT_LENGTH);
    }

    private function resolve(): string
    {
        $basePath = $this->applicationBasePath ?? base_path();

        return $this->fromDeployMarker($basePath)
            ?? $this->fromSourceSnapshot($basePath)
            ?? self::UNKNOWN;
    }

    private function resolveServed(): string
    {
        $basePath = $this->applicationBasePath ?? base_path();

        return $this->fromSourceSnapshot($basePath)
            ?? $this->fromDeployMarker($basePath)
            ?? self::UNKNOWN;
    }

    private function fromDeployMarker(string $basePath): ?string
    {
        $markerPath = $basePath.'/storage/app/private/deploy-marker.json';
        if (! is_file($markerPath)) {
            return null;
        }

        try {
            $marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Fall back to the source snapshot when a partial marker write is observed.
            return null;
        }

        return is_array($marker) ? $this->normalize($marker['sha'] ?? null) : null;
    }

    private function fromSourceSnapshot(string $basePath): ?string
    {
        $shaPath = $basePath.'/.git-sha';

        return is_file($shaPath) ? $this->normalize(trim((string) file_get_contents($shaPath))) : null;
    }

    private function normalize(mixed $sha): ?string
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha) === 1 ? strtolower($sha) : null;
    }
}
