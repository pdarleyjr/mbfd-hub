<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

/**
 * Central, authoritative source for the F-ROC AI import capacity limits.
 *
 * Both the backend (request validation and bounded ZIP extraction) and the
 * frontend bootstrap read through this class so the MB/KB/entry values can
 * never drift. Untrusted or misconfigured configuration is clamped to safe
 * operational ranges here, in one place.
 */
final class FrocImportLimits
{
    public const MIN_UPLOAD_KILOBYTES = 1024; // 1 MB

    public const MAX_UPLOAD_KILOBYTES = 100 * 1024; // 100 MB

    public const MIN_EXTRACTED_BYTES = 64 * 1024; // 64 KB

    public const MAX_EXTRACTED_BYTES = 4 * 1024 * 1024; // 4 MB

    public const MIN_ZIP_ENTRIES = 1;

    public const MAX_ZIP_ENTRIES = 1000;

    public const MIN_TEXT_ENTRIES = 1;

    public const MAX_TEXT_ENTRIES = 25;

    public const MIN_COMPRESSION_RATIO = 10.0;

    public const MAX_COMPRESSION_RATIO = 200.0;

    public const MIN_MODEL_INPUT_BYTES = 16 * 1024; // 16 KB

    public const MAX_MODEL_INPUT_BYTES = 1 * 1024 * 1024; // 1 MB

    public static function uploadMaxKilobytes(): int
    {
        return self::clampInt(
            (int) config('operational-forms.froc_import_upload_max_kilobytes', 50 * 1024),
            self::MIN_UPLOAD_KILOBYTES,
            self::MAX_UPLOAD_KILOBYTES,
        );
    }

    public static function uploadMaxBytes(): int
    {
        return self::uploadMaxKilobytes() * 1024;
    }

    public static function maxExtractedBytes(): int
    {
        return self::clampInt(
            (int) config('operational-forms.froc_import_max_extracted_bytes', 1 * 1024 * 1024),
            self::MIN_EXTRACTED_BYTES,
            self::MAX_EXTRACTED_BYTES,
        );
    }

    public static function maxZipEntries(): int
    {
        return self::clampInt(
            (int) config('operational-forms.froc_import_max_zip_entries', 500),
            self::MIN_ZIP_ENTRIES,
            self::MAX_ZIP_ENTRIES,
        );
    }

    public static function maxTextEntries(): int
    {
        return self::clampInt(
            (int) config('operational-forms.froc_import_max_text_entries', 10),
            self::MIN_TEXT_ENTRIES,
            self::MAX_TEXT_ENTRIES,
        );
    }

    public static function maxCompressionRatio(): float
    {
        return self::clampFloat(
            (float) config('operational-forms.froc_import_max_compression_ratio', 100),
            self::MIN_COMPRESSION_RATIO,
            self::MAX_COMPRESSION_RATIO,
        );
    }

    public static function maxModelInputBytes(): int
    {
        return self::clampInt(
            (int) config('operational-forms.froc_import_max_model_input_bytes', 256 * 1024),
            self::MIN_MODEL_INPUT_BYTES,
            self::MAX_MODEL_INPUT_BYTES,
        );
    }

    /**
     * Safe, non-sensitive configuration exposed to the Operational Forms
     * React application. No secrets, no source content, no infrastructure detail.
     *
     * @return array<string, mixed>
     */
    public static function bootstrap(): array
    {
        $uploadBytes = self::uploadMaxBytes();
        $extractedBytes = self::maxExtractedBytes();

        return [
            'froc_import' => [
                'accepted_extensions' => ['.zip', '.txt'],
                'upload_max_bytes' => $uploadBytes,
                'upload_max_megabytes' => (int) round($uploadBytes / (1024 * 1024)),
                'max_extracted_bytes' => $extractedBytes,
                'max_extracted_megabytes' => (int) round($extractedBytes / (1024 * 1024)),
                'max_zip_entries' => self::maxZipEntries(),
            ],
        ];
    }

    private static function clampInt(int $value, int $min, int $max): int
    {
        return min(max($value, $min), $max);
    }

    private static function clampFloat(float $value, float $min, float $max): float
    {
        return min(max($value, $min), $max);
    }
}
