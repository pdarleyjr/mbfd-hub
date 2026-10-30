<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\InboundEmail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Decides whether an inbound message is the TeleStaff roster export.
 *
 * Mail unrelated to the export leaves on the first check, touching nothing but values already
 * in memory. Sender, subject, filename and every header value are untrusted input: a From
 * header alone proves nothing, so a match also needs Cloudflare's authentication results.
 */
final class TeleStaffMailClassifier
{
    private const SPREADSHEET_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const ZIP_SIGNATURE = "PK\x03\x04";

    private const EVIDENCE_PASS = 'pass';

    private const EVIDENCE_FAIL = 'fail';

    private const EVIDENCE_INCONCLUSIVE = 'inconclusive';

    public function classify(InboundEmail $email): TeleStaffMailClassification
    {
        /** @var array{sender: string, sender_domain: string, subject: string, filename: string, authserv_id: string} $rules */
        $rules = config('daily_roster.classifier');
        $from = strtolower(trim((string) $email->from_address));
        $senderDomain = strtolower($rules['sender_domain']);
        $trustedDomain = self::domainOf($from) === $senderDomain;
        $subjectMatches = self::fold((string) $email->subject) === self::fold($rules['subject']);
        $attachments = is_array($email->attachment_metadata) ? $email->attachment_metadata : [];
        $filenameMatches = self::hasConfiguredFilename($attachments, $rules['filename']);

        if (! $trustedDomain && ! $subjectMatches && ! $filenameMatches) {
            return new TeleStaffMailClassification(TeleStaffMailClassification::ORDINARY);
        }

        $senderMatches = $from === strtolower(trim($rules['sender']));
        if ($senderMatches && $subjectMatches && $filenameMatches) {
            return $this->classifyCandidate($email, $attachments, $senderDomain, $rules['authserv_id']);
        }

        $misses = array_values(array_filter([
            $senderMatches ? null : 'sender_mismatch',
            $subjectMatches ? null : 'subject_mismatch',
            $filenameMatches ? null : 'filename_mismatch',
        ]));

        if ($senderMatches) {
            [$status, $reasons] = $this->evaluateEvidence($email, $senderDomain, $rules['authserv_id']);
            if ($status === self::EVIDENCE_FAIL) {
                return new TeleStaffMailClassification(TeleStaffMailClassification::REJECTED, [...$reasons, ...$misses]);
            }
        }

        $matched = 3 - count($misses);
        if ($matched >= 2 || ($trustedDomain && ! $senderMatches && $matched === 1)) {
            return new TeleStaffMailClassification(TeleStaffMailClassification::REJECTED, ['near_miss', ...$misses]);
        }

        return new TeleStaffMailClassification(TeleStaffMailClassification::ORDINARY);
    }

    /**
     * Sender, subject and filename all match, so this claims to be the export.
     *
     * @param  array<int|string, mixed>  $attachments
     */
    private function classifyCandidate(InboundEmail $email, array $attachments, string $senderDomain, string $authservId): TeleStaffMailClassification
    {
        [$status, $evidenceReasons] = $this->evaluateEvidence($email, $senderDomain, $authservId);
        $contradictions = $this->contradictions($email);
        $shapeProblems = $this->attachmentShapeProblems($attachments);

        if ($status === self::EVIDENCE_FAIL) {
            return new TeleStaffMailClassification(TeleStaffMailClassification::REJECTED, [...$evidenceReasons, ...$contradictions, ...$shapeProblems]);
        }
        if ($contradictions !== []) {
            return new TeleStaffMailClassification(TeleStaffMailClassification::REJECTED, [...$contradictions, ...$shapeProblems]);
        }
        if ($status !== self::EVIDENCE_PASS || $shapeProblems !== []) {
            return new TeleStaffMailClassification(
                TeleStaffMailClassification::NEEDS_REVIEW,
                [...($status === self::EVIDENCE_PASS ? [] : $evidenceReasons), ...$shapeProblems],
            );
        }

        return new TeleStaffMailClassification(TeleStaffMailClassification::MATCH, $evidenceReasons);
    }

    /** @return list<string> Signals that this is not the automated export. */
    private function contradictions(InboundEmail $email): array
    {
        $reasons = [];
        if (strtolower(trim((string) $email->to_address)) !== strtolower(trim((string) config('communications.inbound.address', 'info@mbfdhub.com')))) {
            $reasons[] = 'recipient_mismatch';
        }
        if (filled($email->in_reply_to)) {
            $reasons[] = 'in_reply_to_present';
        }
        if (filled($email->references)) {
            $reasons[] = 'references_present';
        }

        return $reasons;
    }

