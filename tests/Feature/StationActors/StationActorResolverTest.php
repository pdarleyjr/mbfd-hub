<?php

declare(strict_types=1);

namespace Tests\Feature\StationActors;

use App\Data\StationActors\VerifiedHumanStationActor;
use App\Models\Employee;
use App\Models\User;
use App\Services\StationActors\StationActorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use ReflectionClass;
use Tests\TestCase;

class StationActorResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_derives_a_verified_human_only_from_the_employee_session_guard(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'F042',
            'name' => 'Taylor Morgan',
            'rank' => 'Firefighter',
            'password' => 'StationActor!1',
            'must_change_password' => false,
        ]);

        $this->actingAs($employee, 'employee');

        $actor = app(StationActorResolver::class)->resolveVerifiedHuman();

        $this->assertInstanceOf(VerifiedHumanStationActor::class, $actor);
        $this->assertSame((int) $employee->getKey(), $actor->employeeRecordId);
        $this->assertSame('F042', $employee->employee_id);
        $this->assertObjectNotHasProperty('employeeId', $actor);
        $this->assertTrue((new ReflectionClass($actor))->isReadOnly());
    }

    public function test_untrusted_body_pin_signed_url_and_launch_context_cannot_resolve_an_actor(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'F043',
            'name' => 'Morgan Taylor',
            'rank' => 'Lieutenant',
            'password' => 'StationActor!1',
            'must_change_password' => false,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'api.v2.station-inventory.access',
            now()->addHour(),
            [
                'stationId' => 3,
                'actor_name' => $employee->name,
                'actor_shift' => 'A-Day',
            ],
        );
        $request = Request::create($signedUrl, 'POST', [
            'employee_id' => $employee->employee_id,
            'employee_name' => $employee->name,
            'actor_name' => $employee->name,
            'actor_shift' => 'A-Day',
            'pin' => '1234',
            'station_id' => 3,
            'station_role' => 'station_captain',
            'role' => 'captain',
            'device_id' => 'station-3-tablet',
            'launch_context' => 'station-inventory',
        ]);
        $request->setLaravelSession($this->app['session.store']);
        $this->app->instance('request', $request);
        session()->put('station.launch.employee_id', $employee->id);
        session()->put('station.launch.device_id', 'station-3-tablet');

        $resolver = app(StationActorResolver::class);

        $this->assertNull($resolver->resolveVerifiedHuman());
        $this->assertNull($resolver->resolveVerifiedDevice());
    }

    public function test_a_non_employee_session_cannot_be_reclassified_as_a_station_human_or_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'employee');

        $resolver = app(StationActorResolver::class);

        $this->assertNull($resolver->resolveVerifiedHuman());
        $this->assertNull($resolver->resolveVerifiedDevice());
    }
}
