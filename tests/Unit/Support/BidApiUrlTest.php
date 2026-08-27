<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BidApiUrl;
use PHPUnit\Framework\TestCase;

class BidApiUrlTest extends TestCase
{
    public function test_it_maps_the_staging_console_host_to_the_staging_worker_host(): void
    {
        $this->assertSame(
            'https://api.staging.bid.mbfdhub.com',
            BidApiUrl::fromConsoleUrl('https://staging.bid.mbfdhub.com/'),
        );
    }

    public function test_it_maps_the_production_console_host_to_the_production_worker_host(): void
    {
        $this->assertSame(
            'https://api.bid.mbfdhub.com',
            BidApiUrl::fromConsoleUrl('https://bid.mbfdhub.com'),
        );
    }

    public function test_it_preserves_an_explicit_worker_host_or_an_unrelated_local_endpoint(): void
    {
        $this->assertSame(
            'https://api.bid.mbfdhub.com',
            BidApiUrl::fromConsoleUrl('https://api.bid.mbfdhub.com/'),
        );
        $this->assertSame(
            'http://localhost:8787',
            BidApiUrl::fromConsoleUrl('http://localhost:8787/'),
        );
    }
}
