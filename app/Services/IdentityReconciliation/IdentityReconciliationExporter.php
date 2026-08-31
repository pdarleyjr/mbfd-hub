<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use JsonException;
use RuntimeException;

final class IdentityReconciliationExporter
{
    /** @param array<string, mixed> $report */
    public function toJson(array $report): string
    {
        try {
            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the identity reconciliation preview as JSON.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $report */
    public function toCsv(array $report): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not create the in-memory CSV stream.');
        }

        $header = [
            'entity_type',
            'entity_id',
            'classification',
            'proposed_action',
            'blocked_reason',
            'evidence',
            'user_id',
            'legacy_user_employee_id',
            'user_name',
            'user_email',
            'employee_db_id',
            'employee_id',
            'employee_name',
            'employee_rank',
            'credential_comparison',
            'user_hash_state',
            'user_hash_algorithm',
            'employee_hash_state',
            'employee_hash_algorithm',
            'roles',
            'direct_permissions',
            'effective_permissions',
            'workgroups',
            'training_access',
            'admin_access',
            'super_admin',
            'notification_relationships',
            'external_mappings',
            'owner_decision',
            'snapshot_token',
        ];
        fputcsv($stream, $header, ',', '"', '', "\n");

        foreach ($report['rows'] as $row) {
            $user = $row['user'] ?? [];
            $employee = $row['employee'] ?? [];
            $preservation = $row['preservation'] ?? [];
            fputcsv($stream, [
                $row['entity_type'],
                $row['entity_id'],
                $row['classification'],
                $row['proposed_action'],
                $row['blocked_reason'],
                $row['evidence'],
                $user['id'] ?? null,
                $user['legacy_employee_id'] ?? null,
                $user['name'] ?? null,
                $user['email'] ?? null,
                $employee['id'] ?? null,
                $employee['employee_id'] ?? null,
                $employee['name'] ?? null,
                $employee['rank'] ?? null,
                $row['credential_comparison'],
                $user['credential']['state'] ?? null,
                $user['credential']['algorithm'] ?? null,
                $employee['credential']['state'] ?? null,
                $employee['credential']['algorithm'] ?? null,
                $this->encodeCell($preservation['roles'] ?? []),
                $this->encodeCell($preservation['direct_permissions'] ?? []),
                $this->encodeCell($preservation['effective_permissions'] ?? []),
                $this->encodeCell($preservation['workgroups'] ?? []),
                $this->booleanCell($preservation['training_access'] ?? null),
                $this->booleanCell($preservation['admin_access'] ?? null),
                $this->booleanCell($preservation['super_admin'] ?? null),
                $this->encodeCell($preservation['notification_relationships'] ?? []),
                $this->encodeCell($row['external_mappings'] ?? []),
                $this->encodeCell($row['owner_decision'] ?? null),
                $report['snapshot_token'],
            ], ',', '"', '', "\n");
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new RuntimeException('Could not read the in-memory CSV stream.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $report
     * @return list<array{string, int, string, string, string, string, string}>
     */
    public function tableRows(array $report): array
    {
        return array_map(static function (array $row): array {
            $user = $row['user'] ?? [];
            $employee = $row['employee'] ?? [];

            return [
                (string) $row['entity_type'],
                (int) $row['entity_id'],
                (string) ($employee['employee_id'] ?? $user['legacy_employee_id'] ?? $user['email'] ?? ''),
                (string) ($user['name'] ?? $employee['name'] ?? ''),
                (string) $row['classification'],
                (string) $row['proposed_action'],
                (string) ($row['blocked_reason'] ?? ''),
            ];
        }, $report['rows']);
    }

    public function writeExclusive(string $path, string $contents): void
    {
        $hasWindowsDrivePrefix = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        if ($path === ''
            || str_starts_with($path, '\\\\')
            || (parse_url($path, PHP_URL_SCHEME) !== null && ! $hasWindowsDrivePrefix)) {
            throw new RuntimeException('Output must be a local filesystem path.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Output directory is not writable: {$directory}");
        }
        if (file_exists($path)) {
            throw new RuntimeException("Output file already exists and will not be overwritten: {$path}");
        }

        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            if (file_exists($path)) {
                throw new RuntimeException("Output file already exists and will not be overwritten: {$path}");
            }
            throw new RuntimeException("Could not create output file: {$path}");
        }

        try {
            $written = fwrite($stream, $contents);
            if ($written === false || $written !== strlen($contents) || ! fflush($stream)) {
                throw new RuntimeException("Could not write the complete output file: {$path}");
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            @unlink($path);
            throw $exception;
        }

        fclose($stream);
        @chmod($path, 0600);
    }

    private function encodeCell(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode a CSV cell.', 0, $exception);
        }
    }

    private function booleanCell(mixed $value): string
    {
        return match ($value) {
            true => 'true',
            false => 'false',
            default => '',
        };
    }
}
