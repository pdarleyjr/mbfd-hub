<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Transport;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterOutlook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** TeleStaff's 7-day outlook mail: Roster_Day_N.xlsx from the same sender, with the same subject. */
final class RosterOutlookTransportTest extends TestCase
{
    use RefreshDatabase;
    use SendsRosterMail;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        config()->set('daily_roster.mode', 'publish');
    }

    public function test_with_the_outlook_off_its_mail_is_rejected_exactly_as_before(): void
    {
        $mail = $this->rosterMail(['attachments' => [$this->workbookAttachment('Roster_Day_3.xlsx')]]);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_REJECTED, $import->state);
        self::assertSame(['near_miss', 'filename_mismatch'], $import->classification['reasons']);
        self::assertNull($import->source);
        Queue::assertNothingPushed();
    }

    public function test_with_the_outlook_on_each_day_is_queued_as_outlook_mail(): void
    {
        config()->set('daily_roster.outlook.mode', 'shadow');

        foreach ([1, 4, 7] as $day) {
            $mail = $this->rosterMail(['attachments' => [$this->workbookAttachment("Roster_Day_{$day}.xlsx")]]);
            $this->deliverMail($mail)->assertCreated();

            $import = $this->ledgerFor($mail['message_id']);
            self::assertSame(RosterImport::STATE_QUEUED, $import->state);
            self::assertSame(RosterOutlook::SOURCE, $import->source);
            self::assertSame('match', $import->classification['verdict']);
            self::assertSame("Roster_Day_{$day}.xlsx", $import->filename);
        }

        Queue::assertPushed(ProcessRosterImport::class, 3);
    }

    public function test_with_the_outlook_on_the_5_minute_export_is_untouched(): void
    {
        config()->set('daily_roster.outlook.mode', 'publish');
        $mail = $this->rosterMail();

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertNull($import->source);
        self::assertSame('Dailyroster.xlsx', $import->filename);
    }

    public function test_an_outlook_name_outside_the_seven_days_is_still_a_near_miss(): void
    {
        config()->set('daily_roster.outlook.mode', 'publish');
        $mail = $this->rosterMail(['attachments' => [$this->workbookAttachment('Roster_Day_8.xlsx')]]);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_REJECTED, $import->state);
        self::assertNull($import->source);
    }

    public function test_outlook_mail_must_pass_the_same_authentication_as_the_export(): void
    {
        config()->set('daily_roster.outlook.mode', 'publish');
        $mail = $this->rosterMail([
            'attachments' => [$this->workbookAttachment('Roster_Day_2.xlsx')],
            'authentication_evidence' => [
                'authentication_results' => 'mx.cloudflare.net; dkim=fail header.d=miamibeachfl.gov; spf=fail smtp.mailfrom=donotreply@miamibeachfl.gov; dmarc=fail header.from=miamibeachfl.gov',
                'arc_authentication_results' => null,
                'received_spf' => null,
                'cloudflare_claims' => ['authentication_results' => 1, 'arc_authentication_results' => 0],
                'truncated' => false,
            ],
        ]);

        $this->deliverMail($mail)->assertCreated();

        $import = $this->ledgerFor($mail['message_id']);
        self::assertSame(RosterImport::STATE_REJECTED, $import->state);
        self::assertSame(RosterOutlook::SOURCE, $import->source);
        Queue::assertNothingPushed();
    }
}
