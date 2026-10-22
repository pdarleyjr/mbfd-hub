<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SafeLinkifier;
use PHPUnit\Framework\TestCase;

final class SafeLinkifierTest extends TestCase
{
    public function test_it_escapes_html_in_text(): void
    {
        $html = SafeLinkifier::render('<script>alert(1)</script> & "quotes" <b>bold</b>')->toHtml();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;', $html);
    }

    public function test_it_links_http_and_https_urls_with_safe_attributes(): void
    {
        $html = SafeLinkifier::render('See https://example.test/a?b=1&c=2 and http://example.test/x for details')->toHtml();

        $this->assertStringContainsString('<a href="https://example.test/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('<a href="http://example.test/x" target="_blank" rel="noopener noreferrer nofollow"', $html);
        $this->assertSame(2, substr_count($html, '<a '));
    }

    public function test_it_never_links_javascript_data_or_other_schemes(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'ftp://example.test/file', 'file:///etc/passwd', 'vbscript:msgbox(1)', '//evil.test/path', 'www.example.test'] as $text) {
            $html = SafeLinkifier::render($text)->toHtml();
            $this->assertStringNotContainsString('<a ', $html, $text);
            $this->assertStringNotContainsString('href=', $html, $text);
        }
    }

    public function test_url_cannot_break_out_of_the_href_attribute(): void
    {
        $html = SafeLinkifier::render('https://example.test/"onmouseover="alert(1) and https://example.test/?q=\'x\'')->toHtml();

        $this->assertStringNotContainsString('onmouseover="', $html);
        $this->assertStringNotContainsString('<a href="https://example.test/"onmouseover', $html);
        $this->assertSame(0, preg_match('/<a [^>]*\son\w+=/i', $html));
    }

    public function test_trailing_punctuation_stays_outside_the_link_and_line_breaks_are_preserved(): void
    {
        $html = SafeLinkifier::render("Read https://example.test/page.\nThen call (see https://example.test/b).\r\nDone")->toHtml();

        $this->assertStringContainsString('href="https://example.test/page"', $html);
        $this->assertStringContainsString('href="https://example.test/b"', $html);
        $this->assertSame(2, substr_count($html, '<br'));
    }

    public function test_long_urls_are_shortened_for_display_only(): void
    {
        $url = 'https://example.test/'.str_repeat('a', 200);
        $html = SafeLinkifier::render($url)->toHtml();

        $this->assertStringContainsString('href="'.$url.'"', $html);
        $this->assertStringContainsString('…</a>', $html);
    }

    public function test_credential_bearing_urls_are_shown_as_text_not_links(): void
    {
        $html = SafeLinkifier::render('see https://user:secret@example.com/path')->toHtml();

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('https://user:secret@example.com/path', $html);
    }

    public function test_null_and_empty_input_render_nothing(): void
    {
        $this->assertSame('', SafeLinkifier::render(null)->toHtml());
        $this->assertSame('', SafeLinkifier::render('')->toHtml());
    }
}
