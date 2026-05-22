<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Support\Security\SafeHtml;
use PHPUnit\Framework\TestCase;

final class SafeHtmlTest extends TestCase
{
    public function test_strips_script_tags_and_event_handlers(): void
    {
        $dirty = '<p>Hello</p><script>alert(1)</script><img src="x" onerror="alert(1)">';
        $clean = SafeHtml::report($dirty);

        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('alert(', $clean);
        self::assertStringContainsString('<p>Hello</p>', $clean);
    }

    public function test_blocks_javascript_uri_in_anchor(): void
    {
        $dirty = '<a href="javascript:alert(1)">click</a>';
        $clean = SafeHtml::report($dirty);

        self::assertStringNotContainsString('javascript:', $clean);
    }

    public function test_preserves_table_markup_used_by_saver_reports(): void
    {
        $dirty = '<table class="report"><thead><tr><th scope="col">Tool</th></tr></thead>'
            . '<tbody><tr><td colspan="1">Hurst</td></tr></tbody></table>';

        $clean = SafeHtml::report($dirty);

        self::assertStringContainsString('<table', $clean);
        self::assertStringContainsString('<thead>', $clean);
        self::assertStringContainsString('<th', $clean);
        self::assertStringContainsString('Hurst', $clean);
    }

    public function test_returns_empty_string_for_null_or_blank(): void
    {
        self::assertSame('', SafeHtml::report(null));
        self::assertSame('', SafeHtml::report(''));
        self::assertSame('', SafeHtml::report("\n  \t"));
    }

    public function test_strips_iframe_and_object_embedding(): void
    {
        $dirty = '<iframe src="https://evil.example/exfil"></iframe>'
            . '<object data="x.swf"></object>';

        $clean = SafeHtml::report($dirty);

        self::assertStringNotContainsString('<iframe', $clean);
        self::assertStringNotContainsString('<object', $clean);
    }
}
