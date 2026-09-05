<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class InboundEmailAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signed_message_is_persisted_and_replay_is_blocked(): void
    {
        config()->set('communications.inbound.secret', 'test-inbound-secret');
        Storage::fake('local');
        $payload = json_encode([
            'message_id' => '<inbound-1@example.test>',
            'from' => 'sender@example.test',
            'to' => 'info@mbfdhub.com',
            'subject' => 'Operational question',
            'text' => 'Please review.',
            'html' => '<p>Please review.</p><img src="https://tracker.example.test/pixel">',
            'received_at' => '2026-09-04T12:00:00Z',
            'attachments' => [[
                'filename' => 'operational-note.txt',
                'mime_type' => 'text/plain',
                'content' => base64_encode('private attachment'),
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = 'inbound-message-1';
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$payload, 'test-inbound-secret');
        $headers = [
            'Content-Type' => 'application/json',
            'X-MBFD-Timestamp' => $timestamp,
            'X-MBFD-Nonce' => $nonce,
            'X-MBFD-Signature' => $signature,
        ];

        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders($headers), $payload)
            ->assertCreated();
        $this->assertDatabaseHas('inbound_emails', [
            'provider_message_id' => '<inbound-1@example.test>',
            'from_address' => 'sender@example.test',
            'to_address' => 'info@mbfdhub.com',
        ]);
        $stored = (string) \App\Models\InboundEmail::query()->sole()->sanitized_html_body;
        self::assertStringNotContainsString('tracker.example.test', $stored);
        $attachment = \App\Models\InboundEmail::query()->sole()->attachment_metadata[0];
        self::assertSame('operational-note.txt', $attachment['filename']);
        self::assertSame('local', $attachment['disk']);
        self::assertStringStartsWith('inbound-email-attachments/', $attachment['path']);
        Storage::disk('local')->assertExists($attachment['path']);
        Storage::disk('public')->assertMissing($attachment['path']);

        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders($headers), $payload)
            ->assertConflict();
    }

    public function test_unsafe_or_oversized_inbound_attachment_fails_closed(): void
    {
        config()->set('communications.inbound.secret', 'test-inbound-secret');
        config()->set('communications.inbound.max_attachment_bytes', 2);
        Storage::fake('local');
        $payload = json_encode([
            'message_id' => '<attachment-too-large@example.test>',
            'from' => 'sender@example.test',
            'to' => 'info@mbfdhub.com',
            'received_at' => now()->toIso8601String(),
            'attachments' => [[
                'filename' => 'note.txt',
                'mime_type' => 'text/plain',
                'content' => base64_encode('three bytes'),
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = 'oversized-attachment';
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$payload, 'test-inbound-secret');

        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders([
            'Content-Type' => 'application/json',
            'X-MBFD-Timestamp' => $timestamp,
            'X-MBFD-Nonce' => $nonce,
            'X-MBFD-Signature' => $signature,
        ]), $payload)->assertStatus(413);

        $this->assertDatabaseCount('inbound_emails', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_oversized_payload_fails_before_signature_processing(): void
    {
        config()->set('communications.inbound.secret', 'test-inbound-secret');
        config()->set('communications.inbound.max_bytes', 64);
        $payload = json_encode(['message_id' => 'bad', 'from' => 'a@b.test', 'to' => 'info@mbfdhub.com', 'text' => str_repeat('x', 100)], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders([
            'Content-Type' => 'application/json',
            'X-MBFD-Timestamp' => (string) now()->timestamp,
            'X-MBFD-Nonce' => 'bad-signature',
            'X-MBFD-Signature' => str_repeat('0', 64),
        ]), $payload)->assertStatus(413);

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    public function test_invalid_signature_and_unconfigured_recipient_fail_closed(): void
    {
        config()->set('communications.inbound.secret', 'test-inbound-secret');
        $timestamp = (string) now()->timestamp;
        $invalid = json_encode([
            'message_id' => 'bad-signature',
            'from' => 'a@b.test',
            'to' => 'info@mbfdhub.com',
            'received_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders([
            'Content-Type' => 'application/json',
            'X-MBFD-Timestamp' => $timestamp,
            'X-MBFD-Nonce' => 'invalid-signature',
            'X-MBFD-Signature' => str_repeat('0', 64),
        ]), $invalid)->assertForbidden();

        $wrongRecipient = json_encode([
            'message_id' => 'wrong-recipient',
            'from' => 'a@b.test',
            'to' => 'other@mbfdhub.com',
            'received_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $nonce = 'wrong-recipient';
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$wrongRecipient, 'test-inbound-secret');
        $this->call('POST', '/api/v2/email/inbound', [], [], [], $this->serverHeaders([
            'Content-Type' => 'application/json',
            'X-MBFD-Timestamp' => $timestamp,
            'X-MBFD-Nonce' => $nonce,
            'X-MBFD-Signature' => $signature,
        ]), $wrongRecipient)->assertUnprocessable();

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    /** @param array<string, string> $headers */
    private function serverHeaders(array $headers): array
    {
        return collect($headers)->mapWithKeys(fn (string $value, string $key): array => [
            'HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value,
        ])->all();
    }
}
