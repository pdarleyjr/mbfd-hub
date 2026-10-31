<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Services\Mail\AddressParser;
use Tests\TestCase;

final class AddressParserTest extends TestCase
{
    public function test_a_comma_inside_a_quoted_display_name_is_not_a_separator(): void
    {
        $parsed = (new AddressParser)->parse('"Doe, John" <john@example.test>, Mary Smith <mary@example.test>');

        self::assertSame([
            ['address' => 'john@example.test', 'name' => 'Doe, John'],
            ['address' => 'mary@example.test', 'name' => 'Mary Smith'],
        ], $parsed);
    }

    public function test_bare_addresses_comments_groups_and_arrays_are_understood(): void
    {
        $parser = new AddressParser;

        self::assertSame(['a@example.test', 'b@example.test'], $parser->addresses('A@Example.test (Ops desk); b@example.test'));
        self::assertSame(['a@example.test'], $parser->addresses('Duty Officers: a@example.test;'));
        self::assertSame(['x@example.test', 'y@example.test'], $parser->addresses(['X <x@example.test>', ['address' => 'Y@example.test', 'name' => 'Why']]));
        self::assertSame([], $parser->addresses('undisclosed-recipients:;'));
    }

    public function test_duplicates_collapse_case_insensitively_and_keep_the_first_known_name(): void
    {
        $parsed = (new AddressParser)->parse(['one@example.test', 'One <ONE@example.test>']);

        self::assertSame([['address' => 'one@example.test', 'name' => 'One']], $parsed);
    }

    public function test_invalid_and_header_injecting_values_are_dropped(): void
    {
        $parser = new AddressParser;

        self::assertSame([], $parser->addresses(['not-an-address', 'a@b', "evil@example.test\r\nBcc: victim@example.test", 'x y@example.test', '<>', null, 42, '']));
        self::assertSame(['ok@example.test'], $parser->addresses(['ok@example.test', 'bad<script>@example.test']));
    }

    public function test_display_names_lose_control_characters(): void
    {
        $parsed = (new AddressParser)->parse("\"Line\r\nBreak\" <ok@example.test>");

        self::assertSame('Line Break', $parsed[0]['name']);
    }
}
