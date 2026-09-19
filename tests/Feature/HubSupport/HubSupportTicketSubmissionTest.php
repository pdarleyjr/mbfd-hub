<?php

declare(strict_types=1);

namespace Tests\Feature\HubSupport;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\HubSupport\HubSupportTicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class HubSupportTicketSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = Employee::query()->create([
            'employee_id' => 'SUPPORT-1001',
            'name' => 'Firefighter Reporter',
            'rank' => 'Firefighter',
            'password' => 'not-used-by-tests',
        ]);
        $this->reporter = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => 'Stale User Snapshot',
        ]);
        Storage::fake('local');
    }

    public function test_submission_is_durable_idempotent_private_and_automatically_understood(): void
    {
        $service = app(HubSupportTicketSubmissionService::class);
        $payload = [
            'client_submission_id' => 'e5df9749-aabb-43c7-b1f9-e7a215f1de1c',
            'description' => "I can't submit the operational form.",
            'page_path' => '/employee/forms/froc/123?token=secret#step-2',
            'route_name' => 'employee.operational-forms',
            'referrer_path' => 'https://www.mbfdhub.com/employee?private=yes',
            'client_metadata' => [
                'userAgent' => 'Test Browser',
                'viewport' => ['width' => 834, 'height' => 1194],
                'authorization' => 'Bearer should-not-persist',
            ],
            'diagnostics' => [
                'events' => [[
                    'type' => 'request',
                    'method' => 'POST',
                    'path' => '/employee/forms/api/records?csrf=secret',
                    'status' => 500,
                    'request_body' => 'private form contents',
                ]],
            ],
        ];
        $files = [
            UploadedFile::fake()->image('screen.png'),
            UploadedFile::fake()->create('details.pdf', 30, 'application/pdf'),
        ];

        $first = $service->submit($this->reporter, $payload, $files);
        $second = $service->submit($this->reporter, $payload, []);

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->ticket->id, $second->ticket->id);
        self::assertMatchesRegularExpression('/^HUB-\d{4}-\d{6}$/', (string) $first->ticket->ticket_number);
        self::assertSame($this->reporter->id, $first->ticket->reported_by_user_id);
        self::assertSame($this->reporter->employee_profile_id, $first->ticket->reported_by_employee_id);
        self::assertSame('Firefighter Reporter', $first->ticket->reporter_name_snapshot);
        self::assertSame('/employee/forms/froc/123', $first->ticket->page_path);
        self::assertSame('/employee', $first->ticket->referrer_path);
        self::assertSame('form_or_workflow', $first->ticket->category->value);
        self::assertSame('task_blocking', $first->ticket->impact->value);
        self::assertSame('operational_forms', $first->ticket->affected_component);
        self::assertSame('new', $first->ticket->status->value);
        self::assertCount(1, $first->ticket->updates);
        self::assertCount(2, $first->ticket->attachments);
        self::assertDatabaseCount('hub_support_tickets', 1);

        $persisted = json_encode([
            $first->ticket->client_metadata,
            $first->ticket->diagnostics,
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('should-not-persist', $persisted);
        self::assertStringNotContainsString('private form contents', $persisted);
        self::assertStringNotContainsString('secret', $persisted);

        foreach ($first->ticket->attachments as $attachment) {
            self::assertSame('local', $attachment->disk);
            self::assertNotSame($attachment->original_filename, $attachment->generated_filename);
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+\.(png|pdf)$/', $attachment->generated_filename);
            self::assertSame(64, strlen($attachment->sha256));
            Storage::disk('local')->assertExists($attachment->storage_path);
        }
    }

    public function test_another_reporter_cannot_claim_an_existing_client_submission_id(): void
    {
        $service = app(HubSupportTicketSubmissionService::class);
        $payload = [
            'client_submission_id' => 'b9571a97-6d77-403a-9a11-0a7774ec0f27',
            'description' => 'The page does not respond when I press submit.',
            'page_path' => '/employee/forms',
        ];
        $service->submit($this->reporter, $payload);
        $other = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->expectException(ValidationException::class);
        $service->submit($other, $payload);
    }

    public function test_only_two_png_jpeg_or_pdf_files_up_to_ten_megabytes_are_accepted(): void
    {
        $service = app(HubSupportTicketSubmissionService::class);

        try {
            $service->submit($this->reporter, [
                'client_submission_id' => '93941e1d-f5f7-48bb-9106-a2de1dafcbe0',
                'description' => 'This report has too many files attached.',
            ], [
                UploadedFile::fake()->image('one.png'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->create('three.pdf', 1, 'application/pdf'),
            ]);
            self::fail('A third attachment was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('attachments', $exception->errors());
        }

        self::assertDatabaseCount('hub_support_tickets', 0);
    }

    public function test_jpeg_is_accepted_but_unsupported_or_oversized_files_are_rejected(): void
    {
        $service = app(HubSupportTicketSubmissionService::class);
        $accepted = $service->submit($this->reporter, [
            'client_submission_id' => '5d153d24-9274-4d9d-84e5-3f91657576ee',
            'description' => 'A JPEG screenshot helps show the issue.',
        ], [UploadedFile::fake()->image('screen.jpg')]);
        self::assertCount(1, $accepted->ticket->attachments);
        self::assertSame('image/jpeg', $accepted->ticket->attachments->first()->mime_type);

        foreach ([
            UploadedFile::fake()->create('malware.exe', 1, 'application/octet-stream'),
            UploadedFile::fake()->create('large.pdf', 10_241, 'application/pdf'),
        ] as $index => $file) {
            try {
                $service->submit($this->reporter, [
                    'client_submission_id' => sprintf('40a7ebd%d-8d6e-4f9e-b1fd-2c2aa9191c2%d', $index, $index),
                    'description' => 'This attachment should not be accepted.',
                ], [$file]);
                self::fail('An invalid attachment was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('attachments.0', $exception->errors());
            }
        }
    }
}
