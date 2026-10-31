<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Http\Controllers\Mail\MessageBodyController;
use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MessageRoutesTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function body(string $kind, int $id): string
    {
        return "/admin/email/messages/{$kind}/{$id}/body";
    }

    private function attachment(string $kind, int $id, int|string $index): string
    {
        return "/admin/email/messages/{$kind}/{$id}/attachments/{$index}";
    }

    /** An inbound message with one stored attachment. @return array{0: InboundEmail, 1: string} */
    private function inboundWithFile(string $content = 'file bytes', string $filename = 'note.txt', string $claimed = 'text/plain'): array
    {
        $email = $this->inbound();
        $path = 'inbound-email-attachments/'.hash('sha256', $email->provider_message_id).'/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, $content);
        $email->forceFill(['attachment_metadata' => [['filename' => $filename, 'mime_type' => $claimed, 'size' => strlen($content), 'disk' => 'local', 'path' => $path]]])->save();

        return [$email->fresh(), $path];
    }

    public function test_every_route_refuses_guests_and_members_without_the_capability(): void
    {
        [$email, $path] = $this->inboundWithFile();
        $sent = $this->outbound();
        $urls = [$this->body('inbound', $email->id), $this->body('outbound', $sent->id), $this->attachment('inbound', $email->id, 0)];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(); // a guest is sent to sign in, never shown the message
        }
        $noPanel = $this->mailUser(['admin.communications.view'], panel: false);
        $noCapability = $this->mailUser([]);
        $sendOnly = $this->mailUser(['admin.communications.send']);
        foreach ([$noPanel, $noCapability, $sendOnly] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_the_body_route_serves_sanitized_html_with_the_exact_policy_and_no_caching(): void
    {
        $email = $this->inbound(['sanitized_html_body' => '<p>Hello <strong>chief</strong></p>']);

        $response = $this->actingAs($this->mailViewer())->get($this->body('inbound', $email->id))->assertOk();

        self::assertSame("sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        // Popups may escape so a link opens in a new tab; scripts and the Hub's origin are never granted.
        self::assertStringNotContainsString('allow-scripts', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('allow-same-origin', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame(MessageBodyController::CONTENT_SECURITY_POLICY, $response->headers->get('Content-Security-Policy'));
        $cache = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertSee('<p>Hello <strong>chief</strong></p>', false);
        // Nothing may inject a script (Livewire does into any response that closes </html>) into a document shown without scripts.
        self::assertStringNotContainsString('<script', (string) $response->getContent());
    }

    public function test_the_body_route_re_sanitizes_even_what_was_stored(): void
    {
        $email = $this->inbound(['sanitized_html_body' => '<p>ok</p><script>alert(1)</script><img src="https://tracker.test/p.gif" onerror="alert(2)"><a href="javascript:alert(3)">x</a><iframe src="https://evil.test"></iframe><form action="https://evil.test"><input name="a"></form><a href="https://safe.test/page">fine</a>']);

        $html = $this->actingAs($this->mailViewer())->get($this->body('inbound', $email->id))->assertOk()->getContent();

        foreach (['<script', 'onerror', 'javascript:', '<iframe', '<form', '<input', 'tracker.test', '<img'] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }
        self::assertStringContainsString('https://safe.test/page', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('target="_blank"', $html, 'a link opens in a new tab, which the sandbox policy now allows');
    }

    public function test_text_only_and_outbound_messages_are_escaped_or_sanitized(): void
    {
        $textOnly = $this->inbound(['sanitized_html_body' => null, 'text_body' => '<script>alert(1)</script> & done']);
        $sent = $this->outbound(['html_body' => '<p>Sent <b>rich</b></p><script>alert(1)</script>']);
        $viewer = $this->mailViewer();

        $this->actingAs($viewer)->get($this->body('inbound', $textOnly->id))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; &amp; done', false)->assertDontSee('<script>alert', false);
        $this->actingAs($viewer)->get($this->body('outbound', $sent->id))->assertOk()
            ->assertSee('<p>Sent <b>rich</b></p>', false)->assertDontSee('<script', false);
    }

    public function test_sensitive_outbound_text_is_served_exactly_as_stored(): void
    {
        $reset = $this->outbound(['source_type' => 'password_reset', 'text_body' => '[Sensitive account-security message omitted]', 'html_body' => null]);

        $this->actingAs($this->mailViewer())->get($this->body('outbound', $reset->id))->assertOk()
            ->assertSee('[Sensitive account-security message omitted]', false);
    }

    public function test_unknown_kinds_and_messages_are_404(): void
    {
        $viewer = $this->mailViewer();

        $this->actingAs($viewer)->get('/admin/email/messages/draft/1/body')->assertNotFound();
        $this->actingAs($viewer)->get($this->body('inbound', 999999))->assertNotFound();
        $this->actingAs($viewer)->get($this->attachment('outbound', 999999, 0))->assertNotFound();
        $this->actingAs($viewer)->get('/admin/email/messages/inbound/abc/body')->assertNotFound();
    }

    public function test_an_attachment_downloads_as_an_opaque_attachment_never_as_its_claimed_type(): void
    {
        [$email] = $this->inboundWithFile('<html><script>alert(document.cookie)</script></html>', 'invoice.png', 'image/png');

        $response = $this->actingAs($this->mailViewer())->get($this->attachment('inbound', $email->id, 0))->assertOk();

        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('invoice.png', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('<html><script>alert(document.cookie)</script></html>', $response->streamedContent());
    }

    public function test_a_hostile_filename_cannot_break_out_of_the_header(): void
    {
        [$email] = $this->inboundWithFile('x', "evil\r\nSet-Cookie: a=b\"; filename=\"../x.html");

        $response = $this->actingAs($this->mailViewer())->get($this->attachment('inbound', $email->id, 0))->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');

        self::assertStringNotContainsString("\n", $disposition);
        self::assertNotContains('a', array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()), 'no injected cookie');
        self::assertStringNotContainsString('../', $disposition);
    }

    public function test_the_index_must_exist_on_that_message(): void
    {
        [$email] = $this->inboundWithFile();
        $viewer = $this->mailViewer();

        $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, 0))->assertOk();
        $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, 1))->assertNotFound();
        $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, 99))->assertNotFound();
        $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, -1))->assertNotFound();
        $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, 'abc'))->assertNotFound();
        $this->actingAs($viewer)->get($this->attachment('inbound', $this->inbound()->id, 0))->assertNotFound();
    }

    public function test_path_traversal_and_foreign_paths_in_metadata_are_never_served(): void
    {
        $other = $this->inbound();
        Storage::disk('local')->put('inbound-email-attachments/'.hash('sha256', $other->provider_message_id).'/secret.txt', 'other message');
        Storage::disk('local')->put('outside.txt', 'outside');
        $email = $this->inbound();
        $own = 'inbound-email-attachments/'.hash('sha256', $email->provider_message_id).'/';
        $entry = fn (string $path, string $disk = 'local'): array => ['filename' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 3, 'disk' => $disk, 'path' => $path];
        $email->forceFill(['attachment_metadata' => [
            $entry($own.'../../../outside.txt'),
            $entry('outside.txt'),
            $entry('inbound-email-attachments/'.hash('sha256', $other->provider_message_id).'/secret.txt'),
            $entry($own.'sub/dir.txt'),
            $entry($own."x\0.txt"),
            $entry($own.'missing.txt'),
            $entry($own.'real.txt', 's3'),
            ['filename' => 'a.txt', 'mime_type' => 'text/plain', 'size' => 1, 'disk' => 'local'],
        ]])->save();
        Storage::disk('local')->put($own.'real.txt', 'real');
        $viewer = $this->mailViewer();

        foreach (range(0, 7) as $index) {
            $this->actingAs($viewer)->get($this->attachment('inbound', $email->id, $index))->assertNotFound();
        }
    }

    public function test_outbound_attachments_follow_the_same_rules(): void
    {
        $sent = $this->outbound();
        $path = 'outbound-email-attachments/'.$sent->id.'/'.Str::uuid();
        Storage::disk('local')->put($path, 'outbound bytes');
        $sent->forceFill(['attachment_metadata' => [
            ['filename' => 'report.pdf', 'type' => 'application/pdf', 'size' => 14, 'disk' => 'local', 'path' => $path],
            ['filename' => 'old.pdf', 'type' => 'application/pdf', 'size' => 5], // sent before files were kept
        ]])->save();
        $viewer = $this->mailViewer();

        $this->actingAs($viewer)->get($this->attachment('outbound', $sent->id, 0))->assertOk();
        $this->actingAs($viewer)->get($this->attachment('outbound', $sent->id, 1))->assertNotFound();
        // The same path under another outbound id is not this message's directory.
        $other = $this->outbound();
        $other->forceFill(['attachment_metadata' => [['filename' => 'x.pdf', 'type' => 'application/pdf', 'size' => 1, 'disk' => 'local', 'path' => $path]]])->save();
        $this->actingAs($viewer)->get($this->attachment('outbound', $other->id, 0))->assertNotFound();
    }

    public function test_files_on_an_outbound_source_nobody_has_reviewed_stay_private(): void
    {
        Storage::disk('local')->put('outbound-email-attachments/9/held', 'held');
        $email = $this->outbound(['source_type' => 'some_new_notification', 'attachment_metadata' => [['filename' => 'held.txt', 'mime_type' => 'text/plain', 'size' => 4, 'disk' => 'local', 'path' => 'outbound-email-attachments/9/held']]]);

        $this->actingAs($this->mailViewer())->get($this->attachment('outbound', $email->id, 0))->assertForbidden();
    }

    public function test_the_height_probe_cannot_load_anything_either_because_the_document_carries_the_rule(): void
    {
        $email = $this->inbound(['sanitized_html_body' => '<p>Hello</p>']);

        $html = $this->actingAs($this->mailViewer())->get($this->body('inbound', $email->id))->assertOk()->getContent();

        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="default-src \'none\'', $html);
    }

    public function test_bcc_is_never_part_of_what_these_routes_serve(): void
    {
        $author = $this->mailAdmin();
        $sent = $this->outbound(['initiated_by_user_id' => $author->id, 'bcc_recipients' => ['quiet@example.test'], 'html_body' => '<p>Body</p>']);

        foreach ([$author, $this->mailViewer()] as $user) {
            $this->actingAs($user)->get($this->body('outbound', $sent->id))->assertOk()->assertDontSee('quiet@example.test');
        }
        self::assertInstanceOf(User::class, $author);
        self::assertSame(1, OutboundEmail::query()->count());
    }
}
