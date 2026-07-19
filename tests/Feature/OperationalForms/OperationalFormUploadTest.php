<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalFormUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = config('filesystems.private', 'local');
        Storage::fake($this->disk);
    }

    public function test_employee_can_submit_any_file_type_as_a_completed_private_form(): void
    {
        $employee = $this->employee();
        $file = UploadedFile::fake()->create(
            'command-response-plan.docx',
            32,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $response = $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/uploads', [
                'name' => 'Command response plan',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('record.form_type', 'uploaded_file')
            ->assertJsonPath('record.status', 'completed')
            ->assertJsonPath('record.title', 'Command response plan')
            ->assertJsonPath('record.documents.0.display_name', 'Command response plan.docx');

        $record = OperationalFormRecord::query()->findOrFail($response->json('record.id'));
        $document = $record->documents()->sole();

        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame(1, $record->latest_pdf_version);
        $this->assertSame('uploaded', $document->generator_version);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $document->pdf_sha256);
        Storage::disk($this->disk)->assertExists($document->storage_path);
        $this->assertDatabaseHas('operational_form_events', [
            'form_record_id' => $record->id,
            'employee_id' => $employee->id,
            'event_type' => 'file_uploaded',
        ]);
    }

    public function test_upload_requires_a_name_and_file_and_keeps_other_employees_out(): void
    {
        $owner = $this->employee('19545');
        $other = $this->employee('20731');

        $this->actingAs($owner, 'employee')
            ->post('/employee/forms/api/uploads', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'file']);

        $upload = $this->post('/employee/forms/api/uploads', [
            'name' => 'Private field notes',
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Private operational notes'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = OperationalFormDocument::query()
            ->where('form_record_id', $upload->json('record.id'))
            ->sole();

        auth('employee')->logout();

        $this->actingAs($other, 'employee')
            ->get('/employee/forms/api/documents/'.$document->id.'/preview')
            ->assertNotFound();
    }

    public function test_uploaded_text_is_viewable_but_active_content_is_never_rendered_inline(): void
    {
        $employee = $this->employee();

        $text = $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/uploads', [
                'name' => 'Readable notes',
                'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Readable operational notes'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $textDocument = OperationalFormDocument::query()
            ->where('form_record_id', $text->json('record.id'))
            ->sole();

        $textPreview = $this->get('/employee/forms/api/documents/'.$textDocument->id.'/preview')
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('inline;', $textPreview->headers->get('content-disposition'));

        $html = $this->post('/employee/forms/api/uploads', [
            'name' => 'Untrusted active file',
            'file' => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $htmlDocument = OperationalFormDocument::query()
            ->where('form_record_id', $html->json('record.id'))
            ->sole();

        $htmlPreview = $this->get('/employee/forms/api/documents/'.$htmlDocument->id.'/preview')
            ->assertOk();
        $this->assertStringContainsString('attachment;', $htmlPreview->headers->get('content-disposition'));
    }

    private function employee(string $employeeId = '90001'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Employee '.$employeeId,
            'rank' => 'Firefighter',
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }
}
