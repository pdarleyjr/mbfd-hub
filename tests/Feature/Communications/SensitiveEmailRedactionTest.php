<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\EmailBudgetExhausted;
use App\Models\OutboundEmail;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SensitiveEmailRedactionTest extends TestCase
{
    use RefreshDatabase;

    public static function sensitiveSources(): array
    {
        return [['city_email_verification'], ['password_reset'], ['city_email_changed']];
    }

    #[DataProvider('sensitiveSources')]
    public function test_sensitive_bodies_are_not_persisted_even_when_delivery_is_blocked(string $source): void
    {
        config(['communications.cloudflare.account_id' => str_repeat('a', 32), 'communications.cloudflare.api_token' => 'test-only-token']);
        Http::fake();
        $secret = 'sensitive-proof-token-not-for-outbox';

        try {
            app(CloudflareEmailDispatcher::class)->send(
                to: ['member@miamibeachfl.gov'],
                subject: 'Account security',
                text: $secret,
                html: '<p>'.$secret.'</p>',
                sourceType: $source,
            );
            self::fail('No budget exists, so sending must remain blocked.');
        } catch (EmailBudgetExhausted) {
            $email = OutboundEmail::query()->sole();
            self::assertSame('[Sensitive account-security message omitted]', $email->text_body);
            self::assertNull($email->html_body);
            self::assertSame('failed_pre_acceptance', $email->status);
            self::assertStringNotContainsString($secret, json_encode($email->toArray(), JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
    }
}
