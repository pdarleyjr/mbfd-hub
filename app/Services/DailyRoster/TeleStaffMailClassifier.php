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
 *
 * Which header Cloudflare writes has not been observed live, so neither kind is favoured: every
 * forwarded header naming Cloudflare's authserv-id is a claim and every claim must pass. A forged
 * message always carries Cloudflare's real failing claim beside any injected passing one, so
 * all-must-pass closes injection, provided Cloudflare always adds at least one.
 */
final class TeleStaffMailClassifier
{
    private const SPREADSHEET_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const ZIP_SIGNATURE = "PK\x03\x04";

    private const EVIDENCE_PASS = 'pass';

    private const EVIDENCE_FAIL = 'fail';

    private const EVIDENCE_INCONCLUSIVE = 'inconclusive';

    /** Forwarded header => whether it carries the ARC `i=N;` prefix. */
    private const CLAIM_SOURCES = ['authentication_results' => false, 'arc_authentication_results' => true];

    private const HEADER_MISSING = 'missing';

    private const HEADER_FOREIGN = 'foreign_authserv';

    private const CLAIM_UNPARSEABLE = 'unparseable';

    private const CLAIM_NO_RESULTS = 'no_results';

    public function classify(InboundEmail $email): TeleStaffMailClassification
    {
        $classification = $this->decide($email);

        return $classification->isOrdinary()
            ? $classification
            : new TeleStaffMailClassification($classification->verdict, $classification->reasons, self::evidenceSummary($email));
    }

    /**
     * The transport facts the ledger keeps once the email is gone.
     *
     * @return array<string, mixed>
     */
    public static function evidenceSummary(InboundEmail $email): array
    {
        $evidence = is_array($email->transport_evidence) ? $email->transport_evidence : [];

        return array_intersect_key($evidence, array_flip([
            'envelope_from', 'authentication_results', 'arc_authentication_results', 'received_spf', 'cloudflare_claims', 'truncated',
        ]));
    }

    private function decide(InboundEmail $email): TeleStaffMailClassification
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
     * Every readable claim's failure outranks everything else: Cloudflare's real result on a forgery
     * reads the same whatever else the message carries. Then any doubt about the claims holds it, and
     * only claims that all pass, plus an aligned envelope, can match.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function evaluateEvidence(InboundEmail $email, string $senderDomain, string $authservId): array
    {
        $evidence = is_array($email->transport_evidence) ? $email->transport_evidence : [];
        [$claims, $doubts, $absences] = $this->collectClaims($evidence, $authservId);

        $failures = [];
        foreach ($claims as $results) {
            $failures = [...$failures, ...$this->claimFailures($results, $senderDomain)];
        }
        if ($failures !== []) {
            return [self::EVIDENCE_FAIL, array_values(array_unique($failures))];
        }

        if (($evidence['truncated'] ?? false) === true) {
            array_unshift($doubts, 'evidence_truncated');
        }
        if ($claims === [] && $doubts === []) {
            return [self::EVIDENCE_INCONCLUSIVE, $absences];
        }

        foreach ($claims as $results) {
            $doubts = [...$doubts, ...$this->claimDoubts($results, $senderDomain)];
        }
        if ($doubts !== []) {
            return [self::EVIDENCE_INCONCLUSIVE, array_values(array_unique($doubts))];
        }

        return $this->judgeEnvelope($evidence['envelope_from'] ?? null, $senderDomain, array_keys($claims));
    }

    /**
     * Reads the forwarded headers against the Worker's counts. The counts cover headers the Worker did
     * not forward, so a claim it saw but did not send can never be passed over: one sitting below another
     * server's header would otherwise let a clean claim of the other kind match on its own. Returns the
     * parsed claims by source, the doubts that block a match, and which headers said nothing.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{0: array<string, list<array{method: string, result: string, properties: array<string, string>}>>, 1: list<string>, 2: list<string>}
     */
    private function collectClaims(array $evidence, string $authservId): array
    {
        $counts = is_array($evidence['cloudflare_claims'] ?? null) ? $evidence['cloudflare_claims'] : null;
        $claims = [];
        $doubts = [];
        $absences = [];

        foreach (self::CLAIM_SOURCES as $source => $isArc) {
            $header = $evidence[$source] ?? null;
            $reading = is_string($header) && trim($header) !== ''
                ? $this->readClaim($header, $isArc, $authservId)
                : self::HEADER_MISSING;
            $count = is_int($counts[$source] ?? null) ? $counts[$source] : null;
            $isClaim = ! in_array($reading, [self::HEADER_MISSING, self::HEADER_FOREIGN], true);

            if (is_array($reading)) {
                $claims[$source] = $reading;
            } elseif ($isClaim) {
                $doubts[] = $source.'_'.$reading;
            } else {
                $absences[] = $source.'_'.$reading;
            }

            if ($count > 1) {
                $doubts[] = $source.'_multiple_claims';
            } elseif ($count === 1 && ! $isClaim) {
                $doubts[] = $source.'_claim_not_forwarded';
            } elseif ($count === 0 && $isClaim) {
                $doubts[] = $source.'_claim_count_mismatch';
            }
        }

        if ($counts === null && ($claims !== [] || $doubts !== [])) {
            $doubts[] = 'cloudflare_claims_missing';
        }

        return [$claims, $doubts, $absences];
    }

