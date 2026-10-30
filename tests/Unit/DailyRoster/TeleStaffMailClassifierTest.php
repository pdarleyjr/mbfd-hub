<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster;

use App\Models\InboundEmail;
use App\Services\DailyRoster\TeleStaffMailClassification;
use App\Services\DailyRoster\TeleStaffMailClassifier;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class TeleStaffMailClassifierTest extends TestCase
{
    private const SENDER = 'donotreply@miamibeachfl.gov';

    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const DKIM = 'dkim=pass header.d=miamibeachfl.gov';

    private const SPF = 'spf=pass smtp.mailfrom=donotreply@miamibeachfl.gov';

    private const DMARC = 'dmarc=pass header.from=miamibeachfl.gov';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('inbound-email-attachments/sample/workbook.xlsx', "PK\x03\x04workbook");
    }

    /** @return array<string, array{string}> */
    public static function passingResults(): array
    {
        $prefix = 'mx.cloudflare.net; ';

        return [
            'all three results' => [$prefix.self::DKIM.'; '.self::SPF.'; '.self::DMARC],
            'comments everywhere' => ['mx.cloudflare.net (cloudflare); dkim=pass (2048-bit key; signature verified) header.d=miamibeachfl.gov (signer); dmarc=pass (p=reject sp=reject dis=none) header.from=miamibeachfl.gov'],
            'nested comment' => [$prefix.'dkim=pass (outer (inner) still) header.d=miamibeachfl.gov; '.self::DMARC],
            'odd whitespace' => ["  mx.cloudflare.net ;\tdkim = pass   header.d = miamibeachfl.gov ;  dmarc=pass\theader.from=miamibeachfl.gov  "],
            'upper case' => ['MX.CLOUDFLARE.NET; DKIM=PASS HEADER.D=MIAMIBEACHFL.GOV; DMARC=PASS HEADER.FROM=MIAMIBEACHFL.GOV'],
            'authres version' => ['mx.cloudflare.net 1; '.self::DKIM.'; '.self::DMARC],
            'method versions' => [$prefix.'dkim/1=pass header.d=miamibeachfl.gov; dmarc/1=pass header.from=miamibeachfl.gov'],
            'quoted property values' => [$prefix.'dkim=pass header.d="miamibeachfl.gov"; dmarc=pass header.from="miamibeachfl.gov"'],
            'missing semicolons' => ['mx.cloudflare.net; dkim=pass header.d=miamibeachfl.gov spf=pass smtp.mailfrom=a@miamibeachfl.gov dmarc=pass header.from=miamibeachfl.gov'],
            'dkim aligned through header.i' => [$prefix.'dkim=pass header.i=@miamibeachfl.gov; '.self::DMARC],
            'dkim on a subdomain' => [$prefix.'dkim=pass header.d=mail.miamibeachfl.gov; '.self::DMARC],
            'spf on a subdomain beside dkim' => [$prefix.self::DKIM.'; spf=pass smtp.mailfrom=bounce@mail.miamibeachfl.gov; '.self::DMARC],
            'one of two signatures aligned' => [$prefix.'dkim=pass header.d=relay.example; '.self::DKIM.'; '.self::DMARC],
            'another domain signature failed' => [$prefix.'dkim=fail header.d=relay.example; '.self::DKIM.'; '.self::DMARC],
            'spf softfail beside aligned dkim' => [$prefix.self::DKIM.'; spf=softfail smtp.mailfrom=a@miamibeachfl.gov; '.self::DMARC],
            'arc and other methods ignored' => [$prefix.'arc=pass; compauth=pass reason=100; '.self::DKIM.'; '.self::DMARC],
        ];
    }

    #[DataProvider('passingResults')]
    public function test_authentication_results_that_pass(string $results): void
    {
        $classification = $this->classify(evidence: ['authentication_results' => $results]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict, implode(',', $classification->reasons));
        self::assertSame(['authenticated_by_authentication_results'], $classification->reasons);
    }

    /** @return array<string, array{string, string}> */
    public static function inconclusiveResults(): array
    {
        $prefix = 'mx.cloudflare.net; ';

        return [
            'no results at all' => [$prefix.'none', 'authentication_results_no_results'],
            'only an authserv-id' => ['mx.cloudflare.net', 'authentication_results_no_results'],
            'nonsense' => ['not a header at all', 'authentication_results_foreign_authserv'],
            'unbalanced comment' => [$prefix.'dmarc=pass (oops', 'authentication_results_unparseable'],
            'stray closing parenthesis' => [$prefix.'dmarc=pass oops)', 'authentication_results_unparseable'],
            'unbalanced quote' => [$prefix.'dmarc=pass reason="oops', 'authentication_results_unparseable'],
            'another server wrote it' => ['mx.google.com; '.self::DKIM.'; '.self::DMARC, 'authentication_results_foreign_authserv'],
            'lookalike authserv' => ['mx.cloudflare.net.evil.example; '.self::DKIM.'; '.self::DMARC, 'authentication_results_foreign_authserv'],
            'dmarc none' => [$prefix.self::DKIM.'; dmarc=none header.from=miamibeachfl.gov', 'evidence_dmarc_not_pass'],
            'dmarc temperror' => [$prefix.self::DKIM.'; dmarc=temperror', 'evidence_dmarc_not_pass'],
            'dmarc pass beside a non-pass' => [$prefix.self::DKIM.'; '.self::DMARC.'; dmarc=none', 'evidence_dmarc_not_pass'],
            'dmarc absent' => [$prefix.self::DKIM, 'evidence_dmarc_absent'],
            'dmarc pass hidden in a comment' => [$prefix.'dkim=none (dmarc=pass header.from=miamibeachfl.gov)', 'evidence_dmarc_absent'],
            'dmarc pass hidden in a quoted reason' => [$prefix.'dkim=none reason="dmarc=pass header.from=miamibeachfl.gov"', 'evidence_dmarc_absent'],
            'dmarc pass without header.from' => [$prefix.self::DKIM.'; dmarc=pass', 'evidence_header_from_missing'],
            'spf softfail alone' => [$prefix.'spf=softfail smtp.mailfrom=a@miamibeachfl.gov', 'evidence_dmarc_absent'],
            'dmarc pass without an identity' => [$prefix.self::DMARC, 'dkim_not_aligned'],
            'dkim pass without a domain' => [$prefix.'dkim=pass; '.self::DMARC, 'dkim_not_aligned'],
            'spf only, no dkim' => [$prefix.self::SPF.'; '.self::DMARC, 'dkim_not_aligned'],
            'dkim none beside spf' => [$prefix.'dkim=none; '.self::SPF.'; '.self::DMARC, 'dkim_not_aligned'],
            'passes come from other domains' => [$prefix.'dkim=pass header.d=evil.example; spf=pass smtp.mailfrom=a@evil.example; '.self::DMARC, 'dkim_not_aligned'],
            'lookalike dkim domain' => [$prefix.'dkim=pass header.d=miamibeachfl.gov.evil.example; '.self::DMARC, 'dkim_not_aligned'],
            'a failed signature naming nobody' => [$prefix.'dkim=fail; '.self::DKIM.'; '.self::DMARC, 'evidence_dkim_fail_unattributed'],
            'truncated result' => [$prefix.self::DKIM.'; dmarc=pa', 'evidence_dmarc_not_pass'],
        ];
    }

    #[DataProvider('inconclusiveResults')]
    public function test_results_that_cannot_prove_the_sender_are_held(string $results, string $reason): void
    {
        $classification = $this->classify(evidence: ['authentication_results' => $results]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertContains($reason, $classification->reasons);
    }

    /** @return array<string, array{string, string}> */
    public static function failingResults(): array
    {
        $prefix = 'mx.cloudflare.net; ';

        return [
            'dmarc fail' => [$prefix.'dkim=none; spf=fail smtp.mailfrom=a@evil.example; dmarc=fail header.from=miamibeachfl.gov', 'evidence_dmarc_fail'],
            'dmarc fail beside a pass' => [$prefix.self::DKIM.'; dmarc=pass; dmarc=fail', 'evidence_dmarc_fail'],
            'header.from is another domain' => [$prefix.'dkim=pass header.d=evil.example; dmarc=pass header.from=evil.example', 'evidence_header_from_mismatch'],
            'header.from only ends the same' => [$prefix.'dkim=pass header.d=notmiamibeachfl.gov; dmarc=pass header.from=notmiamibeachfl.gov', 'evidence_header_from_mismatch'],
            'header.from is a subdomain' => [$prefix.self::DKIM.'; dmarc=pass header.from=mail.miamibeachfl.gov', 'evidence_header_from_mismatch'],
            'spf fail beside everything passing' => [$prefix.self::DKIM.'; spf=fail smtp.mailfrom=a@miamibeachfl.gov; '.self::DMARC, 'evidence_spf_fail'],
            'sender domain signature fails beside spf' => [$prefix.'dkim=fail header.d=miamibeachfl.gov; '.self::SPF.'; '.self::DMARC, 'evidence_dkim_fail'],
            'sender domain signature fails beside a pass' => [$prefix.'dkim=fail header.d=miamibeachfl.gov; '.self::DKIM.'; '.self::DMARC, 'evidence_dkim_fail'],
            'subdomain signature fails through header.i' => [$prefix.'dkim=fail header.i=@mail.miamibeachfl.gov; '.self::DKIM.'; '.self::DMARC, 'evidence_dkim_fail'],
            'dkim and spf both fail with no dmarc' => [$prefix.'dkim=fail header.d=miamibeachfl.gov; spf=fail smtp.mailfrom=a@miamibeachfl.gov', 'evidence_spf_fail'],
        ];
    }

    #[DataProvider('failingResults')]
    public function test_results_that_contradict_the_sender_are_rejected(string $results, string $reason): void
    {
        $classification = $this->classify(evidence: ['authentication_results' => $results]);

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertContains($reason, $classification->reasons);
    }

    public function test_an_arc_claim_alone_can_match(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => null,
            'arc_authentication_results' => 'i=2; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
        self::assertSame(['authenticated_by_arc_authentication_results'], $classification->reasons);
    }

    public function test_an_arc_claim_matches_beside_another_servers_results(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => 'mail.miamibeach.example; dmarc=none',
            'arc_authentication_results' => 'i = 1 ; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
    }

    public function test_two_passing_claims_both_vouch(): void
    {
        $classification = $this->classify(evidence: [
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
        self::assertSame(['authenticated_by_authentication_results', 'authenticated_by_arc_authentication_results'], $classification->reasons);
    }

    public function test_arc_results_written_by_another_server_are_not_trusted(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => null,
            'arc_authentication_results' => 'i=1; mx.google.com; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_missing', 'arc_authentication_results_foreign_authserv'], $classification->reasons);
    }

    /** @return array<string, array{array<string, ?string>}> */
    public static function injectedPasses(): array
    {
        $pass = 'mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC;
        $fail = 'mx.cloudflare.net; dkim=none; spf=fail smtp.mailfrom=a@evil.example; dmarc=fail header.from=miamibeachfl.gov';

        return [
            'real failure in authentication results, injected pass in arc' => [['authentication_results' => $fail, 'arc_authentication_results' => 'i=1; '.$pass]],
            'real failure in arc, injected pass in authentication results' => [['authentication_results' => $pass, 'arc_authentication_results' => 'i=1; '.$fail]],
        ];
    }

    /** @param array<string, ?string> $evidence */
    #[DataProvider('injectedPasses')]
    public function test_a_failing_claim_is_never_overruled_by_a_passing_one(array $evidence): void
    {
        $classification = $this->classify(evidence: $evidence);

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertContains('evidence_dmarc_fail', $classification->reasons);
    }

    public function test_every_claim_must_pass_so_a_weaker_second_claim_holds_it(): void
    {
        $classification = $this->classify(evidence: [
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::SPF.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['dkim_not_aligned'], $classification->reasons);
    }

    public function test_an_unreadable_claim_holds_a_message_with_a_passing_one(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => 'mx.cloudflare.net; dmarc=pass (oops',
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_unparseable'], $classification->reasons);
    }

    public function test_more_than_one_claim_of_a_kind_holds_even_when_the_forwarded_one_passes(): void
    {
        $classification = $this->classify(evidence: [
            'cloudflare_claims' => ['authentication_results' => 2, 'arc_authentication_results' => 0],
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_multiple_claims'], $classification->reasons);
    }

    public function test_a_claim_the_worker_counted_but_did_not_forward_holds_the_message(): void
    {
        // A passing ARC claim, while Cloudflare's own results sit below a foreign header.
        $classification = $this->classify(evidence: [
            'authentication_results' => 'relay.example; dmarc=pass',
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
            'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 1],
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_claim_not_forwarded'], $classification->reasons);
    }

    public function test_a_forwarded_claim_the_worker_did_not_count_holds_the_message(): void
    {
        $classification = $this->classify(evidence: [
            'cloudflare_claims' => ['authentication_results' => 0, 'arc_authentication_results' => 0],
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_claim_count_mismatch'], $classification->reasons);
    }

    public function test_a_passing_claim_without_the_workers_counts_is_held(): void
    {
        $classification = $this->classify(evidence: ['cloudflare_claims' => null]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['cloudflare_claims_missing'], $classification->reasons);
    }

    public function test_truncated_evidence_is_held_even_when_the_rest_passes(): void
    {
        $classification = $this->classify(evidence: ['truncated' => true]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['evidence_truncated'], $classification->reasons);
    }

    public function test_truncated_evidence_with_no_claim_left_to_read_is_held(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => null,
            'truncated' => true,
            'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 0],
        ]);

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['evidence_truncated', 'authentication_results_claim_not_forwarded'], $classification->reasons);
    }

    /**
     * The same table is in cloudflare-email-worker/tests/envelope.test.ts: a header one side reads as a
     * claim and the other does not would let an injected claim go uncounted.
     *
     * @return array<string, array{string, bool, bool}>
     */
    public static function claimReadings(): array
    {
        return [
            'plain' => ['mx.cloudflare.net; dmarc=pass', false, true],
            'mixed case' => ['MX.CloudFlare.NET; dmarc=pass', false, true],
            'alone' => ['mx.cloudflare.net', false, true],
            'version' => ['mx.cloudflare.net 1; dmarc=pass', false, true],
            'spaces' => ['  mx.cloudflare.net ;dmarc=pass', false, true],
            'leading comment' => ['(by cloudflare) mx.cloudflare.net; dmarc=pass', false, true],
            'quoted' => ['"mx.cloudflare.net"; dmarc=pass', false, true],
            'unbalanced' => ['mx.cloudflare.net; dmarc=pass (oops', false, true],
            'unbalanced foreign mentioning it' => ['relay.example; x=(mx.cloudflare.net', false, true],
            'arc' => ['i=1; mx.cloudflare.net; dmarc=pass', true, true],
            'arc spaced' => ['i = 12 ; mx.cloudflare.net; dmarc=pass', true, true],
            'arc without an instance' => ['mx.cloudflare.net; dmarc=pass', true, true],
            'instance outside arc' => ['i=1; mx.cloudflare.net; dmarc=pass', false, false],
            'arc from another server' => ['i=1; relay.example; dmarc=pass', true, false],
            'named only in a comment' => ['relay.example; dmarc=pass (mx.cloudflare.net)', false, false],
            'longer domain' => ['mx.cloudflare.net.evil.example; dmarc=pass', false, false],
            'prefixed domain' => ['notmx.cloudflare.net; dmarc=pass', false, false],
            'comment before another server' => ['(mx.cloudflare.net) relay.example; dmarc=pass', false, false],
            'empty' => ['', false, false],
        ];
    }

    #[DataProvider('claimReadings')]
    public function test_a_header_reads_as_a_cloudflare_claim_the_same_as_in_the_worker(string $header, bool $isArc, bool $expected): void
    {
        $reading = (new ReflectionMethod(TeleStaffMailClassifier::class, 'readClaim'))->invoke(new TeleStaffMailClassifier, $header, $isArc, 'mx.cloudflare.net');

        self::assertSame($expected, $reading !== 'foreign_authserv');
    }

    public function test_the_classification_carries_the_evidence_for_the_ledger(): void
    {
        $classification = $this->classify(evidence: ['received_spf' => 'pass (mx.cloudflare.net)']);

        self::assertEquals([
            'envelope_from' => self::SENDER,
            'authentication_results' => 'mx.cloudflare.net; '.self::DKIM.'; '.self::SPF.'; '.self::DMARC,
            'arc_authentication_results' => null,
            'received_spf' => 'pass (mx.cloudflare.net)',
            'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 0],
            'truncated' => false,
        ], $classification->evidence);
        self::assertSame($classification->evidence, $classification->toLedger()['evidence']);
    }

    public function test_ordinary_mail_carries_no_evidence(): void
    {
        $classification = $this->classifyEmail($this->email(['from_address' => 'vendor@example.test', 'subject' => 'Quote', 'attachment_metadata' => null]));

        self::assertTrue($classification->isOrdinary());
        self::assertSame([], $classification->evidence);
    }

    public function test_missing_evidence_is_held_and_says_which(): void
    {
        $classification = $this->classifyEmail($this->email(['transport_evidence' => null]));

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_missing', 'arc_authentication_results_missing'], $classification->reasons);
        self::assertArrayNotHasKey('evidence', $classification->toLedger());
    }

    /** @return array<string, array{?string, string}> */
    public static function envelopes(): array
    {
        return [
            'same domain' => ['donotreply@miamibeachfl.gov', TeleStaffMailClassification::MATCH],
            'subdomain' => ['bounce@mail.miamibeachfl.gov', TeleStaffMailClassification::MATCH],
            'case folded' => ['DoNotReply@MiamiBeachFL.gov', TeleStaffMailClassification::MATCH],
            'missing' => [null, TeleStaffMailClassification::NEEDS_REVIEW],
            'other domain' => ['bounce@relay.example', TeleStaffMailClassification::NEEDS_REVIEW],
            'lookalike domain' => ['bounce@evilmiamibeachfl.gov', TeleStaffMailClassification::NEEDS_REVIEW],
        ];
    }

    #[DataProvider('envelopes')]
    public function test_the_envelope_sender_must_align(?string $envelope, string $verdict): void
    {
        $classification = $this->classify(evidence: ['envelope_from' => $envelope]);

        self::assertSame($verdict, $classification->verdict);
    }

    public function test_the_real_sample_shape_matches_with_folding_and_trim(): void
    {
        $classification = $this->classifyEmail($this->email([
            'from_address' => 'DoNotReply@MiamiBeachFL.gov',
            'subject' => '  roster report ',
            'attachment_metadata' => [$this->attachment(['filename' => 'DAILYROSTER.XLSX'])],
        ]));

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function ordinaryMail(): array
    {
        return [
            'unrelated sender' => [['from_address' => 'vendor@example.test', 'subject' => 'Quote', 'attachment_metadata' => null]],
            'unrelated with an xlsx' => [['from_address' => 'vendor@example.test', 'subject' => 'Quote']],
            'staff reply with another xlsx' => [['from_address' => 'staff@miamibeachfl.gov', 'subject' => 'Re: Test hub email', 'in_reply_to' => '<a@b.test>', 'attachment_metadata' => [['filename' => 'budget.xlsx', 'mime_type' => self::MIME, 'size' => 9, 'disk' => 'local', 'path' => 'missing.xlsx']]]],
            'staff with no attachment' => [['from_address' => 'staff@miamibeachfl.gov', 'subject' => 'Hello', 'attachment_metadata' => null]],
            'outsider using only the subject' => [['from_address' => 'vendor@example.test', 'attachment_metadata' => null]],
            'outsider using only the filename' => [['from_address' => 'vendor@example.test', 'subject' => 'Forwarded']],
            'subdomain staff with another xlsx' => [['from_address' => 'staff@fire.miamibeachfl.gov', 'subject' => 'Budget', 'attachment_metadata' => [['filename' => 'budget.xlsx', 'mime_type' => self::MIME, 'size' => 9, 'disk' => 'local', 'path' => 'missing.xlsx']]]],
            'the sender with an unrelated message' => [['subject' => 'System notice', 'attachment_metadata' => null]],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('ordinaryMail')]
    public function test_mail_that_is_not_the_export_is_ordinary(array $attributes): void
    {
        $classification = $this->classifyEmail($this->email($attributes));

        self::assertTrue($classification->isOrdinary());
        self::assertSame([], $classification->reasons);
    }

    public function test_the_fast_path_reads_no_files(): void
    {
        Storage::shouldReceive('disk')->never();

        $classification = $this->classifyEmail($this->email(['from_address' => 'vendor@example.test', 'subject' => 'Quote']));

        self::assertTrue($classification->isOrdinary());
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function nearMisses(): array
    {
        return [
            'wrong sender at the same domain' => [['from_address' => 'other@miamibeachfl.gov'], ['near_miss', 'sender_mismatch']],
            'staff using only the subject' => [['from_address' => 'other@miamibeachfl.gov', 'attachment_metadata' => null], ['near_miss', 'sender_mismatch', 'filename_mismatch']],
            'staff using only the filename' => [['from_address' => 'other@miamibeachfl.gov', 'subject' => 'Fwd: Roster Report'], ['near_miss', 'sender_mismatch', 'subject_mismatch']],
            'outsider using subject and filename' => [['from_address' => 'attacker@evil.example'], ['near_miss', 'sender_mismatch']],
            'sender and subject with another file' => [['attachment_metadata' => [['filename' => 'Other.xlsx', 'mime_type' => self::MIME, 'size' => 9, 'disk' => 'local', 'path' => 'inbound-email-attachments/sample/workbook.xlsx']]], ['near_miss', 'filename_mismatch']],
            'sender and subject with no file' => [['attachment_metadata' => null], ['near_miss', 'filename_mismatch']],
            'sender and filename with another subject' => [['subject' => 'Roster Report 2'], ['near_miss', 'subject_mismatch']],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $reasons
     */
    #[DataProvider('nearMisses')]
    public function test_near_misses_are_rejected_with_reasons(array $attributes, array $reasons): void
    {
        $classification = $this->classifyEmail($this->email($attributes));

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertSame($reasons, $classification->reasons);
    }

    public function test_the_sender_with_failing_evidence_is_rejected_whatever_it_sends(): void
    {
        $classification = $this->classifyEmail($this->email([
            'subject' => 'Please open this',
            'attachment_metadata' => null,
            'transport_evidence' => [
                'envelope_from' => 'attacker@evil.example',
                'authentication_results' => 'mx.cloudflare.net; dmarc=fail header.from=miamibeachfl.gov',
            ],
        ]));

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertSame(['evidence_dmarc_fail', 'subject_mismatch', 'filename_mismatch'], $classification->reasons);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function contradictions(): array
    {
        return [
            'a reply' => [['in_reply_to' => '<parent@sample.test>'], 'in_reply_to_present'],
            'threaded' => [['references' => ['<parent@sample.test>']], 'references_present'],
            'another recipient' => [['to_address' => 'other@mbfdhub.com'], 'recipient_mismatch'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('contradictions')]
    public function test_threaded_or_misaddressed_copies_are_rejected(array $attributes, string $reason): void
    {
        $classification = $this->classifyEmail($this->email($attributes));

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertSame([$reason], $classification->reasons);
    }

    /** @return array<string, array{list<array<string, mixed>>, string}> */
    public static function wrongShapes(): array
    {
        $good = ['filename' => 'Dailyroster.xlsx', 'mime_type' => self::MIME, 'size' => 12, 'disk' => 'local', 'path' => 'inbound-email-attachments/sample/workbook.xlsx'];

        return [
            'two attachments' => [[$good, ['filename' => 'other.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 'local', 'path' => 'x.pdf']], 'attachment_count_not_one'],
            'wrong mime' => [[['mime_type' => 'text/csv'] + $good], 'attachment_mime_mismatch'],
            'file gone' => [[['path' => 'inbound-email-attachments/sample/gone.xlsx'] + $good], 'attachment_file_missing'],
            'not a zip' => [[['path' => 'inbound-email-attachments/sample/not-zip.xlsx'] + $good], 'attachment_signature_invalid'],
            'empty file' => [[['path' => 'inbound-email-attachments/sample/empty.xlsx'] + $good], 'attachment_empty'],
            'too large' => [[['path' => 'inbound-email-attachments/sample/large.xlsx'] + $good], 'attachment_too_large'],
        ];
    }

    /** @param list<array<string, mixed>> $attachments */
    #[DataProvider('wrongShapes')]
    public function test_a_wrong_attachment_shape_is_held(array $attachments, string $reason): void
    {
        config()->set('daily_roster.workbook.max_bytes', 16);
        Storage::disk('local')->put('inbound-email-attachments/sample/not-zip.xlsx', 'plain text');
        Storage::disk('local')->put('inbound-email-attachments/sample/empty.xlsx', '');
        Storage::disk('local')->put('inbound-email-attachments/sample/large.xlsx', "PK\x03\x04".str_repeat('x', 20));

        $classification = $this->classifyEmail($this->email(['attachment_metadata' => $attachments]));

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertContains($reason, $classification->reasons);
    }

    /**
     * The Worker's counts follow the headers given, unless the test says otherwise.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function classify(array $evidence): TeleStaffMailClassification
    {
        $merged = array_replace([
            'envelope_from' => self::SENDER,
            'authentication_results' => 'mx.cloudflare.net; '.self::DKIM.'; '.self::SPF.'; '.self::DMARC,
            'arc_authentication_results' => null,
            'received_spf' => null,
            'truncated' => false,
        ], $evidence);

        return $this->classifyEmail($this->email([
            'transport_evidence' => [
                ...$merged,
                'cloudflare_claims' => array_key_exists('cloudflare_claims', $evidence) ? $evidence['cloudflare_claims'] : [
                    'authentication_results' => self::claimCount($merged['authentication_results'], false),
                    'arc_authentication_results' => self::claimCount($merged['arc_authentication_results'], true),
                ],
            ],
        ]));
    }

    private static function claimCount(?string $header, bool $isArc): int
    {
        $prefix = $isArc ? '(?:i\s*=\s*\d+\s*;)?' : '';

        return preg_match('/^\s*'.$prefix.'\s*mx\.cloudflare\.net(?=[\s;(]|$)/i', (string) $header) === 1 ? 1 : 0;
    }

    private function classifyEmail(InboundEmail $email): TeleStaffMailClassification
    {
        return (new TeleStaffMailClassifier)->classify($email);
    }

    /** @param array<string, mixed> $attributes */
    private function email(array $attributes = []): InboundEmail
    {
        return (new InboundEmail)->forceFill(array_replace([
            'provider_message_id' => '<1.2.3@sample.test>',
            'from_address' => self::SENDER,
            'to_address' => 'info@mbfdhub.com',
            'subject' => 'Roster Report',
            'in_reply_to' => null,
            'references' => [],
            'attachment_metadata' => [$this->attachment()],
            'transport_evidence' => [
                'envelope_from' => self::SENDER,
                'authentication_results' => 'mx.cloudflare.net; '.self::DKIM.'; '.self::SPF.'; '.self::DMARC,
                'arc_authentication_results' => null,
                'received_spf' => null,
                'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 0],
                'truncated' => false,
            ],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attachment(array $overrides = []): array
    {
        return array_replace([
            'filename' => 'Dailyroster.xlsx',
            'mime_type' => self::MIME,
            'size' => 12,
            'disk' => 'local',
            'path' => 'inbound-email-attachments/sample/workbook.xlsx',
        ], $overrides);
    }
}
