<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DepartmentUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DepartmentUpdateCtaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://www.mbfdhub.com']);
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function ctaUrls(): array
    {
        return [
            'another site is external' => ['https://example.test/page', true],
            'a look-alike host is external' => ['https://www.mbfdhub.com.example.test/page', true],
            'a path inside the Hub is internal' => ['/employee/resources', false],
            'the www host is internal' => ['https://www.mbfdhub.com/employee/resources', false],
            'the apex host is internal' => ['https://mbfdhub.com/daily', false],
            'the host check ignores case' => ['https://WWW.MBFDHUB.COM/daily', false],
            'no link is not external' => [null, false],
            'a script url is not a link at all' => ['javascript:alert(1)', false],
        ];
    }

    #[DataProvider('ctaUrls')]
    public function test_only_links_to_other_sites_open_apart(?string $ctaUrl, bool $external): void
    {
        $update = new DepartmentUpdate(['cta_url' => $ctaUrl]);

        $this->assertSame($external, $update->hasExternalCta());
    }
}