    /**
     * @param  list<array{method: string, result: string, properties: array<string, string>}>  $results
     * @return list<string> What this claim says went wrong for the sender's domain
     */
    private function claimFailures(array $results, string $senderDomain): array
    {
        $failures = [];
        foreach ($results as $result) {
            if ($result['method'] === 'dmarc' && $result['result'] === 'fail') {
                $failures[] = 'evidence_dmarc_fail';
            } elseif ($result['method'] === 'spf' && $result['result'] === 'fail') {
                $failures[] = 'evidence_spf_fail';
            } elseif ($result['method'] === 'dkim' && $result['result'] === 'fail' && self::dkimIsAligned($result, $senderDomain)) {
                $failures[] = 'evidence_dkim_fail';
            } elseif ($result['method'] === 'dmarc' && $result['result'] === 'pass'
                && isset($result['properties']['header.from'])
                && self::domainOf($result['properties']['header.from']) !== $senderDomain) {
                // Our From said the sender's domain and Cloudflare judged a different one.
                $failures[] = 'evidence_header_from_mismatch';
            }
        }

        return $failures;
    }

    /**
     * What a match still needs from one claim: DMARC passed for exactly the sender's domain, and a
     * DKIM signature passed for it. SPF alone never matches, because it survives a forged From.
     *
     * @param  list<array{method: string, result: string, properties: array<string, string>}>  $results
     * @return list<string>
     */
    private function claimDoubts(array $results, string $senderDomain): array
    {
        $dmarc = array_filter($results, static fn (array $result): bool => $result['method'] === 'dmarc');
        $dkim = array_filter($results, static fn (array $result): bool => $result['method'] === 'dkim');
        $doubts = [];

        if ($dmarc === []) {
            $doubts[] = 'evidence_dmarc_absent';
        } elseif (array_filter($dmarc, static fn (array $result): bool => $result['result'] !== 'pass') !== []) {
            $doubts[] = 'evidence_dmarc_not_pass';
        } elseif (array_filter($dmarc, static fn (array $result): bool => ! isset($result['properties']['header.from'])) !== []) {
            $doubts[] = 'evidence_header_from_missing';
        }

        $alignedPass = array_filter($dkim, static fn (array $result): bool => $result['result'] === 'pass' && self::dkimIsAligned($result, $senderDomain));
        if ($alignedPass === []) {
            $doubts[] = 'dkim_not_aligned';
        }
        // A failed signature that does not say whose it was could be the sender's.
        if (array_filter($dkim, static fn (array $result): bool => $result['result'] === 'fail' && ! isset($result['properties']['header.d']) && ! isset($result['properties']['header.i'])) !== []) {
            $doubts[] = 'evidence_dkim_fail_unattributed';
        }

        return $doubts;
    }

    /** @param array{method: string, result: string, properties: array<string, string>} $result */
    private static function dkimIsAligned(array $result, string $senderDomain): bool
    {
        foreach (['header.d', 'header.i'] as $property) {
            if (isset($result['properties'][$property]) && self::isAligned(self::domainOf($result['properties'][$property]), $senderDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $sources
     * @return array{0: string, 1: list<string>}
     */
    private function judgeEnvelope(mixed $envelopeFrom, string $senderDomain, array $sources): array
    {
        if (! is_string($envelopeFrom) || trim($envelopeFrom) === '') {
            return [self::EVIDENCE_INCONCLUSIVE, ['envelope_from_missing']];
        }

        return self::isAligned(self::domainOf($envelopeFrom), $senderDomain)
            ? [self::EVIDENCE_PASS, array_map(static fn (string $source): string => 'authenticated_by_'.$source, $sources)]
            : [self::EVIDENCE_INCONCLUSIVE, ['envelope_from_not_aligned']];
    }

    /**
     * RFC 8601 results of a header claiming Cloudflare, tolerant of comments, whitespace and missing
     * semicolons. Quoted values stay values, so reason="dmarc=pass" can never be read as a result. A
     * header too damaged to read counts as a claim when it mentions Cloudflare at all, matching the Worker.
     *
     * @return list<array{method: string, result: string, properties: array<string, string>}>|string
     *                                                                                               The results, or a code saying why there are none
     */
    private function readClaim(string $header, bool $isArc, string $authservId): array|string
    {
        $text = self::stripComments($header);
        if ($text === null) {
            return stripos($header, $authservId) === false ? self::HEADER_FOREIGN : self::CLAIM_UNPARSEABLE;
        }
        if ($isArc) {
            $text = (string) preg_replace('/^\s*i\s*=\s*\d+\s*;/i', '', $text);
        }
        if (preg_match('/^\s*([^\s;]+)(.*)$/s', $text, $parts) !== 1
            || strtolower((string) preg_replace('/^"|"$/', '', $parts[1])) !== strtolower($authservId)) {
            return self::HEADER_FOREIGN;
        }

        preg_match_all(
            '/([A-Za-z0-9_.\-\/]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^\s;"]*)/s',
            $parts[2],
            $pairs,
            PREG_SET_ORDER,
        );

        $methods = [];
        /** @var array<int, array<string, string>> $properties */
        $properties = [];
        foreach ($pairs as [, $key, $value]) {
            $key = strtolower($key);
            $value = self::unquote($value);
            if (str_contains($key, '.')) {
                if ($methods !== []) {
                    $properties[count($methods) - 1][$key] ??= strtolower($value);
                }
            } elseif ($key !== 'reason' && $key !== 'action') {
                $methods[] = ['method' => explode('/', $key)[0], 'result' => strtolower($value)];
            }
        }

        $results = [];
        foreach ($methods as $index => $method) {
            $results[] = [...$method, 'properties' => $properties[$index] ?? []];
        }

        return $results === [] ? self::CLAIM_NO_RESULTS : $results;
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
