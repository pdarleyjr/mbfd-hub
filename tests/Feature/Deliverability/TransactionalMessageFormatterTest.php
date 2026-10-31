<?php

declare(strict_types=1);

namespace Tests\Feature\Deliverability;

use App\Support\Mail\TransactionalMessageFormatter;
use DOMDocument;
use Tests\TestCase;

final class TransactionalMessageFormatterTest extends TestCase
{
    private const FRAGMENT = '<h1>Station request</h1><p>Body &amp; detail</p><p><a href="https://www.mbfdhub.com/admin?x=1&amp;y=2">View in MBFD Hub</a></p>';

    private function formatter(): TransactionalMessageFormatter
    {
        return app(TransactionalMessageFormatter::class);
    }

    public function test_the_text_sign_off_is_appended_and_the_original_text_is_untouched(): void
    {
        $original = "Use this link: https://www.mbfdhub.com/reset-password/abc%2Fdef?employee_id=1001\n\nIf you did not ask, ignore this.";

        $signed = $this->formatter()->signOffText($original);

        self::assertStringStartsWith($original, $signed);
        self::assertSame($original."\n\n--\nMiami Beach Fire Department\nMBFD Hub", $signed);
        self::assertLessThanOrEqual(4, substr_count($signed, "\n") - substr_count($original, "\n"));
    }

    public function test_the_organization_name_follows_configuration(): void
    {
        config(['communications.cloudflare.from_name' => 'Example Fire Rescue']);

        self::assertStringContainsString('Example Fire Rescue', $this->formatter()->signOffText('Hello'));
    }

    public function test_a_blank_configured_name_still_identifies_the_sender(): void
    {
        config(['communications.cloudflare.from_name' => '']);

        self::assertStringContainsString('Miami Beach Fire Department', $this->formatter()->signOffText('Hello'));
    }

    public function test_a_notification_gets_a_sign_off_in_text_and_a_valid_document_in_html(): void
    {
        $text = "Body & detail\n\nView in MBFD Hub: https://www.mbfdhub.com/admin?x=1&y=2";

        $out = $this->formatter()->notification(['subject' => 'Station request', 'text' => $text, 'html' => self::FRAGMENT]);

        self::assertSame('Station request', $out['subject']);
        self::assertStringStartsWith($text."\n\n--\n", $out['text']);
        self::assertStringContainsString('Miami Beach Fire Department', $out['text']);
        self::assertStringContainsString('You are receiving this', $out['text']);

        self::assertStringStartsWith('<!DOCTYPE html>', $out['html']);
        self::assertStringContainsString('<html lang="en">', $out['html']);
        self::assertStringContainsString('<meta charset="utf-8">', $out['html']);
        self::assertStringContainsString('<title>Station request</title>', $out['html']);
        self::assertStringContainsString(self::FRAGMENT, $out['html'], 'existing content must be kept byte for byte');
        self::assertSame(1, substr_count($out['html'], 'You are receiving this'));
        self::assertSame(1, substr_count($out['html'], '<body'));
    }

    public function test_the_html_is_well_formed_without_images_tracking_or_promotional_parts(): void
    {
        $out = $this->formatter()->notification(['subject' => 'S', 'text' => 'T', 'html' => self::FRAGMENT]);

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        self::assertTrue($document->loadHTML($out['html']));
        $problems = array_filter(libxml_get_errors(), fn (\LibXMLError $error): bool => $error->level >= LIBXML_ERR_ERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertSame([], array_values($problems));
        self::assertSame('en', $document->documentElement?->getAttribute('lang'));
        foreach (['img', 'script', 'iframe', 'link', 'form', 'video'] as $tag) {
            self::assertSame(0, $document->getElementsByTagName($tag)->length, "<{$tag}> must not appear");
        }
        foreach (['unsubscribe', 'pixel', 'utm_', 'tracking'] as $word) {
            self::assertStringNotContainsString($word, strtolower($out['html']));
        }
        self::assertSame(1, $document->getElementsByTagName('h1')->length);
    }

    public function test_the_subject_is_escaped_in_the_title(): void
    {
        $out = $this->formatter()->notification(['subject' => 'A <b>&"quote"', 'text' => 'T', 'html' => '<p>x</p>']);

        self::assertStringContainsString('<title>A &lt;b&gt;&amp;&quot;quote&quot;</title>', $out['html']);
        self::assertStringNotContainsString('<b>', $out['html']);
    }

    public function test_a_text_only_notification_stays_text_only(): void
    {
        $out = $this->formatter()->notification(['subject' => 'Equipment damaged', 'text' => 'Plain body']);

        self::assertArrayNotHasKey('html', $out);
        self::assertStringStartsWith("Plain body\n\n--\n", $out['text']);
    }

    public function test_a_null_html_stays_null(): void
    {
        $out = $this->formatter()->notification(['subject' => 'S', 'text' => 'T', 'html' => null]);

        self::assertNull($out['html']);
    }
}
