<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\OwnerLedgerEntry;
use DateTimeImmutable;
use JsonException;

final class OwnerLedgerLoader
{
    /** @return list<OwnerLedgerEntry> */
    public function load(?string $path): array
    {
        if ($path === null || $path === '') {
            return [];
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidOwnerLedger("Owner ledger is not a readable file: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidOwnerLedger("Owner ledger could not be read: {$path}");
        }

        try {
            $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidOwnerLedger('Owner ledger is not valid JSON.', 0, $exception);
        }

        if (! is_array($document) || ($document['schema_version'] ?? null) !== 1 || ! is_array($document['entries'] ?? null)) {
            throw new InvalidOwnerLedger('Owner ledger must contain schema_version 1 and an entries array.');
        }

        $entries = [];
        $seenUsers = [];
        $seenEmployees = [];

        foreach (array_values($document['entries']) as $index => $row) {
            $entry = $this->validateEntry($row, $index);

            if ($entry->userId !== null) {
                if (isset($seenUsers[$entry->userId])) {
                    throw new InvalidOwnerLedger("Owner ledger contains duplicate user_id {$entry->userId}.");
                }
                $seenUsers[$entry->userId] = true;
            }

            if ($entry->employeeId !== null) {
                if (isset($seenEmployees[$entry->employeeId])) {
                    throw new InvalidOwnerLedger("Owner ledger contains duplicate employee_id {$entry->employeeId}.");
                }
                $seenEmployees[$entry->employeeId] = true;
            }

            $entries[] = $entry;
        }

        usort($entries, static fn (OwnerLedgerEntry $left, OwnerLedgerEntry $right): int => [
            $left->userId ?? PHP_INT_MAX,
            $left->employeeId ?? '',
            $left->decision,
        ] <=> [
            $right->userId ?? PHP_INT_MAX,
            $right->employeeId ?? '',
            $right->decision,
        ]);

        return $entries;
    }

    private function validateEntry(mixed $row, int $index): OwnerLedgerEntry
    {
        $path = "entries.{$index}";
        if (! is_array($row)) {
            throw new InvalidOwnerLedger("Owner ledger {$path} must be an object.");
        }

        $allowed = ['user_id', 'employee_id', 'decision', 'approved_by', 'approved_at', 'approval_reference', 'notes', 'credential_action'];
        $unknown = array_diff(array_keys($row), $allowed);
        if ($unknown !== []) {
            throw new InvalidOwnerLedger("Owner ledger {$path} has unknown fields: ".implode(', ', $unknown).'.');
        }

        $decision = $row['decision'] ?? null;
        if (! is_string($decision) || ! in_array($decision, ['LINK', 'CREATE_USER', 'QUARANTINE'], true)) {
            throw new InvalidOwnerLedger("Owner ledger {$path}.decision must be LINK, CREATE_USER, or QUARANTINE.");
        }

        $userId = $row['user_id'] ?? null;
        $employeeId = $row['employee_id'] ?? null;
        if ($userId !== null && (! is_int($userId) || $userId < 1)) {
            throw new InvalidOwnerLedger("Owner ledger {$path}.user_id must be a positive integer or null.");
        }
        if ($employeeId !== null && (! is_string($employeeId) || $employeeId === '' || trim($employeeId) !== $employeeId)) {
            throw new InvalidOwnerLedger("Owner ledger {$path}.employee_id must be an exact non-blank string without surrounding whitespace.");
        }

        if ($decision === 'LINK' && ($userId === null || $employeeId === null)) {
            throw new InvalidOwnerLedger("Owner ledger {$path} LINK requires user_id and employee_id.");
        }
        if ($decision === 'CREATE_USER' && ($userId !== null || $employeeId === null)) {
            throw new InvalidOwnerLedger("Owner ledger {$path} CREATE_USER requires employee_id and a null user_id.");
        }
        if ($decision === 'QUARANTINE' && $userId === null) {
            throw new InvalidOwnerLedger("Owner ledger {$path} QUARANTINE requires user_id.");
        }

        $credentialAction = $row['credential_action'] ?? null;
        if ($credentialAction !== null
            && (! is_string($credentialAction)
                || ! in_array($credentialAction, ['PRESERVE_CANONICAL_HASH', 'COPY_COMPATIBLE_LEGACY_HASH'], true))) {
            throw new InvalidOwnerLedger(
                "Owner ledger {$path}.credential_action must be PRESERVE_CANONICAL_HASH, COPY_COMPATIBLE_LEGACY_HASH, or null.",
            );
        }
        if ($credentialAction !== null && $decision !== 'LINK') {
            throw new InvalidOwnerLedger("Owner ledger {$path}.credential_action is only valid for a LINK decision.");
        }

        $approvedBy = $this->requiredString($row, 'approved_by', $path);
        $approvedAt = $this->requiredString($row, 'approved_at', $path);
        $approvalReference = $this->requiredString($row, 'approval_reference', $path);

        $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $approvedAt);
        if ($date === false || $date->format(DateTimeImmutable::ATOM) !== $approvedAt) {
            throw new InvalidOwnerLedger("Owner ledger {$path}.approved_at must be an exact RFC3339 timestamp.");
        }

        $notes = $row['notes'] ?? null;
        if ($notes !== null && ! is_string($notes)) {
            throw new InvalidOwnerLedger("Owner ledger {$path}.notes must be a string or null.");
        }

        return new OwnerLedgerEntry(
            userId: $userId,
            employeeId: $employeeId,
            decision: $decision,
            approvedBy: $approvedBy,
            approvedAt: $approvedAt,
            approvalReference: $approvalReference,
            notes: $notes,
            credentialAction: $credentialAction,
        );
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field, string $path): string
    {
        $value = $row[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidOwnerLedger("Owner ledger {$path}.{$field} must be a non-blank string.");
        }

        return $value;
    }
}
