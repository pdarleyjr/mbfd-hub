<?php

namespace Tests\Unit\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use App\Services\VideoConferencing\ConferenceIdentityService;
use PHPUnit\Framework\TestCase;

class ConferenceIdentityServiceTest extends TestCase
{
    public function test_it_exposes_only_the_approved_join_roles(): void
    {
        $this->assertSame(
            ['self', '300', 'sta1', 'sta2', 'sta3', 'sta4', 'sta6'],
            array_column(ConferenceJoinRole::cases(), 'value'),
        );
    }

    public function test_it_never_duplicates_a_rank_already_present_in_the_employee_name(): void
    {
        $employee = new Employee(['name' => 'Captain Taylor Morgan', 'rank' => 'Captain']);

        $this->assertSame(
            'Captain Taylor Morgan',
            (new ConferenceIdentityService)->displayName($employee),
        );
    }

    public function test_it_prefixes_rank_when_the_name_does_not_already_include_it(): void
    {
        $employee = new Employee(['name' => 'Taylor Morgan', 'rank' => 'Captain']);

        $this->assertSame(
            'Captain Taylor Morgan',
            (new ConferenceIdentityService)->displayName($employee),
        );
    }
}