    /**
     * @param  array<int|string, mixed>  $attachments
     * @return list<string>
     */
    private function attachmentShapeProblems(array $attachments): array
    {
        if (count($attachments) !== 1) {
            return ['attachment_count_not_one'];
        }

        $attachment = array_values($attachments)[0];
        $problems = [];
        if (strtolower(trim((string) ($attachment['mime_type'] ?? ''))) !== self::SPREADSHEET_MIME) {
            $problems[] = 'attachment_mime_mismatch';
        }

        return [...$problems, ...$this->storedFileProblems((string) ($attachment['disk'] ?? ''), (string) ($attachment['path'] ?? ''))];
    }

    /** @return list<string> */
    private function storedFileProblems(string $disk, string $path): array
    {
        if ($disk === '' || $path === '') {
            return ['attachment_file_missing'];
        }

        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return ['attachment_file_missing'];
            }

            $bytes = (int) $storage->size($path);
            $problems = [];
            if ($bytes < 1) {
                $problems[] = 'attachment_empty';
            } elseif ($bytes > (int) config('daily_roster.workbook.max_bytes')) {
                $problems[] = 'attachment_too_large';
            }

            $stream = $storage->readStream($path);
            if (! is_resource($stream)) {
                return [...$problems, 'attachment_unreadable'];
            }
            $signature = fread($stream, strlen(self::ZIP_SIGNATURE));
            fclose($stream);

