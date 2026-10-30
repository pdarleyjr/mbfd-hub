<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster;

use App\Models\InboundEmail;
use App\Services\DailyRoster\TeleStaffMailClassification;
use App\Services\DailyRoster\TeleStaffMailClassifier;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'missing semicolons' => ['mx.cloudflare.net; dkim=pass header.d=miamibeachfl.gov spf=pass smtp.mailfrom=a@miamibeachfl.gov dmarc=pass'],
            'no header.from, dkim aligned' => [$prefix.self::DKIM.'; dmarc=pass'],
            'no header.from, spf aligned' => [$prefix.self::SPF.'; dmarc=pass'],
            'dkim aligned through header.i' => [$prefix.'dkim=pass header.i=@miamibeachfl.gov; '.self::DMARC],
            'dkim on a subdomain' => [$prefix.'dkim=pass header.d=mail.miamibeachfl.gov; '.self::DMARC],
            'spf on a subdomain' => [$prefix.'spf=pass smtp.mailfrom=bounce@mail.miamibeachfl.gov; '.self::DMARC],
            'spf passes while dkim fails' => [$prefix.'dkim=fail header.d=miamibeachfl.gov; '.self::SPF.'; '.self::DMARC],
            'dkim passes while spf fails' => [$prefix.self::DKIM.'; spf=fail smtp.mailfrom=a@miamibeachfl.gov; '.self::DMARC],
            'one of two signatures aligned' => [$prefix.'dkim=pass header.d=relay.example; '.self::DKIM.'; '.self::DMARC],
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
            'no results at all' => [$prefix.'none', 'authentication_results_unparseable'],
            'only an authserv-id' => ['mx.cloudflare.net', 'authentication_results_unparseable'],
            'nonsense' => ['not a header at all', 'authentication_results_foreign_authserv'],
            'unbalanced comment' => [$prefix.'dmarc=pass (oops', 'authentication_results_unparseable'],
            'stray closing parenthesis' => [$prefix.'dmarc=pass oops)', 'authentication_results_unparseable'],
            'unbalanced quote' => [$prefix.'dmarc=pass reason="oops', 'authentication_results_unparseable'],
            'another server wrote it' => ['mx.google.com; '.self::DKIM.'; '.self::DMARC, 'authentication_results_foreign_authserv'],
            'lookalike authserv' => ['mx.cloudflare.net.evil.example; '.self::DKIM.'; '.self::DMARC, 'authentication_results_foreign_authserv'],
            'dmarc none' => [$prefix.self::DKIM.'; dmarc=none header.from=miamibeachfl.gov', 'evidence_dmarc_not_pass'],
            'dmarc temperror' => [$prefix.self::DKIM.'; dmarc=temperror', 'evidence_dmarc_not_pass'],
            'dmarc absent' => [$prefix.self::DKIM, 'evidence_dmarc_absent'],
            'dmarc pass hidden in a comment' => [$prefix.'dkim=none (dmarc=pass header.from=miamibeachfl.gov)', 'evidence_dmarc_absent'],
            'dmarc pass hidden in a quoted reason' => [$prefix.'dkim=none reason="dmarc=pass header.from=miamibeachfl.gov"', 'evidence_dmarc_absent'],
            'dmarc pass without an identity' => [$prefix.'dmarc=pass header.from=miamibeachfl.gov', 'evidence_no_aligned_identity'],
            'dkim pass without a domain' => [$prefix.'dkim=pass; dmarc=pass', 'evidence_no_aligned_identity'],
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
            'passes come from other domains' => [$prefix.'dkim=pass header.d=evil.example; spf=pass smtp.mailfrom=a@evil.example; dmarc=pass', 'evidence_identity_not_aligned'],
            'lookalike dkim domain' => [$prefix.'dkim=pass header.d=miamibeachfl.gov.evil.example; dmarc=pass', 'evidence_identity_not_aligned'],
            'dkim and spf both fail with no dmarc' => [$prefix.'dkim=fail header.d=miamibeachfl.gov; spf=fail smtp.mailfrom=a@miamibeachfl.gov', 'evidence_dkim_spf_fail'],
            'spf softfail alone' => [$prefix.'spf=softfail smtp.mailfrom=a@miamibeachfl.gov', 'evidence_dkim_spf_fail'],
        ];
    }

    #[DataProvider('failingResults')]
    public function test_results_that_contradict_the_sender_are_rejected(string $results, string $reason): void
    {
        $classification = $this->classify(evidence: ['authentication_results' => $results]);

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
        self::assertContains($reason, $classification->reasons);
    }

    public function test_arc_results_are_the_fallback_when_authentication_results_are_absent(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => null,
            'arc_authentication_results' => 'i=2; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
        self::assertSame(['authenticated_by_arc_authentication_results'], $classification->reasons);
    }

    public function test_arc_results_are_the_fallback_when_another_server_wrote_the_top_header(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => 'mail.miamibeach.example; dmarc=none',
            'arc_authentication_results' => 'i = 1 ; mx.cloudflare.net; '.self::SPF.'; dmarc=pass',
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
    }

    public function test_arc_results_are_the_fallback_when_the_top_header_is_malformed(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => 'mx.cloudflare.net; dmarc=pass (oops',
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::MATCH, $classification->verdict);
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

    public function test_a_failure_from_cloudflare_is_never_overruled_by_arc(): void
    {
        $classification = $this->classify(evidence: [
            'authentication_results' => 'mx.cloudflare.net; dmarc=fail header.from=miamibeachfl.gov',
            'arc_authentication_results' => 'i=1; mx.cloudflare.net; '.self::DKIM.'; '.self::DMARC,
        ]);

        self::assertSame(TeleStaffMailClassification::REJECTED, $classification->verdict);
    }

    public function test_missing_evidence_is_held_and_says_which(): void
    {
        $classification = $this->classifyEmail($this->email(['transport_evidence' => null]));

        self::assertSame(TeleStaffMailClassification::NEEDS_REVIEW, $classification->verdict);
        self::assertSame(['authentication_results_missing', 'arc_authentication_results_missing'], $classification->reasons);
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

    /** @param array<string, ?string> $evidence */
    private function classify(array $evidence): TeleStaffMailClassification
    {
        return $this->classifyEmail($this->email([
            'transport_evidence' => array_replace([
                'envelope_from' => self::SENDER,
                'authentication_results' => 'mx.cloudflare.net; '.self::DKIM.'; '.self::SPF.'; '.self::DMARC,
                'arc_authentication_results' => null,
                'received_spf' => null,
            ], $evidence),
        ]));
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
