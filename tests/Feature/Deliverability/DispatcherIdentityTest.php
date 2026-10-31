<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Deliverability\Concerns\SendsThroughCloudflareFake;
use Tests\TestCase;

/** Sender identity, the Auto-Submitted header and the blank-subject guard. Nothing here reaches a real provider. */
final class DispatcherIdentityTest extends TestCase
{
    use RefreshDatabase;
    use SendsThroughCloudflareFake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareSending();
    }

    public function test_from_carries_a_display_name_and_the_stored_address_stays_bare(): void
    {
        $email = $this->send('test');

        self::assertSame(['address' => 'info@mbfdhub.com', 'name' => 'Miami Beach Fire Department'], $this->onlyRequest()['from']);
        self::assertSame('info@mbfdhub.com', $email->from_address);
        self::assertSame('info@mbfdhub.com', OutboundEmail::query()->sole()->from_address);
    }

    public function test_the_display_name_is_configurable_and_cleaned(): void
    {
        config(['communications.cloudflare.from_name' => "  MBFD\r\nBcc: attacker@example.test \x00 Hub  "]);

        $this->send('test');

        $name = $this->onlyRequest()['from']['name'];
        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString("\r", $name);
        self::assertStringNotContainsString("\x00", $name);
        self::assertSame($name, trim($name));
    }

    #[DataProvider('blankNames')]
    public function test_a_blank_display_name_falls_back_to_the_bare_address(?string $name): void
    {
        config(['communications.cloudflare.from_name' => $name]);

        $this->send('test');

        self::assertSame('info@mbfdhub.com', $this->onlyRequest()['from']);
    }

    /** @return array<string, array{?string}> */
    public static function blankNames(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'null' => [null]];
    }

    #[DataProvider('systemSources')]
    public function test_system_generated_mail_is_marked_auto_generated(string $sourceType): void
    {
        $this->send($sourceType);

        self::assertSame(['Auto-Submitted' => 'auto-generated'], $this->onlyRequest()['headers']);
    }

    /** @return array<string, array{string}> */
    public static function systemSources(): array
    {
        return [
            'password reset' => ['password_reset'],
            'activation notice' => ['member_self_service_activation'],
            'onboarding' => ['member_onboarding_invitation'],
            'notification class' => [\App\Notifications\NewSubmissionNotification::class],
            'unclassified' => ['test'],
        ];
    }

    #[DataProvider('humanSources')]
    public function test_human_composed_mail_never_carries_auto_submitted(string $sourceType): void
    {
        $this->send($sourceType, ['In-Reply-To' => '<parent@example.test>', 'References' => '<parent@example.test>']);

        $headers = $this->onlyRequest()['headers'];
        self::assertArrayNotHasKey('Auto-Submitted', $headers);
        self::assertSame(['In-Reply-To' => '<parent@example.test>', 'References' => '<parent@example.test>'], $headers);
    }

    /** @return array<string, array{string}> */
    public static function humanSources(): array
    {
        return ['compose' => ['admin_compose'], 'reply' => ['admin_reply'], 'forward' => ['admin_forward']];
    }

    public function test_system_mail_keeps_the_threading_headers_beside_auto_submitted(): void
    {
        $this->send('test', ['In-Reply-To' => '<parent@example.test>']);

        self::assertSame(['In-Reply-To' => '<parent@example.test>', 'Auto-Submitted' => 'auto-generated'], $this->onlyRequest()['headers']);
    }

    public function test_a_caller_cannot_mark_human_mail_auto_submitted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->send('admin_compose', ['Auto-Submitted' => 'auto-generated']);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('outbound_emails', 0);
        }
    }

    #[DataProvider('refusedHeaders')]
    public function test_every_other_header_stays_refused(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->send('test', [$name => $value]);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('outbound_emails', 0);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function refusedHeaders(): array
    {
        return [
            'unsubscribe' => ['List-Unsubscribe', '<mailto:info@mbfdhub.com>'],
            'precedence' => ['Precedence', 'bulk'],
            'x header' => ['X-Anything', 'value'],
            'unknown auto-submitted value' => ['Auto-Submitted', 'yes'],
            'injected threading value' => ['In-Reply-To', "<a@b.test>\r\nBcc: x@example.test"],
        ];
    }

    #[DataProvider('blankSubjects')]
    public function test_a_blank_subject_is_rejected_before_any_row_or_request(string $subject): void
    {
        try {
            app(CloudflareEmailDispatcher::class)->send(['member@miamibeachfl.gov'], $subject, 'Body', null, 'test');
            self::fail('A blank subject must be refused.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('outbound_emails', 0);
        }
    }

    /** @return array<string, array{string}> */
    public static function blankSubjects(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'whitespace controls' => ["\t\r\n "]];
    }

    /** @param array<string, string> $headers */
    private function send(string $sourceType, array $headers = []): OutboundEmail
    {
        return app(CloudflareEmailDispatcher::class)->send(
            ['member@miamibeachfl.gov'], 'Deliverability test', 'Body', null, $sourceType, headers: $headers,
        );
    }
}
