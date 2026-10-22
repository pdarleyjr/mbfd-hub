<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Enums\AccountStatus;
use App\Models\CommandCommunicationAttachment;
use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Contract of the 300 Command Communications JSON API and attachment download. */
final class CommandCommunicationApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('command.300.access', 'web');
        Storage::fake('local');
    }

    public function test_guests_receive_401_on_every_endpoint(): void
    {
        $this->getJson('/api/command-communications/posts')->assertUnauthorized();
        $this->getJson('/api/command-communications/summary')->assertUnauthorized();
        $this->postJson('/api/command-communications/posts', ['body' => 'x'])->assertUnauthorized();
        $this->postJson('/api/command-communications/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV/pin', ['pinned' => true])->assertUnauthorized();
        $this->postJson('/api/command-communications/read')->assertUnauthorized();
        $this->getJson('/command-communications/attachments/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertUnauthorized();
    }

    public function test_firefighter_without_grant_is_forbidden_everywhere_including_downloads(): void
    {
        $attachmentId = $this->seedAttachmentAsCaptain();
        $this->actingAsCanonicalFixture('E-FF-0001', 'Plain Firefighter', 'Firefighter');

        $this->getJson('/api/command-communications/posts')->assertForbidden();
        $this->getJson('/api/command-communications/summary')->assertForbidden();
        $this->postJson('/api/command-communications/posts', ['body' => 'hi', 'client_submission_id' => 'abcdefgh-0001'])->assertForbidden();
        $this->postJson('/api/command-communications/posts/'.CommandCommunicationPost::query()->value('public_id').'/pin', ['pinned' => true])->assertForbidden();
        $this->postJson('/api/command-communications/read')->assertForbidden();
        $this->getJson('/command-communications/attachments/'.$attachmentId)->assertForbidden();
        $this->assertSame(1, CommandCommunicationPost::query()->count());
    }

    public function test_captain_can_post_and_read(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0001', 'Casey Captain', 'Captain');

        $created = $this->postJson('/api/command-communications/posts', [
            'subject' => 'Staffing', 'body' => 'Need relief at 0700 https://example.test/plan', 'client_submission_id' => 'sub-0000001',
        ])->assertCreated();
        $created->assertJsonPath('data.author.name', 'Casey Captain')
            ->assertJsonPath('data.author.rank', 'Captain')
            ->assertJsonPath('data.author.side', 'command')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.pinned', false)
            ->assertJsonPath('data.subject', 'Staffing');

        $feed = $this->getJson('/api/command-communications/posts')->assertOk();
        $feed->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $created->json('data.id'))
            ->assertJsonPath('next_before', null)->assertJsonPath('unread_count', 0);
        $this->assertSame([], $feed->json('pinned'));
    }

    public function test_firefighter_with_direct_grant_can_post(): void
    {
        $user = $this->actingAsCanonicalFixture('E-FF-0002', 'Granted Firefighter', 'Firefighter');
        $user->givePermissionTo('command.300.access');

        $this->postJson('/api/command-communications/posts', ['body' => 'Granted voice', 'client_submission_id' => 'grant-00001'])->assertCreated();
        $this->getJson('/api/command-communications/posts')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_super_admin_can_post_and_is_labelled_command_through_the_api(): void
    {
        $this->actingAsCanonicalUser($this->superAdmin());

        $this->postJson('/api/command-communications/posts', ['body' => 'Admin note', 'client_submission_id' => 'admin-00001'])
            ->assertCreated()->assertJsonPath('data.author.side', 'command');
    }

    public function test_reply_persists_and_nests_and_replies_to_replies_are_rejected(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0002', 'Reply Captain', 'Captain');
        $parent = $this->postJson('/api/command-communications/posts', ['body' => 'Top level', 'client_submission_id' => 'parent-0001'])->json('data.id');

        $reply = $this->postJson('/api/command-communications/posts', ['body' => 'First reply', 'parent_id' => $parent, 'client_submission_id' => 'reply-00001'])
            ->assertCreated()->assertJsonPath('data.parent_id', $parent);
        $this->postJson('/api/command-communications/posts', ['body' => 'Nested?', 'parent_id' => $reply->json('data.id'), 'client_submission_id' => 'reply-00002'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/command-communications/posts', ['body' => 'Ghost', 'parent_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'client_submission_id' => 'reply-00003'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $feed = $this->getJson('/api/command-communications/posts')->assertOk();
        $feed->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.replies')
            ->assertJsonPath('data.0.replies.0.id', $reply->json('data.id'))
            ->assertJsonPath('data.0.replies.0.body', 'First reply');
        $this->assertSame(2, CommandCommunicationPost::query()->count());
    }

    public function test_idempotent_replay_returns_same_post_without_duplicates_or_new_files(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0003', 'Replay Captain', 'Captain');
        $payload = ['body' => 'Once only', 'client_submission_id' => 'replay-00001', 'attachments' => [$this->pngUpload('a.png')]];

        $first = $this->post('/api/command-communications/posts', $payload, ['Accept' => 'application/json'])->assertCreated();
        $second = $this->post('/api/command-communications/posts', ['body' => 'Once only', 'client_submission_id' => 'replay-00001', 'attachments' => [$this->pngUpload('b.png')]], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, CommandCommunicationPost::query()->count());
        $this->assertSame(1, CommandCommunicationAttachment::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('command-communications'));
    }

    public function test_client_submission_id_is_required_and_validated(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0004', 'Validation Captain', 'Captain');

        $this->postJson('/api/command-communications/posts', ['body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('client_submission_id');
        $this->postJson('/api/command-communications/posts', ['body' => 'x', 'client_submission_id' => 'short'])->assertUnprocessable();
        $this->postJson('/api/command-communications/posts', ['body' => 'x', 'client_submission_id' => 'has spaces in it'])->assertUnprocessable();
        $this->postJson('/api/command-communications/posts', ['client_submission_id' => 'empty-00001'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/api/command-communications/posts', ['body' => str_repeat('a', 10001), 'client_submission_id' => 'long-000001'])->assertUnprocessable();
        $this->postJson('/api/command-communications/posts', ['body' => 'x', 'subject' => str_repeat('s', 161), 'client_submission_id' => 'subj-000001'])->assertUnprocessable();
    }

    public function test_attachment_type_validation_rejects_svg_html_and_executables_and_accepts_png_and_pdf(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0005', 'Upload Captain', 'Captain');
        $bad = [
            'svg named svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'svg named png' => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'),
            'html named txt' => UploadedFile::fake()->createWithContent('page.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'),
            'html named html' => UploadedFile::fake()->createWithContent('page.html', '<html><body>hi</body></html>'),
            'exe named pdf' => UploadedFile::fake()->createWithContent('setup.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00This program cannot be run in DOS mode."),
            'exe named exe' => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00"),
            'php named png' => UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>'),
        ];

        $n = 0;
        foreach ($bad as $label => $file) {
            $this->post('/api/command-communications/posts', ['body' => 'try', 'client_submission_id' => 'bad-upload-'.++$n, 'attachments' => [$file]], ['Accept' => 'application/json'])
                ->assertUnprocessable($label);
        }
        $this->assertSame(0, CommandCommunicationPost::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $ok = $this->post('/api/command-communications/posts', [
            'body' => '', 'client_submission_id' => 'good-upload-1', 'attachments' => [$this->pngUpload('Photo One.PNG'), $this->pdfUpload('Plan.pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $ok->assertJsonCount(2, 'data.attachments')
            ->assertJsonPath('data.attachments.0.kind', 'image')->assertJsonPath('data.attachments.0.mime_type', 'image/png')
            ->assertJsonPath('data.attachments.1.kind', 'document')->assertJsonPath('data.attachments.1.mime_type', 'application/pdf');
    }

    public function test_office_text_and_csv_documents_are_accepted_with_canonical_types(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0015', 'Docs Captain', 'Captain');
        $zip = tempnam(sys_get_temp_dir(), 'ccz');
        $archive = new \ZipArchive;
        $archive->open($zip, \ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('word/document.xml', '<w:document/>');
        $archive->close();
        $docx = UploadedFile::fake()->createWithContent('Report.docx', (string) file_get_contents($zip));
        @unlink($zip);

        $response = $this->post('/api/command-communications/posts', [
            'body' => 'Docs', 'client_submission_id' => 'office-00001',
            'attachments' => [$docx, UploadedFile::fake()->createWithContent('notes.txt', "plain notes\n"), UploadedFile::fake()->createWithContent('roster.csv', "a,b\n1,2\n")],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/csv'],
            array_column($response->json('data.attachments'), 'mime_type'),
        );
        $this->assertSame(['docx', 'txt', 'csv'], CommandCommunicationAttachment::query()->orderBy('id')->get()->map(fn ($a) => pathinfo($a->storage_path, PATHINFO_EXTENSION))->all());
    }

    public function test_a_renamed_archive_is_not_accepted_as_an_office_document(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0016', 'Zip Captain', 'Captain');
        $zip = tempnam(sys_get_temp_dir(), 'ccz');
        $archive = new \ZipArchive;
        $archive->open($zip, \ZipArchive::OVERWRITE);
        $archive->addFromString('payload/readme.txt', 'not an office file');
        $archive->close();
        $renamed = UploadedFile::fake()->createWithContent('Report.docx', (string) file_get_contents($zip));
        @unlink($zip);

        $this->post('/api/command-communications/posts', [
            'body' => 'Archive', 'client_submission_id' => 'archive-0001', 'attachments' => [$renamed],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->assertSame(0, CommandCommunicationAttachment::query()->count());
    }

    public function test_attachment_count_limit(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0006', 'Limit Captain', 'Captain');
        $files = array_map(fn (int $i) => $this->pdfUpload("doc{$i}.pdf"), range(1, 6));

        $this->post('/api/command-communications/posts', ['body' => 'six', 'client_submission_id' => 'limit-00001', 'attachments' => $files], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_attachments_are_private_hashed_and_never_leak_storage_details(): void
    {
        $user = $this->actingAsCanonicalFixture('E-CAP-0007', 'Private Captain', 'Captain');
        $response = $this->post('/api/command-communications/posts', [
            'body' => 'See file', 'client_submission_id' => 'private-0001', 'attachments' => [$this->pdfUpload('Plan.pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $row = CommandCommunicationAttachment::query()->firstOrFail();
        $this->assertSame('local', $row->disk);
        $this->assertSame(hash('sha256', self::PDF), $row->sha256);
        $this->assertSame(strlen(self::PDF), $row->byte_size);
        $this->assertMatchesRegularExpression('#^command-communications/\d{4}/\d{2}/[0-9A-Za-z]{26}\.pdf$#', $row->storage_path);
        Storage::disk('local')->assertExists($row->storage_path);

        $body = $response->getContent().$this->getJson('/api/command-communications/posts')->getContent();
        foreach ([$row->storage_path, 'storage_path', 'generated_filename', $row->sha256, $user->email, 'author_user_id', 'uploaded_by'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        $this->assertSame(['id', 'name', 'mime_type', 'size', 'kind', 'url'], array_keys($response->json('data.attachments.0')));
        $this->assertSame('/command-communications/attachments/'.$row->public_id, $response->json('data.attachments.0.url'));
    }

    public function test_download_is_authorized_and_sends_safe_headers(): void
    {
        $id = $this->seedAttachmentAsCaptain();
        $this->actingAsCanonicalFixture('E-CAP-0008', 'Reader Captain', 'Captain');

        $response = $this->get('/command-communications/attachments/'.$id)->assertOk();
        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        // Non-images are never inline, even when asked.
        $this->assertStringContainsString('attachment;', (string) $this->get('/command-communications/attachments/'.$id.'?inline=1')->headers->get('Content-Disposition'));
        $this->get('/command-communications/attachments/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    public function test_images_are_inline_only_on_request(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0009', 'Image Captain', 'Captain');
        $url = $this->post('/api/command-communications/posts', [
            'body' => 'pic', 'client_submission_id' => 'image-00001', 'attachments' => [$this->pngUpload('pic.png')],
        ], ['Accept' => 'application/json'])->json('data.attachments.0.url');

        $this->assertStringStartsWith('attachment;', (string) $this->get($url)->headers->get('Content-Disposition'));
        $inline = $this->get($url.'?inline=1')->assertOk();
        $this->assertStringStartsWith('inline;', (string) $inline->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $inline->headers->get('Content-Type'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
    }

    public function test_unread_counts_follow_other_authors_and_mark_read(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $captain = $this->actingAsCanonicalFixture('E-CAP-0010', 'Unread Captain', 'Captain');
        $admin = $this->superAdmin();
        $service = app(CommandCommunicationService::class);

        $service->post($admin, 'administration', null, 'Admin says hi', null, 'unread-admin-1');
        $this->getJson('/api/command-communications/summary')->assertOk()
            ->assertJsonPath('unread_count', 1)->assertJsonPath('latest_activity_at', now()->toIso8601String());

        Carbon::setTestNow('2026-10-22 10:01:00');
        $this->postJson('/api/command-communications/posts', ['body' => 'My own post', 'client_submission_id' => 'unread-own-001'])->assertCreated();
        $this->getJson('/api/command-communications/summary')->assertJsonPath('unread_count', 1);

        Carbon::setTestNow('2026-10-22 10:02:00');
        $read = $this->postJson('/api/command-communications/read')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(now()->toIso8601String(), $read->json('last_read_at'));
        $this->getJson('/api/command-communications/posts')->assertJsonPath('unread_count', 0);

        Carbon::setTestNow('2026-10-22 10:03:00');
        $service->post($admin, 'administration', null, 'Another from admin', null, 'unread-admin-2');
        $service->post($admin, 'administration', null, 'A reply counts too', CommandCommunicationPost::query()->value('public_id'), 'unread-admin-3');
        $this->getJson('/api/command-communications/summary')->assertJsonPath('unread_count', 2);
        $this->assertSame(2, $service->unreadCount($captain));
        $this->assertSame(1, $service->unreadCount($admin)); // only the captain's post; admin never marked read
        Carbon::setTestNow();
    }

    public function test_pin_and_unpin_by_any_authorized_participant(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-0011', 'Pin Captain', 'Captain');
        $id = $this->postJson('/api/command-communications/posts', ['body' => 'Important', 'client_submission_id' => 'pin-0000001'])->json('data.id');
        $replyId = $this->postJson('/api/command-communications/posts', ['body' => 'Reply', 'parent_id' => $id, 'client_submission_id' => 'pin-0000002'])->json('data.id');

        $this->postJson("/api/command-communications/posts/{$id}/pin", ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);
        $feed = $this->getJson('/api/command-communications/posts')->assertOk();
        $feed->assertJsonCount(1, 'pinned')->assertJsonPath('pinned.0.id', $id)->assertJsonPath('data.0.pinned', true);
        $this->assertNotNull(CommandCommunicationPost::query()->where('public_id', $id)->value('pinned_by_user_id'));

        $this->postJson("/api/command-communications/posts/{$id}/pin", ['pinned' => false])->assertOk()->assertJsonPath('data.pinned', false);
        $this->getJson('/api/command-communications/posts')->assertJsonCount(0, 'pinned');
        $this->assertNull(CommandCommunicationPost::query()->where('public_id', $id)->value('pinned_by_user_id'));

        $this->postJson("/api/command-communications/posts/{$replyId}/pin", ['pinned' => true])->assertNotFound();
        $this->postJson("/api/command-communications/posts/{$id}/pin", [])->assertUnprocessable();
        $this->postJson('/api/command-communications/posts/01ARZ3NDEKTSV4RRFFQ69G5FAV/pin', ['pinned' => true])->assertNotFound();
    }

    public function test_pagination_cursor_walks_newest_first_without_gaps(): void
    {
        $captain = $this->actingAsCanonicalFixture('E-CAP-0012', 'Pager Captain', 'Captain');
        $service = app(CommandCommunicationService::class);
        $ids = [];
        foreach (range(1, 25) as $i) {
            $ids[] = $service->post($captain, 'command', null, "Post {$i}", null, sprintf('page-%07d', $i))->public_id;
        }
        $expected = array_reverse($ids);

        $first = $this->getJson('/api/command-communications/posts?limit=10')->assertOk();
        $this->assertSame(array_slice($expected, 0, 10), array_column($first->json('data'), 'id'));
        $this->assertSame($expected[9], $first->json('next_before'));

        $second = $this->getJson('/api/command-communications/posts?limit=10&before='.$first->json('next_before'))->assertOk();
        $this->assertSame(array_slice($expected, 10, 10), array_column($second->json('data'), 'id'));

        $third = $this->getJson('/api/command-communications/posts?limit=10&before='.$second->json('next_before'))->assertOk();
        $this->assertSame(array_slice($expected, 20), array_column($third->json('data'), 'id'));
        $this->assertNull($third->json('next_before'));

        $this->getJson('/api/command-communications/posts?before=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertUnprocessable();
        $this->getJson('/api/command-communications/posts?limit=500')->assertUnprocessable();
    }

    public function test_feed_query_count_does_not_grow_with_posts_replies_or_attachments(): void
    {
        $captain = $this->actingAsCanonicalFixture('E-CAP-0013', 'Query Captain', 'Captain');
        $service = app(CommandCommunicationService::class);
        $top = $service->post($captain, 'command', null, 'one', null, 'query-000001', [$this->pngUpload('one.png')]);
        $service->post($captain, 'command', null, 'first reply', $top->public_id, 'queryr-000001', [$this->pdfUpload('one.pdf')]);
        $small = $this->feedQueryCount();

        foreach (range(2, 8) as $i) {
            $parent = $service->post($captain, 'command', null, "post {$i}", null, sprintf('query-%06d', $i), [$this->pngUpload("p{$i}.png")]);
            $service->post($captain, 'command', null, "reply {$i}", $parent->public_id, sprintf('queryr-%05d', $i), [$this->pdfUpload("r{$i}.pdf")]);
        }
        $service->post($captain, 'command', null, 'reply to first', $top->public_id, 'queryr-000099');

        $this->assertSame($small, $this->feedQueryCount());
    }

    public function test_post_json_never_contains_email_or_ids(): void
    {
        $user = $this->actingAsCanonicalFixture('E-CAP-0014', 'Shape Captain', 'Captain');
        $this->postJson('/api/command-communications/posts', ['body' => 'Shape', 'client_submission_id' => 'shape-00001'])->assertCreated();

        $post = $this->getJson('/api/command-communications/posts')->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'parent_id', 'subject', 'body', 'author', 'is_mine', 'created_at', 'pinned', 'attachments', 'replies'], array_keys($post));
        $this->assertEqualsCanonicalizing(['name', 'rank', 'side'], array_keys($post['author']));
        $this->assertStringNotContainsString($user->email, json_encode($post, JSON_THROW_ON_ERROR));
    }

    private function feedQueryCount(): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/command-communications/posts?limit=50')->assertOk();
        $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $count;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Super Administrator']);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }

    /** Creates a captain-authored post with a PDF, then returns the attachment public id. */
    private function seedAttachmentAsCaptain(): string
    {
        $captain = $this->actingAsCanonicalFixture('E-CAP-SEED', 'Seed Captain', 'Captain');
        app(CommandCommunicationService::class)->post($captain, 'command', null, 'With file', null, 'seed-00000001', [$this->pdfUpload('seed.pdf')]);

        return (string) CommandCommunicationAttachment::query()->value('public_id');
    }

    private function pngUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(self::PNG, true));
    }

    private function pdfUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }
}
