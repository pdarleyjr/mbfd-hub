<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\FuelIdNumbers;
use App\Models\Employee;
use App\Models\FuelIdEvent;
use App\Models\FuelIdRow;
use App\Models\FuelIdSource;
use App\Services\Personnel\FuelIdImporter;
use App\Services\Personnel\FuelIds\FuelIdImportException;
use App\Services\Personnel\FuelIds\FuelIdNameMatcher;
use App\Services\Personnel\FuelIds\FuelIdRowReader;
use App\Services\Personnel\FuelPinDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** Synthetic data only: no real fuel list values appear in this file. */
final class FuelIdTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        // A disk of its own, so concurrent suites faking 'local' cannot clear these files mid-test.
        config(['filesystems.private' => 'fuel-ids-test']);
        Storage::fake('fuel-ids-test');
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fuel-id-test-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function test_csv_import_keeps_leading_zeros_encrypts_the_pin_and_matches_id_and_last_name(): void
    {
        $rivera = $this->employee('90001', 'Alex Rivera');
        $lopez = $this->employee('90002', 'LOPEZ, MARIA');
        $path = $this->csv([
            ['090001', 'RIVERA', 'ALEX', '0042', 'Active', '1210'],
            ['90002', 'LOPEZ', 'MARIA', '7315', 'ACTIVE', ''],
        ]);

        $result = app(FuelIdImporter::class)->import($path, null, 'auto', 'list.csv');

        self::assertTrue($result->imported);
        self::assertSame(['matched' => 2, 'name_mismatch' => 0, 'conflict' => 0, 'unmatched' => 0], $result->plan->counts());
        self::assertSame('0042', app(FuelPinDirectory::class)->currentFor($rivera)?->pin);
        self::assertSame('7315', app(FuelPinDirectory::class)->currentFor($lopez)?->pin);
        self::assertStringNotContainsString('0042', (string) DB::table('fuel_id_rows')->where('employee_id', $rivera->id)->value('prompt_id'));
        self::assertArrayNotHasKey('prompt_id', FuelIdRow::query()->firstOrFail()->toArray());
        self::assertNull(FuelIdRow::query()->where('employee_id', $lopez->id)->value('department'));
    }

    public function test_csv_with_spreadsheet_stripped_zeros_is_rejected(): void
    {
        $this->employee('90001', 'Alex Rivera');
        $this->expectException(FuelIdImportException::class);
        $this->expectExceptionMessage('keep that column as text');

        app(FuelIdImporter::class)->import($this->csv([['90001', 'RIVERA', 'ALEX', '42', 'Active', '']]), null);
    }

    public function test_duplicate_source_employee_id_is_a_conflict_and_nobody_is_assigned(): void
    {
        $first = $this->employee('91111', 'Pat Henley');
        $this->employee('91112', 'Sam Vale');
        $result = app(FuelIdImporter::class)->import($this->jsonFile([
            $this->row('91111', 'HENLEY', 'PAT', '1357', '1210'),
            $this->row('91111', 'VALE', 'PAT', '2468', '1230'),
        ]), null, 'json');

        self::assertSame(2, $result->plan->counts()['conflict']);
        self::assertNull(app(FuelPinDirectory::class)->currentFor($first));
        self::assertSame(0, FuelIdRow::query()->whereNotNull('current_employee_id')->count());
        self::assertSame(0, FuelIdRow::query()->whereNotNull('employee_id')->count());
    }

    public function test_name_mismatch_and_unknown_ids_are_not_assigned_and_departed_matches_are_flagged(): void
    {
        $mismatch = $this->employee('92001', 'Jordan Blake');
        $departed = $this->employee('92002', 'Casey Morrow', 'departed');
        $result = app(FuelIdImporter::class)->import($this->jsonFile([
            $this->row('92001', 'STONE', 'JORDAN', '1111'),
            $this->row('92002', 'MORROW', 'CASEY', '2222'),
            $this->row('99999', 'NOBODY', 'NONE', '3333'),
        ]), null, 'json');

        self::assertSame(['matched' => 1, 'name_mismatch' => 1, 'conflict' => 0, 'unmatched' => 1], $result->plan->counts());
        self::assertNull(app(FuelPinDirectory::class)->currentFor($mismatch));
        self::assertSame($mismatch->id, FuelIdRow::query()->where('match_status', 'name_mismatch')->value('candidate_employee_id'));
        $view = app(FuelPinDirectory::class)->currentFor($departed);
        self::assertSame('2222', $view?->pin);
        self::assertTrue($view?->employeeDeparted);
    }

    public function test_reimporting_the_same_file_is_a_no_op(): void
    {
        $this->employee('93001', 'Robin Hale');
        $path = $this->jsonFile([$this->row('93001', 'HALE', 'ROBIN', '0909')]);
        app(FuelIdImporter::class)->import($path, null, 'json');
        $again = app(FuelIdImporter::class)->import($path, null, 'json');

        self::assertFalse($again->imported);
        self::assertTrue($again->plan->alreadyImported);
        self::assertSame(1, FuelIdSource::query()->count());
        self::assertSame(1, FuelIdRow::query()->count());
        self::assertSame(1, FuelIdEvent::query()->where('action', FuelIdEvent::IMPORTED)->count());
    }

    public function test_ambiguous_reimport_never_overwrites_and_a_clean_value_supersedes(): void
    {
        $employee = $this->employee('94001', 'Drew Quinn');
        $importer = app(FuelIdImporter::class);
        $importer->import($this->jsonFile([$this->row('94001', 'QUINN', 'DREW', '1111')], 'a'), null, 'json');
        $importer->import($this->jsonFile([
            $this->row('94001', 'QUINN', 'DREW', '2222'),
            $this->row('94001', 'OTHER', 'PERSON', '3333'),
        ], 'b'), null, 'json');
        $importer->import($this->jsonFile([$this->row('94001', 'WRONGNAME', 'DREW', '4444')], 'c'), null, 'json');

        self::assertSame('1111', app(FuelPinDirectory::class)->currentFor($employee)?->pin);

        $importer->import($this->jsonFile([$this->row('94001', 'QUINN', 'DREW', '5555')], 'd'), null, 'json');
        self::assertSame('5555', app(FuelPinDirectory::class)->currentFor($employee)?->pin);
        $old = FuelIdRow::query()->where('match_status', FuelIdRow::SUPERSEDED)->where('employee_id', $employee->id)->firstOrFail();
        self::assertSame('1111', $old->prompt_id);
        self::assertNull($old->current_employee_id);
        self::assertSame(1, FuelIdRow::query()->where('current_employee_id', $employee->id)->count());
    }

    public function test_command_imports_json_with_a_pdf_source_record_and_never_prints_a_pin(): void
    {
        $this->employee('95001', 'Taylor Reyes');
        $json = $this->jsonFile([$this->row('95001', 'REYES', 'TAYLOR', '0718'), $this->row('95999', 'UNKNOWN', 'LEE', '0606')]);
        $pdf = $this->fakePdf();

        self::assertSame(0, Artisan::call('personnel:fuel-ids-import', ['path' => $json, '--format' => 'json', '--dry-run' => true]));
        self::assertSame(0, FuelIdSource::query()->count());
        $dryRun = Artisan::output();

        self::assertSame(0, Artisan::call('personnel:fuel-ids-import', ['path' => $json, '--format' => 'json', '--source-pdf' => $pdf]));
        $output = $dryRun.Artisan::output();
        foreach (['0718', '0606'] as $pin) {
            self::assertStringNotContainsString($pin, $output);
        }
        self::assertStringContainsString('95999', $output);
        self::assertStringContainsString('••••', $output);

        $source = FuelIdSource::query()->sole();
        self::assertSame('application/pdf', $source->mime_type);
        self::assertSame('json', $source->format);
        self::assertSame(hash_file('sha256', $pdf), $source->sha256);
        Storage::disk($source->disk)->assertExists($source->path);

        self::assertSame(0, Artisan::call('personnel:fuel-ids-import', ['path' => $json, '--format' => 'json']));
        self::assertStringContainsString('already been imported', Artisan::output());
        self::assertSame(1, FuelIdSource::query()->count());
    }

    public function test_member_sees_only_their_own_pin_on_the_portal_home_page(): void
    {
        $this->withoutVite();
        $member = $this->employee('96001', 'Morgan Ellis');
        $other = $this->employee('96002', 'Riley Shaw');
        $none = $this->employee('96003', 'Avery Brooks');
        app(FuelIdImporter::class)->import($this->jsonFile([
            $this->row('96001', 'ELLIS', 'MORGAN', '0731'),
            $this->row('96002', 'SHAW', 'RILEY', '8264'),
        ]), null, 'json');

        $html = $this->actingAs($member, 'employee')->get('/employee/dashboard')->assertOk()
            ->assertSee('My details')->assertSee('Fuel PIN')
            ->assertSee('translate="no">0731</dd>', false)
            ->getContent();
        self::assertStringNotContainsString('>8264<', $html);
        self::assertStringNotContainsString('translate="no">8264', $html);
        self::assertStringNotContainsString('data-fuel', $html);

        $this->actingAs($none, 'employee')->get('/employee/dashboard')->assertOk()
            ->assertSee('Not on file')->assertDontSee('>0731<', false)->assertDontSee('>8264<', false);
    }

    public function test_every_administrator_opens_the_page_but_a_non_admin_account_does_not(): void
    {
        $source = $this->importWithPdf();
        // Fuel PINs are department public record (owner, 2026-11-06): any Hub administrator sees them.
        $this->actingAsAdminWith(['personnel.documents.view']);
        Livewire::test(FuelIdNumbers::class)->assertOk();
        self::assertTrue(FuelIdNumbers::canAccess());
        $this->get(route('personnel.fuel-ids.sources.view', $source))->assertOk();

        // An account without Hub administration access opens nothing, whatever it holds.
        $member = \App\Models\User::factory()->create(['account_status' => 'active']);
        $member->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('personnel.fuel_ids.view', 'web'));
        $this->actingAs($member);
        $this->get(route('personnel.fuel-ids.sources.view', $source))->assertForbidden();
        Livewire::test(FuelIdNumbers::class)->assertForbidden();
    }

    public function test_viewer_streams_the_source_inline_with_restrictive_headers(): void
    {
        $source = $this->importWithPdf();
        $this->actingAsAdminWith(['personnel.fuel_ids.view']);

        $response = $this->get(route('personnel.fuel-ids.sources.view', $source))->assertOk();
        self::assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringStartsWith('%PDF-', $response->streamedContent());
        self::assertStringStartsWith('attachment', (string) $this->get(route('personnel.fuel-ids.sources.download', $source))->headers->get('Content-Disposition'));
        self::assertSame(1, FuelIdEvent::query()->where('action', FuelIdEvent::SOURCE_VIEWED)->count());
    }

    public function test_administrators_see_every_pin_plainly_with_leading_zeros(): void
    {
        $this->employee('97001', 'Jamie Cole');
        app(FuelIdImporter::class)->import($this->jsonFile([$this->row('97001', 'COLE', 'JAMIE', '0465')]), null, 'json');
        $this->actingAsAdminWith([]);

        Livewire::test(FuelIdNumbers::class)->assertOk()
            ->assertSee('Jamie Cole')->assertSee('0465')->assertDontSee('••••')
            ->assertActionHidden('import');
    }

    public function test_manager_resolves_a_conflict_with_a_required_note_and_viewer_cannot(): void
    {
        $henley = $this->employee('98001', 'Pat Henley');
        app(FuelIdImporter::class)->import($this->jsonFile([
            $this->row('98001', 'HENLEY', 'PAT', '1212', '1210'),
            $this->row('98001', 'VALE', 'PAT', '3434', '1230'),
        ]), null, 'json');
        $rows = FuelIdRow::query()->orderBy('row_number')->get();

        $this->actingAsAdminWith(['personnel.fuel_ids.view']);
        Livewire::test(FuelIdNumbers::class)->assertActionHidden('resolve')->assertActionHidden('dismiss');

        $manager = $this->actingAsAdminWith(['personnel.fuel_ids.manage']);
        Livewire::test(FuelIdNumbers::class)
            ->callAction('resolve', data: ['employee_id' => $henley->id, 'note' => ''], arguments: ['row' => $rows[0]->id])
            ->assertHasActionErrors(['note' => 'required']);
        self::assertNull(app(FuelPinDirectory::class)->currentFor($henley));

        Livewire::test(FuelIdNumbers::class)
            ->callAction('resolve', data: ['employee_id' => $henley->id, 'note' => 'Confirmed with the fuel coordinator.'], arguments: ['row' => $rows[0]->id])
            ->assertHasNoActionErrors()
            ->callAction('dismiss', data: ['note' => 'Not a department member.'], arguments: ['row' => $rows[1]->id])
            ->assertHasNoActionErrors();

        self::assertSame('1212', app(FuelPinDirectory::class)->currentFor($henley)?->pin);
        $resolved = $rows[0]->refresh();
        self::assertSame(FuelIdRow::RESOLVED, $resolved->match_status);
        self::assertSame($manager->id, $resolved->resolved_by_user_id);
        self::assertSame(FuelIdRow::DISMISSED, $rows[1]->refresh()->match_status);
        self::assertSame(1, FuelIdEvent::query()->where('action', FuelIdEvent::RESOLVED)->count());
    }

    public function test_last_name_agreement_ignores_case_accents_apostrophes_and_spacing(): void
    {
        self::assertTrue(FuelIdNameMatcher::lastNameAgrees('DE LA CRUZ', 'Juan Delacruz'));
        self::assertTrue(FuelIdNameMatcher::lastNameAgrees('DELACRUZ', 'Juan De La Cruz'));
        self::assertTrue(FuelIdNameMatcher::lastNameAgrees("O'NEIL", 'Sean O’Neil'));
        self::assertTrue(FuelIdNameMatcher::lastNameAgrees('PENA', 'José Peña Jr.'));
        self::assertTrue(FuelIdNameMatcher::lastNameAgrees('SMITH-JONES', 'SMITH JONES, ANN'));
        self::assertFalse(FuelIdNameMatcher::lastNameAgrees('LEE', 'Kathleen Kleen'));
        self::assertFalse(FuelIdNameMatcher::lastNameAgrees('VIERA', 'Michelle Henley'));
        self::assertSame('16', FuelIdNameMatcher::normalizeEmployeeNumber(' 0016 '));
    }

    public function test_pdf_extractor_reads_a_synthetic_fuel_list_when_python_is_available(): void
    {
        $python = getenv('TEST_PYTHON_BINARY') ?: 'python3';
        $probe = new Process([$python, '-I', '-c', 'import fitz']);
        try {
            $probe->run();
        } catch (\Throwable) {
            self::markTestSkipped('Python is not available.');
        }
        if (! $probe->isSuccessful()) {
            self::markTestSkipped('Python with PyMuPDF is not available.');
        }
        config(['fuel-ids.python_binary' => $python]);
        $pdf = $this->tmp.DIRECTORY_SEPARATOR.'synthetic.pdf';
        $script = <<<'PY'
import sys, fitz
doc = fitz.open()
page = doc.new_page()
def put(y, cells):
    for x, text in zip((40, 120, 210, 330, 390, 470), cells):
        if text:
            page.insert_text((x, y), text, fontsize=9)
put(60, ("Employee", "Driver", "Driver", "Driver", "Status", "Driver"))
put(70, ("ID", "Last Name", "First Name", "Prompt ID", "Indicator", "Department"))
put(100, ("90001", "RIVERA", "ALEX", "0042", "Active", "1210"))
put(115, ("90002", "DE LA CRUZ", "ANA MARIA", "7315", "ACTIVE", ""))
doc.save(sys.argv[1])
PY;
        $make = new Process([$python, '-I', '-c', $script, $pdf]);
        $make->mustRun();

        $rows = app(FuelIdRowReader::class)->read($pdf, 'pdf');

        self::assertCount(2, $rows);
        self::assertSame(['90001', 'RIVERA', 'ALEX', '0042', 'Active', '1210', 1], [$rows[0]->employeeNumber, $rows[0]->lastName, $rows[0]->firstName, $rows[0]->promptId, $rows[0]->status, $rows[0]->department, $rows[0]->page]);
        self::assertSame(['DE LA CRUZ', 'ANA MARIA', '7315', null], [$rows[1]->lastName, $rows[1]->firstName, $rows[1]->promptId, $rows[1]->department]);
    }

    private function importWithPdf(): FuelIdSource
    {
        $this->employee('99001', 'Quinn Parker');
        app(FuelIdImporter::class)->import($this->jsonFile([$this->row('99001', 'PARKER', 'QUINN', '5150')]), null, 'json', null, $this->fakePdf(), 'fuel-list.pdf');

        return FuelIdSource::query()->sole();
    }

    private function employee(string $employeeId, string $name, ?string $rosterStatus = null): Employee
    {
        return Employee::query()->create(array_filter([
            'employee_id' => $employeeId,
            'name' => $name,
            'rank' => 'Firefighter',
            'roster_status' => $rosterStatus,
            'password' => bcrypt(str()->random(40)),
            'must_change_password' => false,
        ], fn ($value): bool => $value !== null));
    }

    /** @return array<string, string|int|null> */
    private function row(string $employeeId, string $last, string $first, string $pin, ?string $department = null): array
    {
        return ['page' => 1, 'employee_id' => $employeeId, 'last_name' => $last, 'first_name' => $first,
            'prompt_id' => $pin, 'status' => 'Active', 'department' => $department];
    }

    /** @param list<array<string, string|int|null>> $rows */
    private function jsonFile(array $rows, string $suffix = ''): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'rows'.$suffix.'.json';
        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'list.csv';
        $handle = fopen($path, 'wb');
        fputcsv($handle, ['Employee ID', 'Driver Last Name', 'Driver First Name', 'Driver Prompt ID', 'Status Indicator', 'Driver Department'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }

    private function fakePdf(): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'source-'.bin2hex(random_bytes(3)).'.pdf';
        file_put_contents($path, "%PDF-1.4\n% synthetic fuel list placeholder\n%%EOF\n");

        return $path;
    }
}
