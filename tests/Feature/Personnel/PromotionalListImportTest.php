<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\PromotionalListEvent;
use App\Services\Personnel\Promotions\EmployeeNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** personnel:import-promotional-list with a synthetic CSV (fake names only). */
final class PromotionalListImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    private const HEADER = 'original_rank,soi_id,last_name,first_name,written,oral,composite,seniority,education,max_promotional,veteran,total';

    protected function setUp(): void
    {
        parent::setUp();
        $this->csv = tempnam(sys_get_temp_dir(), 'promo-').'.csv';
        file_put_contents($this->csv, implode("\n", [
            '# Transcription of a fixture list. Rank 1 redacted in source; rows after 3 not provided.',
            self::HEADER,
            '2,F31,Quigley,Jordan,82.00,p,82.00,1.00,5.00,6.00,20.00,108.00',
            '3,F22,Santoro,Casey,96.00,P,96.00,3.00,5.00,8.00,,104.00',
            '4,F4,Delacroix,Morgan,92.00,P,92.00,1.00,-,1.00,,93.00',
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    public function test_dry_run_reports_the_plan_and_writes_nothing(): void
    {
        $this->employee('Jordan Quigley');

        $this->artisan(...$this->command(false))
            ->expectsOutputToContain('Create 3, unchanged 0, conflicts 0')
            ->expectsOutputToContain('Dry run. Nothing was saved')
            ->assertSuccessful();

        self::assertSame(0, PromotionalList::query()->count());
        self::assertSame(0, PromotionalListEntry::query()->count());
    }

    public function test_apply_creates_the_list_with_values_exactly_as_printed_and_ranks_from_one(): void
    {
        $this->artisan(...$this->command(true))->assertSuccessful();

        $list = PromotionalList::query()->firstOrFail();
        self::assertSame('lieutenant', $list->list_type);
        self::assertSame('2025-04-01', $list->effective_date->toDateString());
        self::assertSame('2027-04-01', $list->expires_on->toDateString());
        self::assertSame('active', $list->status);
        self::assertSame('Fixture results document', $list->source_reference);
        $entries = $list->entries()->orderBy('original_rank')->get();
        self::assertSame([2, 3, 4], $entries->pluck('original_rank')->all());
        self::assertSame([1, 2, 3], $entries->pluck('current_rank')->all());
        self::assertSame('p', $entries[0]->scores['oral'], 'A lowercase p stays lowercase.');
        self::assertSame('20.00', $entries[0]->scores['veteran']);
        self::assertSame('', $entries[1]->scores['veteran']);
        self::assertSame('-', $entries[2]->scores['education']);
        self::assertSame('F31', $entries[0]->soi_id);
        $imported = PromotionalListEvent::query()->where('action', 'imported')->firstOrFail();
        self::assertSame(3, $imported->metadata['created']);
        self::assertSame(basename($this->csv), $imported->metadata['source_file']);
    }

    public function test_running_the_same_import_twice_changes_nothing(): void
    {
        $this->artisan(...$this->command(true))->assertSuccessful();
        $events = PromotionalListEvent::query()->count();

        $this->artisan(...$this->command(true))
            ->expectsOutputToContain('Create 0, unchanged 3, conflicts 0')->assertSuccessful();

        self::assertSame(1, PromotionalList::query()->count());
        self::assertSame(3, PromotionalListEntry::query()->count());
        self::assertSame($events + 1, PromotionalListEvent::query()->count(), 'Only the "imported" audit line is added.');
    }

    public function test_employees_link_only_on_an_exact_unique_active_name_match(): void
    {
        $exact = $this->employee('Jordan Quigley');
        $this->employee('Casey Santoro');
        $this->employee('Casey Santoro', 'retired');
        $this->employee('Casey Santoro');
        $this->employee('Morgan A. Delacroix');
        $this->employee('Morgen Delacroix');

        $this->artisan(...$this->command(true))->assertSuccessful();

        $byRank = PromotionalListEntry::query()->orderBy('original_rank')->get()->keyBy('original_rank');
        self::assertSame($exact->id, $byRank[2]->employee_id, 'One active exact match links.');
        self::assertNull($byRank[3]->employee_id, 'Two active people with that name are never guessed between.');
        self::assertNull($byRank[4]->employee_id, 'A middle initial or a similar spelling does not match.');
    }

    public function test_a_retired_only_match_is_left_unlinked(): void
    {
        $this->employee('Jordan Quigley', 'retired');

        $this->artisan(...$this->command(true))->assertSuccessful();

        self::assertNull(PromotionalListEntry::query()->where('original_rank', 2)->firstOrFail()->employee_id);
    }

    public function test_existing_entries_are_never_overwritten_and_a_difference_is_reported(): void
    {
        $this->artisan(...$this->command(true))->assertSuccessful();
        PromotionalListEntry::query()->where('original_rank', 3)->update(['status' => 'promoted', 'status_reason' => 'Promoted 2026-11-01', 'last_name' => 'Corrected']);

        $this->artisan(...$this->command(true))
            ->expectsOutputToContain('conflicts 1')->assertSuccessful();

        $entry = PromotionalListEntry::query()->where('original_rank', 3)->firstOrFail();
        self::assertSame('Corrected', $entry->last_name);
        self::assertSame('promoted', $entry->status);
    }

    public function test_a_late_roster_fix_links_an_existing_unlinked_entry_on_rerun(): void
    {
        $this->artisan(...$this->command(true))->assertSuccessful();
        $employee = $this->employee('Morgan Delacroix');

        $this->artisan(...$this->command(true))->assertSuccessful();

        self::assertSame($employee->id, PromotionalListEntry::query()->where('original_rank', 4)->firstOrFail()->employee_id);
    }

    public function test_bad_files_and_options_are_refused_without_writing(): void
    {
        file_put_contents($this->csv, "original_rank,last_name\n2,Quigley\n");
        $this->artisan(...$this->command(true))->expectsOutputToContain('header must be exactly')->assertFailed();

        file_put_contents($this->csv, self::HEADER."\n2,F1,Quigley,Jordan,1,P,1,1,1,1,,1\n2,F2,Santoro,Casey,1,P,1,1,1,1,,1\n");
        $this->artisan(...$this->command(true))->expectsOutputToContain('appears twice')->assertFailed();

        file_put_contents($this->csv, self::HEADER."\nx,F1,Quigley,Jordan,1,P,1,1,1,1,,1\n");
        $this->artisan(...$this->command(true))->expectsOutputToContain('original_rank must be a whole number')->assertFailed();

        $this->artisan(...$this->command(true, ['--type' => 'sergeant']))->assertFailed();
        $this->artisan(...$this->command(true, ['--status' => 'expired']))->assertFailed();

        self::assertSame(0, PromotionalList::query()->count());
    }

    public function test_name_matching_ignores_case_accents_and_order_but_nothing_looser(): void
    {
        self::assertSame(EmployeeNameMatcher::key('Rodríguez, José'), EmployeeNameMatcher::key('JOSE  rodriguez'));
        self::assertSame(EmployeeNameMatcher::key("Mary-Jane O'Neil"), EmployeeNameMatcher::key('maryjane oneil'));
        self::assertNotSame(EmployeeNameMatcher::key('Jon Smith'), EmployeeNameMatcher::key('John Smith'));
        self::assertNotSame(EmployeeNameMatcher::key('Jon A Smith'), EmployeeNameMatcher::key('Jon Smith'));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function command(bool $apply, array $overrides = []): array
    {
        return ['personnel:import-promotional-list', [...[
            'csv' => $this->csv, '--type' => 'lieutenant', '--title' => 'Fixture Lieutenant Results 2025', '--effective' => '2025-04-01',
            '--expires' => '2027-04-01', '--source' => 'Fixture results document', '--apply' => $apply,
        ], ...$overrides]];
    }

    private function employee(string $name, string $status = 'active'): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::query()->create(['employee_id' => sprintf('I%04d', $sequence), 'name' => $name, 'rank' => 'Firefighter', 'roster_status' => $status]);
    }
}
