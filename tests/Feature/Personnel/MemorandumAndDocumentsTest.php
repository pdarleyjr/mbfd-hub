<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Services\Personnel\PersonnelDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\EmployeeMessaging\BuildsEmployeeMessagingFixtures;
use Tests\TestCase;

final class MemorandumAndDocumentsTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('local');
        config(['policy-library.scanner_enabled' => false]);
        $this->withoutVite();
    }

    private function member(string $name): User
    {
        $number = (string) (70000 + ++$this->sequence);
        $profile = Employee::query()->create([
            'employee_id' => $number, 'name' => $name, 'rank' => 'Firefighter', 'station' => 'Station 1',
            'roster_status' => 'active', 'password' => 'unused', 'must_change_password' => false,
            'city_email' => 'member'.$number.'@miamibeachfl.gov',
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'employee_profile_id' => $profile->id,
            'employee_id' => $number, 'name' => $name, 'must_change_password' => false,
        ])->fresh();
    }

    private function admin(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Admin '.++$this->sequence, 'must_change_password' => false]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function memo(array $overrides = []): array
    {
        return array_merge([
            'to' => 'Chief of Department', 'from' => 'Firefighter Pat Member', 'date' => '2026-11-05',
            'subject' => 'Hose testing equipment', 'body' => "Requesting a second hose tester for Station 2.\n\nThank you.",
        ], $overrides);
    }

    public function test_an_external_memorandum_is_produced_on_request_and_never_stored(): void
    {
        $member = $this->member('Pat Member');

        $response = $this->actingAs($member)->post('/employee/forms/api/memorandum/pdf', $this->memo() + ['download' => 1]);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(0, PersonnelDocument::query()->count());
        $this->assertSame(0, EmployeeLogisticsThread::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('personnel-documents'));
    }

    public function test_a_memorandum_to_support_services_reaches_the_inbox_once_with_its_pdf(): void
    {
        $member = $this->member('Pat Member');
        $key = (string) Str::uuid();

        $this->actingAs($member)->postJson('/employee/forms/api/memorandum/support-services', $this->memo(['to' => null]) + ['client_key' => $key])
            ->assertOk()->assertJsonPath('data.delivered', true);
        // A retry with the same key (dropped connection, double tap) delivers nothing twice.
        $this->actingAs($member)->postJson('/employee/forms/api/memorandum/support-services', $this->memo(['to' => null]) + ['client_key' => $key])->assertOk();

        $this->assertSame(1, EmployeeLogisticsThread::query()->count());
        $message = EmployeeLogisticsMessage::query()->sole();
        $this->assertStringContainsString('Hose testing equipment', (string) $message->body);
        $document = PersonnelDocument::query()->sole();
        $this->assertSame($message->personnel_document_id, $document->id);
        $this->assertSame('memorandum', $document->category);
        $this->assertTrue($document->visible_to_employee);
        $this->assertSame($member->employee_profile_id, $document->employee_id);

        // The member and Support Services can open it; an unrelated member cannot.
        $this->actingAs($member)->get(route('employee-messages.attachment', ['message' => $message->id]))->assertOk();
        $support = $this->logisticsViewer();
        $this->actingAs($support)->get(route('employee-messages.attachment', ['message' => $message->id]))->assertOk();
        $this->actingAs($this->member('Pat Other'))->get(route('employee-messages.attachment', ['message' => $message->id]))->assertNotFound();
        // A personnel-files viewer is not Support Services: the memo is not a personnel-file document.
        $this->actingAs($this->admin(['personnel.documents.view']))->get(route('personnel-documents.view', $document))->assertNotFound();
        // Inside the Hub the sender is the signed-in member, whatever the request says.
        $this->assertStringContainsString('From: Firefighter Pat Member', (string) $message->body);
    }

    public function test_email_copy_is_refused_when_no_attachment_capable_sender_is_configured(): void
    {
        config(['communications.cloudflare.account_id' => '', 'communications.cloudflare.api_token' => '']);
        $member = $this->member('Pat Member');

        $this->actingAs($member)->getJson('/employee/forms/api/memorandum/form')->assertOk()->assertJsonPath('data.email_copy_available', false);
        $this->actingAs($member)->postJson('/employee/forms/api/memorandum/email', $this->memo())->assertStatus(409);
    }

    public function test_uploaded_documents_are_hidden_from_the_member_until_shared(): void
    {
        $member = $this->member('Pat Member');
        $manager = $this->admin(['personnel.documents.manage']);
        $pdf = UploadedFile::fake()->createWithContent('evaluation.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $document = app(PersonnelDocumentService::class)->upload($pdf, 'evaluation.pdf', $member->employeeProfile, $manager, ['category' => 'evaluation']);

        $this->assertFalse($document->visible_to_employee, 'Linked to the member, but not shown until chosen.');
        $this->actingAs($member)->get(route('personnel-documents.view', $document))->assertNotFound();
        $this->actingAs($member)->get('/employee/documents')->assertOk()->assertDontSee('evaluation');

        app(PersonnelDocumentService::class)->setVisibility($document, true, $manager);
        $this->actingAs($member)->get(route('personnel-documents.view', $document))->assertOk();
        $this->actingAs($member)->get('/employee/documents')->assertOk()->assertSee('evaluation');
        // Another member never sees it, even by guessing the link.
        $this->actingAs($this->member('Pat Other'))->get(route('personnel-documents.download', $document))->assertNotFound();
        $this->assertSame(1, PersonnelDocument::query()->count());
        $this->assertGreaterThanOrEqual(3, $document->events()->count());
    }

    public function test_only_document_managers_upload(): void
    {
        $member = $this->member('Pat Member');
        $viewer = $this->admin(['personnel.documents.view']);
        $pdf = UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF\n");

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PersonnelDocumentService::class)->upload($pdf, 'x.pdf', $member->employeeProfile, $viewer, ['category' => 'other']);
    }
}
