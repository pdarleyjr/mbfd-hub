<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Services\Mail\MailHtmlSanitizer;
use App\Services\Mail\MailText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MailHtmlAndTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function xssPayloads(): array
    {
        return [
            'script tag' => ['<p>Hi</p><script>alert(1)</script>'],
            'inline handler' => ['<p onclick="alert(1)">Hi</p><a href="https://example.test" onmouseover="alert(1)">x</a>'],
            'javascript scheme' => ['<a href="javascript:alert(1)">click</a>'],
            'obfuscated javascript scheme' => ['<a href="jav&#x09;ascript:alert(1)">click</a><a href=" JaVaScRiPt:alert(1)">y</a>'],
            'data uri link' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">z</a>'],
            'image onerror' => ['<img src=x onerror="alert(1)">'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>'],
            'iframe' => ['<iframe src="https://evil.test"></iframe>'],
            'form' => ['<form action="https://evil.test"><input name="p"><button>Go</button></form>'],
            'style block' => ['<style>body{background:url(https://evil.test/x)}</style><p style="background:url(https://evil.test)">t</p>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test"><p>t</p>'],
            'base hijack' => ['<base href="https://evil.test/"><a href="/x">t</a>'],
            'math namespace trick' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
            'nested script in attribute' => ['<a href="https://example.test" title="x"><script>alert(1)</script>ok</a>'],
        ];
    }

    #[DataProvider('xssPayloads')]
    public function test_composer_html_never_keeps_anything_executable_or_remote(string $payload): void
    {
        $clean = strtolower((new MailHtmlSanitizer)->composer($payload));

        foreach (['<script', 'onerror', 'onclick', 'onmouseover', 'onload', 'javascript:', 'data:', '<iframe', '<form', '<input', '<style', 'style=', '<meta', '<base', '<svg', '<img', 'evil.test/x', 'refresh'] as $needle) {
            self::assertStringNotContainsString($needle, $clean, "composer() kept {$needle} from: {$payload}");
        }
    }

    #[DataProvider('xssPayloads')]
    public function test_display_html_never_keeps_anything_executable_or_remote(string $payload): void
    {
        $clean = strtolower((new MailHtmlSanitizer)->display($payload));

        foreach (['<script', 'onerror', 'onclick', 'onmouseover', 'onload', 'javascript:', 'data:', '<iframe', '<form', '<input', '<style', 'style=', '<meta', '<base', '<svg', '<img', 'evil.test/x', 'refresh'] as $needle) {
            self::assertStringNotContainsString($needle, $clean, "display() kept {$needle} from: {$payload}");
        }
    }

    public function test_safe_formatting_and_web_links_survive_with_noopener(): void
    {
        $clean = (new MailHtmlSanitizer)->composer('<p>Hello <strong>team</strong> <a href="https://example.test/a?b=1">site</a> <a href="mailto:ops@example.test">mail</a></p><ul><li>One</li></ul><blockquote>quoted</blockquote>');

        $decoded = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5);
        self::assertStringContainsString('<strong>team</strong>', $clean);
        self::assertStringContainsString('href="https://example.test/a?b=1"', $decoded);
        self::assertStringContainsString('href="mailto:ops@example.test"', $decoded);
        self::assertStringContainsString('<ul><li>One</li></ul>', $clean);
        self::assertStringContainsString('<blockquote>quoted</blockquote>', $clean);
        self::assertSame(2, substr_count($clean, 'rel="noopener noreferrer"'));
    }

    public function test_relative_and_non_web_links_lose_their_href(): void
    {
        $clean = (new MailHtmlSanitizer)->composer('<a href="/admin/secret">a</a><a href="ftp://files.test/x">b</a><a href="tel:+13055550100">c</a>');

        self::assertStringNotContainsString('/admin/secret', $clean);
        self::assertStringNotContainsString('ftp:', $clean);
        self::assertStringNotContainsString('tel:', $clean);
    }

    public function test_the_text_alternative_follows_the_html_closely(): void
    {
        $html = '<p>First line<br>second line</p><p>Visit <a href="https://example.test/page">our page</a> or <a href="https://example.test">https://example.test</a>.</p><ul><li>One</li><li>Two</li></ul><ol><li>A</li><li>B</li></ol><blockquote><p>Earlier words</p></blockquote><p>A &amp; B &lt;ok&gt;</p>';
        $text = (new MailText)->fromHtml($html);

        self::assertStringContainsString("First line\nsecond line", $text);
        self::assertStringContainsString('our page (https://example.test/page)', $text);
        self::assertStringContainsString('https://example.test.', $text);
        self::assertStringNotContainsString('(https://example.test)', $text);
        self::assertStringContainsString("- One\n- Two", $text);
        self::assertStringContainsString("1. A\n2. B", $text);
        self::assertStringContainsString('> Earlier words', $text);
        self::assertStringContainsString('A & B <ok>', $text);
        self::assertDoesNotMatchRegularExpression('/<\/?(p|div|ul|ol|li|a|br|blockquote|strong)\b/i', $text);
        self::assertDoesNotMatchRegularExpression('/\n{3,}/', $text);
    }

    public function test_text_from_html_ignores_script_and_style_even_if_present(): void
    {
        self::assertSame('Visible', (new MailText)->fromHtml('<style>p{}</style><script>alert(1)</script><p>Visible</p>'));
    }

    public function test_snippet_drops_quoted_history_and_is_bounded(): void
    {
        $text = "Thanks, see you at 9.\n\nOn Mon, Oct 26, 2026 at 8:00 AM Chief <chief@example.test> wrote:\n> Are we on?\n";
        self::assertSame('Thanks, see you at 9.', (new MailText)->snippet($text));
        self::assertSame(140, mb_strlen((new MailText)->snippet(str_repeat('word ', 100))));
        self::assertSame('From html only', (new MailText)->snippet(null, '<p>From html <b>only</b></p>'));
    }

    public function test_subjects_are_normalised_for_display_and_prefixed_once(): void
    {
        $text = new MailText;

        self::assertSame('Budget', $text->baseSubject('RE: Fwd: re: Budget'));
        self::assertSame('Re: Budget', $text->replySubject('Budget'));
        self::assertSame('Re: Budget', $text->replySubject('Re: Budget'));
        self::assertSame('RE: Budget', $text->replySubject('RE: Budget'));
        self::assertSame('Fwd: Budget', $text->forwardSubject('Budget'));
        self::assertSame('Fwd: Budget', $text->forwardSubject('Fwd: Budget'));
    }
}
