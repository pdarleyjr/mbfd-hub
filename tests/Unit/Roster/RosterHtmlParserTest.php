<?php

declare(strict_types=1);

namespace Tests\Unit\Roster;

use App\Services\Roster\RosterHtmlParser;
use PHPUnit\Framework\TestCase;

final class RosterHtmlParserTest extends TestCase
{
    public function test_it_reads_exact_employee_ids_without_inventing_email_addresses(): void
    {
        $html = <<<'HTML'
        <table><thead><tr><th>Employee ID</th><th>Name</th><th>Rank</th><th>Assignment</th></tr></thead>
        <tbody>
        <tr><td>20731</td><td>Peter Darley Jr.</td><td>Firefighter</td><td>Station 2</td></tr>
        <tr><td>16584</td><td>Darryl Bell</td><td>Captain</td><td>Historical</td></tr>
        </tbody></table>
        HTML;

        $rows = (new RosterHtmlParser)->parse($html);

        self::assertCount(2, $rows);
        self::assertSame('20731', $rows[0]['employee_id']);
        self::assertSame('Peter Darley Jr.', $rows[0]['name']);
        self::assertNull($rows[0]['city_email']);
    }

    public function test_duplicate_employee_ids_are_rejected(): void
    {
        $html = '<table><tr><th>Employee ID</th><th>Name</th></tr><tr><td>1</td><td>One</td></tr><tr><td>1</td><td>Duplicate</td></tr></table>';

        $this->expectException(\InvalidArgumentException::class);
        (new RosterHtmlParser)->parse($html);
    }
}
