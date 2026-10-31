<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Enums\AccountStatus;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smalot\PdfParser\Parser;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

final class RosterPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = '/daily-roster/pdf';

    private const NOW = '2026-10-30 10:30:00';

    private const TODAY = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
    }

    public function test_it_downloads_the_entire_roster_as_a_pdf_with_every_section_and_person(): void
    {
        $sections = [];
        for ($station = 1; $station <= 21; $station++) {
            $sections["Station {$station} / Engine {$station}"] = [
                ['employee_id' => (string) (70000 + $station), 'name' => "EXPORTED{$station}, MEMBER", 'code' => 'SP', 'seat' => 0],
                ['employee_id' => (string) (71000 + $station), 'name' => "COVER{$station}, MEMBER", 'code' => '+OT', 'seat' => 1],
            ];
        }
        DailyRosterSnapshotFactory::publish(self::TODAY, $sections, ['generated_at' => CarbonImmutable::now()]);
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PDF)->assertOk();

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=MBFD-Daily-Roster-2026-10-30.pdf', str_replace('"', '', (string) $response->headers->get('Content-Disposition')));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());

        $text = (new Parser)->parseContent((string) $response->getContent())->getText();
        for ($station = 1; $station <= 21; $station++) {
            self::assertStringContainsString("Station {$station} / Engine {$station}", $text);
            self::assertStringContainsString("EXPORTED{$station}, MEMBER", $text);
            self::assertStringContainsString("COVER{$station}, MEMBER", $text);
        }
    }

    public function test_it_states_what_day_it_is_and_when_it_was_updated_in_miami_beach_time(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $text = $this->pdfText();

        self::assertStringContainsString('Friday, October 30', $text);
        self::assertStringContainsString('Oct 30, 10:30 AM EDT', $text);
        self::assertStringContainsString('B Shift', $text);
    }

    public function test_it_uses_telestaff_colors_and_prints_the_code_verbatim(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [
            ['employee_id' => '90001', 'name' => 'LEAVE, MEMBER', 'code' => 'ADMS', 'category' => 'Leave', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
        ]], ['generated_at' => CarbonImmutable::now()]);
        $this->actingAsCanonicalFixture();

        $html = view('daily-roster.pdf', app(\App\Services\DailyRoster\DailyRosterPagePresenter::class)->pdfData(null))->render();

        self::assertStringContainsString('background-color: #E60000', $html);
        self::assertStringContainsString('color: #FFFFFF', $html);
        self::assertStringContainsString('>ADMS<', $html);
    }

    public function test_the_exchange_mark_never_prints_as_an_empty_box_and_the_file_stays_small(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [
            ['employee_id' => '90001', 'name' => 'EXCHANGE, MEMBER', 'code' => "\u{2927}2WXON", 'base_code' => '2WXON', 'category' => 'Exchange'],
        ]], ['generated_at' => CarbonImmutable::now()]);
        $this->actingAsCanonicalFixture();

        $html = view('daily-roster.pdf', app(\App\Services\DailyRoster\DailyRosterPagePresenter::class)->pdfData(null))->render();
        self::assertStringNotContainsString("\u{2927}", $html);
        self::assertStringContainsString("\u{21C4}2WXON", $html);

        self::assertLessThan(400_000, strlen((string) $this->get(self::PDF)->assertOk()->getContent()));
    }

    public function test_cell_text_cannot_inject_markup_into_the_pdf(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / <b>Engine 1</b>' => [
            ['employee_id' => '90001', 'name' => '<script>alert(1)</script>, MEMBER'],
        ]], ['generated_at' => CarbonImmutable::now()]);
        $this->actingAsCanonicalFixture();

        $html = view('daily-roster.pdf', app(\App\Services\DailyRoster\DailyRosterPagePresenter::class)->pdfData(null))->render();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Engine', $html);
    }

    public function test_an_earlier_date_exports_that_date(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '90002', 'name' => 'YESTERDAY, MEMBER']]]);
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PDF.'?date=2026-10-29')->assertOk();

        self::assertStringContainsString('MBFD-Daily-Roster-2026-10-29.pdf', (string) $response->headers->get('Content-Disposition'));
        $text = (new Parser)->parseContent((string) $response->getContent())->getText();
        self::assertStringContainsString('YESTERDAY, MEMBER', $text);
        self::assertStringNotContainsString('ALPHA, MEMBER', $text);
    }

    public function test_the_last_good_roster_carries_its_honest_warning_into_the_pdf(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '90002', 'name' => 'YESTERDAY, MEMBER']]]);
        $this->actingAsCanonicalFixture();

        $text = $this->pdfText();

        self::assertStringContainsString("Today's roster has not arrived", str_replace(['’'], "'", $text));
        self::assertStringContainsString('YESTERDAY, MEMBER', $text);
    }

    public function test_with_nothing_published_there_is_nothing_to_export(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get(self::PDF)->assertNotFound();
        $this->get('/daily-roster')->assertOk()->assertDontSee('data-roster-export', false);
    }

    public function test_the_page_links_to_the_export_for_the_date_on_screen(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '90002', 'name' => 'YESTERDAY, MEMBER']]]);
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $this->get('/daily-roster')->assertOk()->assertSee('data-roster-export', false)->assertSee('href="'.url(self::PDF).'"', false);
        $this->get('/daily-roster?date=2026-10-29')->assertOk()->assertSee('href="'.url(self::PDF).'?date=2026-10-29"', false);
    }

    public function test_it_is_refused_like_the_rest_of_the_roster(): void
    {
        $this->publishRoster();

        $this->get(self::PDF)->assertRedirect('/login');

        $this->actingAsCanonicalUser(User::factory()->create(['account_status' => AccountStatus::Active]));
        $this->get(self::PDF)->assertForbidden();
    }

    public function test_a_member_cannot_run_up_the_server_by_asking_repeatedly(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $last = null;
        for ($request = 0; $request < 11; $request++) {
            $last = $this->get(self::PDF);
        }

        $last->assertStatus(429);
    }

    public function test_ordinary_use_of_the_hub_never_spends_the_export_limit(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        for ($request = 0; $request < 15; $request++) {
            $this->get('/daily-roster/sections')->assertOk();
        }

        $this->get(self::PDF)->assertOk();
    }

    private function publishRoster(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, [
            'Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'ALPHA, MEMBER', 'code' => 'SP']],
        ], ['generated_at' => CarbonImmutable::now()]);
    }

    private function pdfText(): string
    {
        return (new Parser)->parseContent((string) $this->get(self::PDF)->assertOk()->getContent())->getText();
    }
}
