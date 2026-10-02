<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class StagingStorage
{
    public function create(): string
    {
        $path = rtrim(config('policy-library.storage_root'), '/\\').'/staging/'.Str::uuid();
        if (! mkdir($path, 0700, true)) {
            throw new \RuntimeException('Unable to create document staging storage.');
        }

        return $path;
    }

    public function remove(string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            return;
        }
        $private = realpath(config('policy-library.storage_root'));
        if ($private === false || dirname($resolved) !== $private.DIRECTORY_SEPARATOR.'staging'
            || ! preg_match('/^[a-f0-9-]{36}$/', basename($resolved)) || is_link($path)) {
            throw new \LogicException('Invalid private staging cleanup target.');
        }
        if (! (new Filesystem)->deleteDirectory($resolved)) {
            throw new \RuntimeException('Unable to remove document staging storage.');
        }
    }
}
