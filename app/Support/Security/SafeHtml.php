<?php

declare(strict_types=1);

namespace App\Support\Security;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class SafeHtml
{
    private static ?HtmlSanitizer $reportSanitizer = null;

    public static function report(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return self::reportSanitizer()->sanitize($html);
    }

    private static function reportSanitizer(): HtmlSanitizer
    {
        if (self::$reportSanitizer instanceof HtmlSanitizer) {
            return self::$reportSanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['https', 'mailto', 'tel'])
            ->allowMediaSchemes(['https', 'data'])
            ->forceHttpsUrls()
            ->withMaxInputLength(250_000);

        foreach (['table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element, ['class']);
        }

        $config = $config
            ->allowAttribute('class', '*')
            ->allowAttribute('colspan', ['th', 'td'])
            ->allowAttribute('rowspan', ['th', 'td'])
            ->allowAttribute('scope', ['th'])
            ->allowAttribute('href', ['a'])
            ->allowAttribute('src', ['img'])
            ->allowAttribute('alt', ['img'])
            ->allowAttribute('title', ['a', 'img'])
            ->allowAttribute('width', ['img'])
            ->allowAttribute('height', ['img'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        return self::$reportSanitizer = new HtmlSanitizer($config);
    }
}
