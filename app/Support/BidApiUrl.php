<?php

declare(strict_types=1);

namespace App\Support;

final class BidApiUrl
{
    public static function fromConsoleUrl(string $consoleUrl): string
    {
        $url = rtrim($consoleUrl, '/');

        // The bid console and Worker use paired MBFD hostnames in staging and
        // production. Leave explicit Worker hosts and local/custom endpoints intact.
        return preg_replace(
            '#^(https?://)(?:api\\.)?(staging\\.)?bid\\.mbfdhub\\.com(?=[:/]|$)#i',
            '$1api.$2bid.mbfdhub.com',
            $url,
        ) ?? $url;
    }
}
