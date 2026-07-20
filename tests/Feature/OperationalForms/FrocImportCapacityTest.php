<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormRecord;
use App\Services\CloudflareAIService;
use App\Services\OperationalForms\FrocImportLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class FrocImportCapacityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function employee(string $employeeId = '20731'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Test Firefighter '.$employeeId,
            'rank' => 'Firefighter',
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }

    /**
     * @param  array<int, array{name: string, content?: string, store?: bool}>  $entries
     */
    private function makeZipUpload(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'froc-cap');
        unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $entry) {
            $zip->addFromString($entry['name'], $entry['content'] ?? '');
            if ($entry['store'] ?? false) {
                $zip->setCompressionIndex($zip->numFiles - 1, \ZipArchive::CM_STORE);
            }
        }
        $zip->close();
        $this->tempPaths[] = $path;

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function makeTextUpload(string $name, string $content, string $mime = 'text/plain'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'froc-txt');
        unlink($path);
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * Build a sparse file of exactly $bytes without allocating a matching PHP
     * string. Used to test the upload-size ceiling without str_repeat().
     */
    private function makeSparseFile(int $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'froc-sparse');
        unlink($path);
        $handle = fopen($path, 'w+b');
        ftruncate($handle, $bytes);
        fclose($handle);
        $this->tempPaths[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function createFrocRecord(): array
    {
        return $this->postJson('/employee/forms/api/records', [
            'form_type' => 'froc_log_001_ff',
            'title' => 'F-ROC Daily Activity Report',
        ])->assertCreated()->json('record');
    }

    public function test_preview_accepts_a_media_bearing_zip_larger_than_two_megabytes(): void
    {
        $employee = $this->employee();
        $this->mock(CloudflareAIService::class)->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('offline'));

        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', [
            ['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969. R6 en-route to staging area.'],
            ['name' => 'IMG-0001.jpg', 'content' => random_bytes(2_200_000), 'store' => true],
        ]);

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('preview.unit_designation', 'R6')
            ->assertJsonPath('preview.engine', 'deterministic-fallback')
            ->assertJsonPath('preview.vehicle_mileage.0.start_odometer', '113969');
    }

    public function test_record_scoped_apply_accepts_a_media_bearing_zip_larger_than_two_megabytes(): void
    {
        $employee = $this->employee();
        $this->mock(CloudflareAIService::class)->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('offline'));

        $record = $this->actingAs($employee, 'employee')
            ->postJson('/employee/forms/api/records', [
                'form_type' => 'froc_log_001_ff',
                'title' => 'F-ROC Daily Activity Report',
            ])->assertCreated()->json('record');

        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', [
            ['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969.'],
            ['name' => 'IMG-0001.jpg', 'content' => random_bytes(2_200_000), 'store' => true],
        ]);

        $this->actingAs($employee, 'employee')
            ->postJson("/employee/forms/api/records/{$record['id']}/froc/import", [
                'revision' => $record['revision'],
                'unit_id' => 'R6',
                'notes_file' => $zip,
                'merge_mode' => 'fill_empty_and_append',
                'idempotency_key' => 'cap-media-zip',
            ])
            ->assertOk()
            ->assertJsonPath('record.revision', 2)
            ->assertJsonPath('record.data.vehicle_mileage.0.start_odometer', '113969');

        $this->assertDatabaseCount('operational_form_records', 1);
        $this->assertDatabaseHas('operational_form_imports', ['status' => 'applied']);
    }

    public function test_exact_fifty_megabyte_file_passes_size_validation_but_fails_source_parse(): void
    {
        $employee = $this->employee();
        $file = $this->makeSparseFile(50 * 1024 * 1024, 'notes.txt', 'text/plain');

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('notes_file')
            ->assertJsonValidationErrors('import');

        $this->assertDatabaseCount('operational_form_imports', 0);
    }

    public function test_fifty_megabytes_plus_one_byte_is_rejected_by_size(): void
    {
        $employee = $this->employee();
        $file = $this->makeSparseFile((50 * 1024 * 1024) + 1, 'too-big.txt', 'text/plain');

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes_file');

        $this->assertDatabaseCount('operational_form_imports', 0);
    }

    public function test_large_text_file_is_rejected_after_bounded_read_and_record_unchanged(): void
    {
        $employee = $this->employee();
        $record = $this->actingAs($employee, 'employee')
            ->postJson('/employee/forms/api/records', [
                'form_type' => 'froc_log_001_ff',
                'title' => 'F-ROC Daily Activity Report',
            ])->assertCreated()->json('record');

        // 3 MB of text is well under the 50 MB upload ceiling but far above the
        // 1 MiB extracted-text ceiling, so it must be rejected by bounded read.
        $big = $this->makeTextUpload('notes.txt', str_repeat('R6 started mileage 113969. ', 60_000));

        $this->actingAs($employee, 'employee')
            ->postJson("/employee/forms/api/records/{$record['id']}/froc/import", [
                'revision' => $record['revision'],
                'unit_id' => 'R6',
                'notes_file' => $big,
                'merge_mode' => 'fill_empty_and_append',
                'idempotency_key' => 'cap-large-txt',
            ])
            ->assertStatus(422);

        $fresh = OperationalFormRecord::query()->find($record['id']);
        $this->assertSame(1, $fresh->revision, 'Record must be unchanged after a failed import.');
        $this->assertDatabaseCount('operational_form_imports', 0);
    }

    public function test_existing_manual_fields_are_preserved_when_import_fails(): void
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'froc_log_001_ff',
            'form_version' => '11',
            'title' => 'Manual title',
            'data' => ['general_information' => ['event_id' => 'Manual event']],
            'revision' => 4,
        ]);

        $big = $this->makeTextUpload('notes.txt', str_repeat('R6 started mileage 113969. ', 60_000));

        $this->actingAs($employee, 'employee')
            ->postJson("/employee/forms/api/records/{$record->id}/froc/import", [
                'revision' => 4,
                'unit_id' => 'R6',
                'notes_file' => $big,
                'merge_mode' => 'fill_empty_and_append',
                'idempotency_key' => 'cap-preserve',
            ])
            ->assertStatus(422);

        $fresh = $record->fresh();
        $this->assertSame('Manual title', $fresh->title);
        $this->assertSame('Manual event', data_get($fresh->data, 'general_information.event_id'));
    }

    public function test_preview_accepts_exactly_five_hundred_entries(): void
    {
        $employee = $this->employee();
        $entries = [['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969.']];
        for ($i = 0; $i < 499; $i++) {
            $entries[] = ['name' => "IMG-{$i}.jpg", 'content' => 'x', 'store' => true];
        }
        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', $entries);

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('preview.unit_designation', 'R6');
    }

    public function test_preview_rejects_five_hundred_and_one_entries(): void
    {
        $employee = $this->employee();
        $entries = [['name' => '_chat.txt', 'content' => 'R6 starting mileage: 113969.']];
        for ($i = 0; $i < 500; $i++) {
            $entries[] = ['name' => "IMG-{$i}.jpg", 'content' => 'x', 'store' => true];
        }
        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', $entries);

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('notes_file')
            ->assertJsonValidationErrors('import');
    }

    public function test_preview_accepts_exactly_ten_text_entries(): void
    {
        $employee = $this->employee();
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = ['name' => "chat-{$i}.txt", 'content' => "R6 message {$i}"];
        }
        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', $entries);

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('preview.unit_designation', 'R6');
    }

    public function test_preview_rejects_eleven_text_entries(): void
    {
        $employee = $this->employee();
        $entries = [];
        for ($i = 0; $i < 11; $i++) {
            $entries[] = ['name' => "chat-{$i}.txt", 'content' => "R6 message {$i}"];
        }
        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', $entries);

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('notes_file')
            ->assertJsonValidationErrors('import');
    }

    public function test_preview_keeps_safe_audit_metadata_and_omits_raw_source(): void
    {
        $employee = $this->employee();
        $this->mock(CloudflareAIService::class)->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('offline'));

        // Deterministic log capture via a Monolog TestHandler pushed onto the
        // default channel the application actually writes to.
        $handler = new TestHandler();
        logger()->driver()->getLogger()->pushHandler($handler);

        $sentinel = 'UNIQUE_R6_SOURCE_TOKEN_ZXCVBNM';
        $zip = $this->makeZipUpload('WhatsApp Chat with R6.zip', [
            ['name' => '_chat.txt', 'content' => "{$sentinel} starting mileage: 113969."],
            ['name' => 'IMG-0001.jpg', 'content' => random_bytes(1_500_000), 'store' => true],
        ]);

        $response = $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => $zip,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $preview = $response->json('preview');
        $json = json_encode($preview, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($sentinel, $json, 'Raw source must not appear in the API response.');
        // `zip_stats` is intentionally not returned to the client; it is only
        // emitted to structured logs (verified below).
        $this->assertArrayHasKey('source_sha256', $preview);
        $this->assertArrayNotHasKey('zip_stats', $preview);

        // The fallback log must never contain the raw source token.
        $leaked = false;
        foreach ($handler->getRecords() as $record) {
            $serialized = $record['message'].' '.json_encode($record['context'], JSON_THROW_ON_ERROR);
            if (str_contains($serialized, $sentinel)) {
                $leaked = true;
                break;
            }
        }
        $this->assertFalse($leaked, 'Raw source must not appear in application logs.');

        // Positive control: prove the handler can capture a known sentinel so a
        // passing assertion above is meaningful. This record is emitted AFTER the
        // import run and is never asserted as "absent".
        logger()->warning('control sentinel '.$sentinel);
        $controlDetected = false;
        foreach ($handler->getRecords() as $record) {
            if (str_contains($record['message'], 'control sentinel '.$sentinel)) {
                $controlDetected = true;
                break;
            }
        }
        $this->assertTrue($controlDetected, 'Positive control: the log handler must capture a known sentinel.');
    }

    public function test_post_too_large_returns_stable_json_413_for_operational_forms_api(): void
    {
        $request = Request::create('/employee/forms/api/froc/import-preview', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, new PostTooLargeException());

        $this->assertNotNull($response);
        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $body = json_decode($response->getContent(), true);
        $this->assertSame('request_too_large', $body['code']);
        $this->assertStringContainsString((string) FrocImportLimits::uploadMaxMegabytes(), $body['message']);
    }

    public function test_post_too_large_retains_normal_html_behavior_for_unrelated_web_requests(): void
    {
        $request = Request::create('/some/web/page', 'POST');

        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->render($request, new PostTooLargeException());

        // Non-API, non-JSON requests keep ordinary web handling (an HTML 413
        // page) and are never converted to the F-ROC JSON schema.
        $this->assertNotNull($response, 'Unrelated web requests must still receive a response.');
        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('413', $response->getContent());
    }
}
