<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Identity\EmployeeIdentifier;
use PHPUnit\Framework\TestCase;

final class EmployeeIdentifierTest extends TestCase
{
    public function test_pasted_boundary_whitespace_is_removed_without_changing_identity(): void
    {
        self::assertSame('0019965', EmployeeIdentifier::normalize("\u{00a0}\t0019965\u{202f}\n"));
        self::assertSame('19 965', EmployeeIdentifier::normalize('19 965'));
        self::assertSame('19-965', EmployeeIdentifier::normalize('19-965'));
        self::assertSame('', EmployeeIdentifier::normalize("\u{00a0} "));
    }
}
