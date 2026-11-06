<?php

declare(strict_types=1);

namespace App\Services\PersonnelForms;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills an official PDF form (resources/forms/<form>/<version>) with validated values by running the checked-in
 * pdf-lib overlay renderer (scripts/personnel-forms/render.mjs), the same Node runtime the Operational Forms
 * generator uses in the production image. Returns the PDF bytes; storing them is the caller's decision.
 */
final class PersonnelFormRenderer
{
    public const VEHICLE_ACCIDENT = 'vehicle_accident_report';

    public const SUPERVISOR_ACCIDENT = 'supervisors_report_of_accident';

    public const MEMORANDUM = 'interoffice_memorandum';

    /**
     * @param  array<string, string|bool|null>  $values
     * @param  array<string, string>  $images  PNG bytes keyed by layout image field
     * @param  array{title?:string, reference?:string, subject?:string}  $meta
     */
    public function render(string $form, array $values, array $images = [], array $meta = []): string
    {
        if (! in_array($form, [self::VEHICLE_ACCIDENT, self::SUPERVISOR_ACCIDENT, self::MEMORANDUM], true)) {
            throw new RuntimeException('Unknown personnel form.');
        }
        $directory = storage_path('app/private/personnel-forms-tmp');
        File::ensureDirectoryExists($directory, 0700);
        $nonce = Str::uuid()->toString();
        $input = $directory.'/'.$nonce.'.json';
        $output = $directory.'/'.$nonce.'.pdf';

        try {
            file_put_contents($input, json_encode([
                'values' => $values,
                'images' => array_map('base64_encode', $images),
                'meta' => $meta,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
            $started = hrtime(true);
            $result = Process::timeout((int) config('operational-forms.generator_timeout_seconds', 30))->run([
                config('operational-forms.node_binary', 'node'),
                base_path('scripts/personnel-forms/render.mjs'),
                '--form', $form,
                '--input', $input,
                '--output', $output,
            ]);
            if ($result->failed()) {
                $error = $result->errorOutput();
                Log::warning('Personnel form renderer failed.', [
                    'form' => $form,
                    'exit_code' => $result->exitCode(),
                    'stderr_sha256' => hash('sha256', $error),
                    'stderr_head' => Str::limit(preg_replace('/[^\x20-\x7e]/', '', $error) ?? '', 300),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ]);
                throw new RuntimeException('The official form could not be produced.');
            }
            $metadata = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
            $bytes = file_get_contents($output);
            if ($bytes === false || ! str_starts_with($bytes, '%PDF-') || ! hash_equals((string) $metadata['pdf_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('The produced form failed its integrity check.');
            }
            if (strlen($bytes) > (int) config('operational-forms.maximum_pdf_bytes', 15 * 1024 * 1024)) {
                throw new RuntimeException('The produced form is larger than the safe limit.');
            }

            return $bytes;
        } finally {
            File::delete([$input, $output]);
        }
    }
}
