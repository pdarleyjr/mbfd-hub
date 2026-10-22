<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Renders untrusted plain text as safe HTML: everything is escaped, line
 * breaks are preserved, and only http:// and https:// URLs become links
 * (opened in a new tab with noopener noreferrer nofollow). The server never
 * fetches a linked URL.
 */
final class SafeLinkifier
{
    private const URL_PATTERN = '~https?://[^\s<>"\'`\x00-\x1f\x7f]+~iu';

    private const TRAILING_PUNCTUATION = '.,;:!?\'")]}>';

    private const DISPLAY_LIMIT = 80;

    public static function render(?string $text): HtmlString
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $pieces = preg_split(self::URL_PATTERN, $text) ?: [$text];
        preg_match_all(self::URL_PATTERN, $text, $matches);
        $urls = $matches[0];

        $html = '';
        foreach ($pieces as $index => $piece) {
            $html .= self::text($piece);
            if (! isset($urls[$index])) {
                continue;
            }
            [$url, $tail] = self::splitTrailingPunctuation($urls[$index]);
            $html .= self::isSafeUrl($url) ? self::link($url) : self::text($url);
            $html .= self::text($tail);
        }

        return new HtmlString($html);
    }

    private static function text(string $value): string
    {
        return nl2br(e($value), false);
    }

    /** @return array{0: string, 1: string} */
    private static function splitTrailingPunctuation(string $url): array
    {
        $trimmed = rtrim($url, self::TRAILING_PUNCTUATION);

        return [$trimmed, substr($url, strlen($trimmed))];
    }

    private static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            // Never link credential-bearing URLs (matches the React linkifier).
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    private static function link(string $url): string
    {
        $label = mb_strlen($url) > self::DISPLAY_LIMIT ? mb_substr($url, 0, self::DISPLAY_LIMIT - 1).'…' : $url;

        return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer nofollow" class="underline break-all">'.e($label).'</a>';
    }
}
