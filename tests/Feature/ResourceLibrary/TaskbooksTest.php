<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Filament\Resources\ResourceDocumentResource;
use App\Models\Employee;
use App\Models\ResourceDocument;
use App\Models\User;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibrarySearch;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TaskbooksTest extends TestCase
{
    use BuildsResourceLibraryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResourceLibrary();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->tearDownResourceLibrary();
        parent::tearDown();
    }

    public function test_the_page_and_private_streams_show_only_the_canonical_employees_taskbooks(): void
    {
        $owner = $this->actingAsCanonicalFixture('91001', 'First Officer');
        $other = $this->employee('91002', 'Second Officer');
        $pdf = $this->makePdf('first.pdf', 'First Officer taskbook', 16);
        $mine = $this->taskbook($owner->employeeProfile, 'first', $pdf);
        $theirs = $this->taskbook($other, 'second', $this->makePdf('second.pdf', 'Second Officer taskbook', 16));

        $this->get('/employee/taskbooks')->assertOk()
            ->assertSee('Taskbooks')->assertSee('First Officer taskbook')
            ->assertSee('16 pages')->assertSee(route('employee.taskbooks.preview', $mine->uuid), false)
            ->assertDontSee('Second Officer taskbook')->assertDontSee($theirs->uuid);

        foreach (['preview' => 'inline;', 'download' => 'attachment;'] as $action => $disposition) {
            $response = $this->get(route('employee.taskbooks.'.$action, $mine->uuid))->assertOk();
            self::assertSame(file_get_contents($pdf), $response->streamedContent());
            self::assertSame(hash_file('sha256', $pdf), hash('sha256', $response->streamedContent()));
            self::assertStringStartsWith($disposition, (string) $response->headers->get('Content-Disposition'));
            self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_other_members_and_shared_resource_routes_cannot_open_a_personal_taskbook(): void
    {
        $owner = $this->employee('91001', 'First Officer');
        $document = $this->taskbook($owner, 'first', $this->makePdf('first.pdf', 'Private taskbook', 16));
        $this->actingAsCanonicalFixture('91002', 'Second Officer');

        foreach (['preview', 'download'] as $action) {
            $this->get(route('employee.taskbooks.'.$action, $document->uuid))->assertNotFound();
            $this->get(route('employee.resources.'.$action, $document->uuid))->assertNotFound();
        }
        $this->get('/employee/taskbooks')->assertOk()->assertSee('No taskbooks yet')->assertDontSee('First Officer');
        $this->get('/employee/resources?q=Private')->assertOk()->assertDontSee('First Officer taskbook');
        self::assertSame([], app(ResourceLibrarySearch::class)->documents()->all());
        self::assertSame([], app(ResourceLibrarySearch::class)->categories());
        self::assertSame(0, ResourceDocumentResource::getEloquentQuery()->count(), 'Shared-library admin forms must not manage personal recipients.');
    }

    public function test_guests_and_unlinked_accounts_cannot_access_taskbooks(): void
    {
        $document = $this->taskbook($this->employee('91001', 'First Officer'), 'first', $this->makePdf('first.pdf', 'Private', 16));
        $this->get('/employee/taskbooks')->assertRedirect('/login');
        foreach (['preview', 'download'] as $action) {
            $url = route('employee.taskbooks.'.$action, $document->uuid);
            $this->get($url)->assertRedirect(route('login'));
            $this->getJson($url)->assertUnauthorized();
        }

        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        foreach (['preview', 'download'] as $action) {
            $this->get(route('employee.taskbooks.'.$action, $document->uuid))->assertForbidden();
        }
        self::assertNotSame(200, $this->get('/employee/taskbooks')->getStatusCode());
    }

    public function test_hidden_deleted_and_shared_documents_are_not_taskbooks(): void
    {
        $user = $this->actingAsCanonicalFixture('91001', 'First Officer');
        $hidden = $this->taskbook($user->employeeProfile, 'hidden', $this->makePdf('hidden.pdf', 'Hidden', 16), false);
        $deleted = $this->taskbook($user->employeeProfile, 'deleted', $this->makePdf('deleted.pdf', 'Deleted', 16));
        $deleted->delete();
        $shared = $this->importFixture('shared', 'Shared manual', 'Manuals', $this->makePdf('shared.pdf', 'Shared'));
        foreach ([$hidden, $deleted, $shared] as $document) {
            foreach (['preview', 'download'] as $action) {
                $this->get(route('employee.taskbooks.'.$action, $document->uuid))->assertNotFound();
            }
        }
        $this->get('/employee/taskbooks')->assertOk()->assertSee('No taskbooks yet');
    }

    public function test_import_resolves_existing_employees_and_is_idempotent_without_notifications(): void
    {
        Notification::fake();
        $employee = $this->employee('91001', 'First Officer');
        $pdf = $this->makePdf('first.pdf', 'First Officer personalized cover', 16);
        $manifest = $this->writeManifest([$this->entry($employee, 'first', $pdf)]);
        $this->importTaskbooks($manifest)->assertSuccessful();
        $document = ResourceDocument::query()->sole();
        self::assertSame($employee->id, $document->recipient_employee_id);
        self::assertSame(16, $document->page_count);
        self::assertSame(hash_file('sha256', $pdf), $document->original_sha256);
        self::assertSame(file_get_contents($pdf), Storage::disk('local')->get($document->original_path));
        self::assertSame(1, User::count(), 'Publishing does not create accounts.');

        $this->importTaskbooks($manifest)->expectsOutputToContain('unchanged')->assertSuccessful();
        self::assertSame(1, ResourceDocument::count());
        self::assertSame(1, $document->events()->count());
        self::assertCount(1, Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_import_preflights_every_identity_before_writing_anything(): void
    {
        $first = $this->employee('91001', 'First Officer');
        $second = $this->employee('91002', 'Second Officer');
        $good = $this->entry($first, 'first', $this->makePdf('first.pdf', 'First', 16));
        $bad = $this->entry($second, 'second', $this->makePdf('second.pdf', 'Second', 16));
        $bad['employee_name'] = 'Different Person';
        $this->importTaskbooks($this->writeManifest([$good, $bad]))->expectsOutputToContain('Employee name does not match')->assertFailed();
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_rejects_wrong_checksum_and_page_count_before_writes(): void
    {
        $employee = $this->employee('91001', 'First Officer');
        $entry = $this->entry($employee, 'first', $this->makePdf('first.pdf', 'First', 16));
        $entry['original']['sha256'] = str_repeat('a', 64);
        $this->importTaskbooks($this->writeManifest([$entry]))->expectsOutputToContain('Checksum mismatch')->assertFailed();

        $entry = $this->entry($employee, 'short', $this->makePdf('short.pdf', 'Too short', 15));
        $this->importTaskbooks($this->writeManifest([$entry]))->expectsOutputToContain('expected 16 pages')->assertFailed();
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_dry_run_validates_documents_without_writing(): void
    {
        $employee = $this->employee('91001', 'First Officer');
        $entry = $this->entry($employee, 'first', $this->makePdf('first.pdf', 'First', 16));
        $this->importTaskbooks($this->writeManifest([$entry]), ['--dry-run' => true])->assertSuccessful();
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_existing_slug_cannot_be_reassigned_or_converted_between_shared_and_personal(): void
    {
        $first = $this->employee('91001', 'First Officer');
        $second = $this->employee('91002', 'Second Officer');
        $pdf = $this->makePdf('first.pdf', 'First', 16);
        $document = $this->taskbook($first, 'first', $pdf);
        foreach ([$second->id, null] as $recipient) {
            try {
                app(ResourceLibraryService::class)->store(
                    ['slug' => 'first', 'title' => 'Changed', 'category' => 'Taskbooks', 'recipient_employee_id' => $recipient],
                    new ResourceFileInput($pdf, basename($pdf)),
                );
                self::fail('Changing a recipient must be rejected.');
            } catch (ResourceImportException $exception) {
                self::assertStringContainsString('different recipient', $exception->getMessage());
            }
        }
        self::assertSame($first->id, $document->fresh()->recipient_employee_id);
    }

    public function test_personal_documents_stay_hidden_from_legacy_shared_queries_even_if_an_admin_shows_them(): void
    {
        $employee = $this->employee('91001', 'First Officer');
        $document = $this->taskbook($employee, 'first', $this->makePdf('first.pdf', 'Private', 16));
        self::assertFalse($document->published);
        self::assertTrue($document->taskbook_published);
        $document->update(['published' => true]);
        self::assertFalse($document->fresh()->published);
        self::assertSame(0, ResourceDocument::query()->where('published', true)->count(), 'Old images only query this shared flag.');
    }

    public function test_the_storage_service_refuses_a_personal_pdf_with_the_wrong_page_count(): void
    {
        $this->expectException(ResourceImportException::class);
        $this->expectExceptionMessage('verified 16-page PDF');
        $this->taskbook($this->employee('91001', 'First Officer'), 'short', $this->makePdf('short.pdf', 'Short', 15));
    }

    public function test_the_recipient_cannot_be_removed_to_expose_a_taskbook_as_shared(): void
    {
        $document = $this->taskbook($this->employee('91001', 'First Officer'), 'first', $this->makePdf('first.pdf', 'Private', 16));
        $this->expectException(\LogicException::class);
        $document->update(['recipient_employee_id' => null, 'published' => true]);
    }

    public function test_the_database_rejects_legacy_admin_writes_that_try_to_share_personal_files(): void
    {
        $document = $this->taskbook($this->employee('91001', 'First Officer'), 'first', $this->makePdf('first.pdf', 'Private', 16));
        try {
            DB::transaction(fn () => DB::table('resource_documents')->where('id', $document->id)->update(['published' => true]));
            self::fail('An old image must not be able to publish personal files to the shared library.');
        } catch (QueryException) {
            self::assertFalse($document->fresh()->published);
            self::assertSame(0, ResourceDocument::query()->where('published', true)->count());
        }
    }

    private function employee(string $number, string $name): Employee
    {
        $employee = Employee::query()->create(['employee_id' => $number, 'name' => $name, 'rank' => 'Lieutenant']);
        User::factory()->create(['account_status' => AccountStatus::Active, 'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);

        return $employee;
    }

    private function taskbook(Employee $employee, string $slug, string $pdf, bool $published = true): ResourceDocument
    {
        return app(ResourceLibraryService::class)->store(
            ['slug' => $slug, 'title' => $employee->name.' taskbook', 'category' => 'Taskbooks', 'recipient_employee_id' => $employee->id, 'published' => $published],
            new ResourceFileInput($pdf, basename($pdf)),
        )->document;
    }

    /** @return array<string, mixed> */
    private function entry(Employee $employee, string $slug, string $pdf): array
    {
        return $this->manifestEntry($slug, $employee->name.' taskbook', 'Taskbooks', $pdf, extra: [
            'employee_number' => $employee->employee_id,
            'employee_name' => $employee->name,
            'page_count' => 16,
        ]);
    }

    private function importTaskbooks(string $manifest, array $options = [])
    {
        return $this->artisan('taskbooks:import', ['dir' => $this->fixtureDir(), '--manifest' => $manifest] + $options);
    }
}
