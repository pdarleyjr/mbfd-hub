<?php

declare(strict_types=1);

namespace App\Services\Identity;

use RuntimeException;

final class MemberBootstrapCohortManifest
{
    /**
     * @return array{
     *   source_backup_file:string,
     *   source_backup_sha256:string,
     *   manifest_sha256:string,
     *   members:list<array{user_id:int,employee_profile_id:int,created_at:string,password_hash_fingerprint:string}>
     * }
     */
    public function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Bootstrap cohort manifest is not a readable file.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ((int) fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Bootstrap cohort manifest permissions must be restricted to mode 0600.');
        }

        $json = file_get_contents($path);
        if ($json === false || strlen($json) > 2_000_000) {
            throw new RuntimeException('Bootstrap cohort manifest cannot be read safely.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ($decoded['schema'] ?? null) !== 'mbfd-member-bootstrap-cohort-v1'
            || ! is_string($decoded['source_backup_file'] ?? null)
            || preg_match('/^mbfd-hub-pre-activation-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{40}\.dump$/', $decoded['source_backup_file']) !== 1
            || ! is_string($decoded['source_backup_sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/', $decoded['source_backup_sha256']) !== 1
            || ! is_int($decoded['expected_count'] ?? null)
            || $decoded['expected_count'] < 1
            || $decoded['expected_count'] > 5000
            || ! is_array($decoded['members'] ?? null)
            || count($decoded['members']) !== $decoded['expected_count']) {
            throw new RuntimeException('Bootstrap cohort manifest schema is invalid.');
        }

        $members = [];
        $seenUserIds = [];
        $seenEmployeeProfileIds = [];
        foreach ($decoded['members'] as $member) {
            if (! is_array($member)
                || ! is_int($member['user_id'] ?? null) || $member['user_id'] < 1
                || ! is_int($member['employee_profile_id'] ?? null) || $member['employee_profile_id'] < 1
                || ! is_string($member['created_at'] ?? null) || trim($member['created_at']) === ''
                || ! is_string($member['password_hash_fingerprint'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/', $member['password_hash_fingerprint']) !== 1
                || isset($seenUserIds[$member['user_id']])
                || isset($seenEmployeeProfileIds[$member['employee_profile_id']])) {
                throw new RuntimeException('Bootstrap cohort manifest member evidence is invalid.');
            }
            $seenUserIds[$member['user_id']] = true;
            $seenEmployeeProfileIds[$member['employee_profile_id']] = true;
            $members[] = [
                'user_id' => $member['user_id'],
                'employee_profile_id' => $member['employee_profile_id'],
                'created_at' => $member['created_at'],
                'password_hash_fingerprint' => $member['password_hash_fingerprint'],
            ];
        }
        usort($members, static fn (array $left, array $right): int => $left['user_id'] <=> $right['user_id']);

        return [
            'source_backup_file' => $decoded['source_backup_file'],
            'source_backup_sha256' => $decoded['source_backup_sha256'],
            'manifest_sha256' => hash('sha256', $json),
            'members' => $members,
        ];
    }
}