            return $signature === self::ZIP_SIGNATURE ? $problems : [...$problems, 'attachment_signature_invalid'];
        } catch (Throwable) {
            return ['attachment_unreadable'];
        }
    }

    /**
     * Reads the results Cloudflare authored. The topmost Authentication-Results wins; the ARC copy
     * is only consulted when that header is absent, unparseable or written by another server.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function evaluateEvidence(InboundEmail $email, string $senderDomain, string $authservId): array
    {
        $evidence = is_array($email->transport_evidence) ? $email->transport_evidence : [];
        $reasons = [];

        foreach (['authentication_results' => false, 'arc_authentication_results' => true] as $source => $isArc) {
            $header = $evidence[$source] ?? null;
            if (! is_string($header) || trim($header) === '') {
                $reasons[] = $source.'_missing';

                continue;
            }

            $results = $this->parseResults($header, $isArc, $authservId);
            if (is_string($results)) {
                $reasons[] = $source.'_'.$results;

                continue;
            }

            [$status, $resultReasons] = $this->judgeResults($results, $senderDomain);
            if ($status !== self::EVIDENCE_PASS) {
                return [$status, $resultReasons];
            }

            return $this->judgeEnvelope($evidence['envelope_from'] ?? null, $senderDomain, $source);
        }

        return [self::EVIDENCE_INCONCLUSIVE, $reasons];
    }

    /** @return array{0: string, 1: list<string>} */
    private function judgeEnvelope(mixed $envelopeFrom, string $senderDomain, string $source): array
    {
        if (! is_string($envelopeFrom) || trim($envelopeFrom) === '') {
            return [self::EVIDENCE_INCONCLUSIVE, ['envelope_from_missing']];
        }

        return self::isAligned(self::domainOf($envelopeFrom), $senderDomain)
            ? [self::EVIDENCE_PASS, ['authenticated_by_'.$source]]
            : [self::EVIDENCE_INCONCLUSIVE, ['envelope_from_not_aligned']];
    }

    /**
     * @param  list<array{method: string, result: string, properties: array<string, string>}>  $results
     * @return array{0: string, 1: list<string>}
     */
    private function judgeResults(array $results, string $senderDomain): array
    {
        $byMethod = ['dmarc' => [], 'dkim' => [], 'spf' => []];
        foreach ($results as $result) {
            if (isset($byMethod[$result['method']])) {
                $byMethod[$result['method']][] = $result;
            }
        }

        $dmarcPasses = [];
        foreach ($byMethod['dmarc'] as $dmarc) {
            if ($dmarc['result'] === 'fail') {
                return [self::EVIDENCE_FAIL, ['evidence_dmarc_fail']];
            }
            if ($dmarc['result'] === 'pass') {
                $dmarcPasses[] = $dmarc;
            }
        }

        $identityResults = [...$byMethod['dkim'], ...$byMethod['spf']];
        if ($dmarcPasses === []) {
            return $this->judgeWithoutDmarcPass($byMethod['dmarc'], $identityResults);
        }

        foreach ($dmarcPasses as $dmarc) {
            $headerFrom = $dmarc['properties']['header.from'] ?? null;
            if ($headerFrom !== null && self::domainOf($headerFrom) !== $senderDomain) {
                return [self::EVIDENCE_FAIL, ['evidence_header_from_mismatch']];
            }
        }

        $sawForeignIdentity = false;
        foreach ($identityResults as $result) {
            if ($result['result'] !== 'pass') {
                continue;
            }
            foreach ($result['method'] === 'dkim' ? ['header.d', 'header.i'] : ['smtp.mailfrom'] as $property) {
                $value = $result['properties'][$property] ?? null;
                if ($value === null) {
                    continue;
                }
                if (self::isAligned(self::domainOf($value), $senderDomain)) {
                    return [self::EVIDENCE_PASS, []];
                }
                $sawForeignIdentity = true;
            }
        }

        return $sawForeignIdentity
            ? [self::EVIDENCE_FAIL, ['evidence_identity_not_aligned']]
            : [self::EVIDENCE_INCONCLUSIVE, ['evidence_no_aligned_identity']];
    }

    /**
     * @param  list<array{method: string, result: string, properties: array<string, string>}>  $dmarcResults
     * @param  list<array{method: string, result: string, properties: array<string, string>}>  $identityResults
     * @return array{0: string, 1: list<string>}
     */
    private function judgeWithoutDmarcPass(array $dmarcResults, array $identityResults): array
    {
        if ($dmarcResults !== []) {
            return [self::EVIDENCE_INCONCLUSIVE, ['evidence_dmarc_not_pass']];
        }

        $anyPass = false;
        $anyFail = false;
        foreach ($identityResults as $result) {
            $anyPass = $anyPass || $result['result'] === 'pass';
            $anyFail = $anyFail || in_array($result['result'], ['fail', 'softfail'], true);
        }

        return $anyFail && ! $anyPass
            ? [self::EVIDENCE_FAIL, ['evidence_dkim_spf_fail']]
            : [self::EVIDENCE_INCONCLUSIVE, ['evidence_dmarc_absent']];
    }

    /**
     * RFC 8601 results, tolerant of comments, whitespace and missing semicolons. Quoted values stay
     * values, so reason="dmarc=pass" can never be read as a result.
     *
     * @return list<array{method: string, result: string, properties: array<string, string>}>|string
     *                                                                                               The results, or a failure code
     */
    private function parseResults(string $header, bool $isArc, string $authservId): array|string
    {
        $text = self::stripComments($header);
        if ($text === null) {
            return 'unparseable';
        }
        if ($isArc) {
            $text = (string) preg_replace('/^\s*i\s*=\s*\d+\s*;/i', '', $text);
        }
        if (preg_match('/^\s*([^\s;]+)(.*)$/s', $text, $parts) !== 1) {
            return 'unparseable';
        }
        if (strtolower($parts[1]) !== strtolower($authservId)) {
            return 'foreign_authserv';
        }

        preg_match_all(
            '/([A-Za-z0-9_.\-\/]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^\s;"]*)/s',
            $parts[2],
            $pairs,
            PREG_SET_ORDER,
        );

        $results = [];
        foreach ($pairs as [, $key, $value]) {
            $key = strtolower($key);
            $value = self::unquote($value);
            if (str_contains($key, '.')) {
                if ($results !== [] && ! isset($results[array_key_last($results)]['properties'][$key])) {
                    $results[array_key_last($results)]['properties'][$key] = strtolower($value);
                }
            } elseif ($key !== 'reason' && $key !== 'action') {
                $results[] = ['method' => explode('/', $key)[0], 'result' => strtolower($value), 'properties' => []];
            }
        }

        return $results === [] ? 'unparseable' : $results;
    }

    /** @return string|null Null when quotes or parentheses do not balance. */
    private static function stripComments(string $header): ?string
    {
        $output = '';
        $depth = 0;
        $inQuote = false;
        $length = strlen($header);

        for ($index = 0; $index < $length; $index++) {
            $character = $header[$index];
            if ($character === '\\' && ($inQuote || $depth > 0)) {
                if ($inQuote) {
                    $output .= $character.($header[$index + 1] ?? '');
                }
                $index++;
            } elseif ($inQuote) {
                $output .= $character;
                $inQuote = $character !== '"';
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($depth === 0) {
                $output .= $character;
                $inQuote = $character === '"';
            }
        }

        return $depth === 0 && ! $inQuote ? $output : null;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"') {
            return (string) preg_replace('/\\\\(.)/s', '$1', substr($value, 1, -1));
        }

        return $value;
    }

    /** @param array<int|string, mixed> $attachments */
    private static function hasConfiguredFilename(array $attachments, string $filename): bool
    {
        $expected = mb_strtolower($filename);
        foreach ($attachments as $attachment) {
            if (is_array($attachment) && mb_strtolower((string) ($attachment['filename'] ?? '')) === $expected) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** The domain of an address, or the value itself when it is already a bare domain. */
    private static function domainOf(string $value): string
    {
        $value = strtolower(trim($value, " \t<>"));
        $at = strrpos($value, '@');

        return rtrim($at === false ? $value : substr($value, $at + 1), '.');
    }

    private static function isAligned(string $domain, string $senderDomain): bool
    {
        return $domain === $senderDomain || str_ends_with($domain, '.'.$senderDomain);
    }
}
