<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\ManualNode;
use ZipArchive;

final class ArchiveImportService
{
    public function stage(string $archive, ?int $userId, ?ManualNode $section = null): array
    {
        app(MalwareScanner::class)->scan($archive);
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw ValidationException::withMessages(['package' => 'Upload a valid library import ZIP.']);
        }
        $staging = app(StagingStorage::class);
        $root = $staging->create();
        try {
            $total = 0;
            if ($zip->numFiles > 10000) {
                throw ValidationException::withMessages(['package' => 'The import ZIP contains too many files.']);
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                if (in_array($name, ['assets/', 'sources/'], true)) {
                    continue;
                }
                if (! preg_match('#^(import-manifest\.json|(assets|sources)/[a-f0-9]{64}\.pdf)$#', $name)) {
                    throw ValidationException::withMessages(['package' => 'The ZIP may contain only an import manifest and its original/derived PDFs.']);
                }
                $zip->getExternalAttributesIndex($index, $platform, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000) {
                    throw ValidationException::withMessages(['package' => 'Import packages cannot contain symbolic links.']);
                }
                $total += $stat['size'];
                if ($total > 1024 * 1024 * 1024 || $stat['size'] > config('policy-library.max_upload_kb') * 1024) {
                    throw ValidationException::withMessages(['package' => 'The expanded import package exceeds the document limit.']);
                }
                $destination = $root.'/'.$name;
                if (! is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0700, true);
                }
                $input = $zip->getStream($name);
                if ($input === false) {
                    throw ValidationException::withMessages(['package' => 'The import ZIP could not be read.']);
                }
                $output = fopen($destination, 'xb');
                if ($output === false) {
                    fclose($input);
                    throw ValidationException::withMessages(['package' => 'Import packages cannot contain duplicate files.']);
                }
                try {
                    $written = stream_copy_to_stream($input, $output, $stat['size'] + 1);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($written !== $stat['size']) {
                    throw ValidationException::withMessages(['package' => 'The import ZIP contains an invalid file.']);
                }
            }
            if (! is_file($root.'/import-manifest.json')) {
                throw ValidationException::withMessages(['package' => 'The ZIP is missing its import manifest.']);
            }
            try {
                $manifest = json_decode(file_get_contents($root.'/import-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['package' => 'The import manifest is invalid.']);
            }
            if ($section) {
                $sections = $manifest['manuals'][0]['sections'] ?? [];
                if (count($manifest['manuals'] ?? []) !== 1 || count($sections) !== 1) {
                    throw ValidationException::withMessages(['package' => 'A section replacement must contain exactly one manual with one section.']);
                }

                return [app(ImportService::class)->stageSection($section, $sections[0], $root, $userId)];
            }

            return app(ImportService::class)->stage($manifest, $root, $userId);
        } finally {
            $zip->close();
            $staging->remove($root);
        }
    }
}
