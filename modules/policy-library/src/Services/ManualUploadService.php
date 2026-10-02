<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ManualUploadService
{
    public function stageMoms(string $path, ?int $userId, ?Manual $manual = null): array
    {
        app(PdfService::class)->inspect($path);
        $staging = app(StagingStorage::class);
        $root = $staging->create();
        try {
            $qpdf = config('policy-library.qpdf_binary');
            $qpdf = is_file($qpdf) ? $qpdf : (new ExecutableFinder)->find($qpdf);
            if (! $qpdf) {
                throw ValidationException::withMessages(['pdf' => 'PDF processing is unavailable. Try again later.']);
            }
            $process = new Process([config('policy-library.python_binary'), __DIR__.'/../../tools/import_sources.py', '--moms', $path, '--output', $root, '--moms-only', '--qpdf', $qpdf], null, null, null, 900);
            $process->run();
            $manifest = $root.'/import-manifest.json';
            if (! $process->isSuccessful() || ! is_file($manifest)) {
                report(new \RuntimeException('Policy library manual analysis failed (exit '.$process->getExitCode().').'));
                throw ValidationException::withMessages(['pdf' => 'The manual could not be mapped completely. The published edition remains available.']);
            }

            return app(ImportService::class)->stage($this->targetManual(json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR), $manual), $root, $userId);
        } finally {
            $staging->remove($root);
        }
    }

    public function stageSog(string $path, string $filename, ?int $userId, ?Manual $manual = null, ?ManualNode $section = null): array
    {
        $pdf = app(PdfService::class);
        $pdf->inspect($path);
        $staging = app(StagingStorage::class);
        $root = $staging->create();
        try {
            $inputs = $root.'/inputs';
            mkdir($inputs, 0700);
            $filename = basename(str_replace('\\', '/', $filename));
            if (! preg_match('/^mbfd_(section_\d{3}(?:_r\d+(?:\.\d+)*)?|900_company_standards|900_de_task_book|900_training_control_forms|section_100_references_companions)\.pdf$/i', $filename)) {
                $text = $pdf->pageText($path)[0] ?? '';
                if (! preg_match('/POLICY NUMBER:?\s+(\d{3})\./i', $text, $match)) {
                    throw ValidationException::withMessages(['pdf' => 'This SOG PDF needs recognizable policy headers. Use the complete PDF format for another manual.']);
                }
                $filename = 'MBFD_Section_'.$match[1].'.pdf';
            }
            copy($path, $inputs.'/'.$filename);
            $qpdf = config('policy-library.qpdf_binary');
            $qpdf = is_file($qpdf) ? $qpdf : (new ExecutableFinder)->find($qpdf);
            if (! $qpdf) {
                throw ValidationException::withMessages(['pdf' => 'PDF processing is unavailable. Try again later.']);
            }
            $process = new Process([config('policy-library.python_binary'), __DIR__.'/../../tools/import_sources.py', '--sog', $inputs, '--output', $root.'/mapped', '--sog-only', '--qpdf', $qpdf], null, null, null, 900);
            $process->run();
            $manifestPath = $root.'/mapped/import-manifest.json';
            if (! $process->isSuccessful() || ! is_file($manifestPath)) {
                report(new \RuntimeException('Policy library SOG analysis failed (exit '.$process->getExitCode().').'));
                throw ValidationException::withMessages(['pdf' => 'The SOG could not be mapped completely. The published edition remains available.']);
            }
            $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if ($section) {
                $sections = $manifest['manuals'][0]['sections'] ?? [];
                if (count($sections) !== 1) {
                    throw ValidationException::withMessages(['pdf' => 'Upload one logical section PDF.']);
                }
                $incoming = array_replace($sections[0], $section->only(['title', 'slug']));

                return [app(ImportService::class)->stageSection($section, $incoming, $root.'/mapped', $userId)];
            }

            return app(ImportService::class)->stage($this->targetManual($manifest, $manual), $root.'/mapped', $userId);
        } finally {
            $staging->remove($root);
        }
    }

    public function stagePdf(string $path, string $filename, ?int $userId, ?Manual $manual, ?ManualNode $section = null): array
    {
        if (! $manual) {
            throw ValidationException::withMessages(['pdf' => 'Select the manual to replace.']);
        }
        $pdf = app(PdfService::class);
        $inspection = $pdf->inspect($path);
        $text = $pdf->pageText($path);
        $staging = app(StagingStorage::class);
        $root = $staging->create();
        try {
            copy($path, $root.'/manual.pdf');
            $title = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
            $existing = $section ? $section->children()->where('type', 'document') : $manual->nodes()->where('edition_id', $manual->active_edition_id)->where('type', 'document');
            $bookmark = $existing->count() === 1 ? $existing->value('slug') : ($section->slug ?? 'manual').'-document';
            $pages = [];
            for ($page = 1; $page <= $inspection['page_count']; $page++) {
                $pages[] = ['physical_page' => $page, 'title' => $title, 'text' => $text[$page - 1] ?? ''];
            }
            $incoming = ['title' => $section->title ?? $manual->name, 'slug' => $section->slug ?? 'manual-contents', 'documents' => [[
                'title' => $title, 'slug' => Str::limit($bookmark, 255, ''),
                'asset_path' => 'manual.pdf', 'source_path' => 'manual.pdf', 'sha256' => $inspection['sha256'], 'page_count' => $inspection['page_count'], 'pages' => $pages,
            ]]];
            if ($section) {
                return [app(ImportService::class)->stageSection($section, $incoming, $root, $userId)];
            }

            return app(ImportService::class)->stage(['manuals' => [$manual->only(['slug', 'name', 'type']) + ['sections' => [$incoming]]]], $root, $userId);
        } finally {
            $staging->remove($root);
        }
    }

    private function targetManual(array $manifest, ?Manual $manual): array
    {
        if ($manual) {
            $manifest['manuals'][0] = array_replace($manifest['manuals'][0], $manual->only(['slug', 'name', 'type']));
        }

        return $manifest;
    }
}
