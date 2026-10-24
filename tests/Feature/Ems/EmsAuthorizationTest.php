<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsAccess;
use App\Services\Security\Captain5Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class EmsAuthorizationTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $member;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEmsWorld();
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->uuid = $this->submitRequest($this->member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4]])['uuid'];
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    /** @return array<string, callable(User): TestResponse> every staff read */
    private function reads(): array
    {
        $item = $this->supplyItem('sr.t.02')->id;

        return [
            'meta' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/meta'),
            'requests' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/requests'),
            'request' => fn (User $u) => $this->actingAs($u)->getJson("/ems/api/requests/{$this->uuid}"),
            'items' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/items'),
            'item' => fn (User $u) => $this->actingAs($u)->getJson("/ems/api/supply/items/{$item}"),
            'locations' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/locations'),
            'counts' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/counts'),
            'reconciliation' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/reconciliation'),
            'advisories' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/advisories'),
            'orders' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/orders'),
            'movements' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/movements'),
            'snapshots' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/supply/snapshots'),
            'statistics' => fn (User $u) => $this->actingAs($u)->getJson('/ems/api/statistics'),
        ];
    }

    /** @return array<string, callable(User): TestResponse> every staff write that needs "act" */
    private function writes(): array
    {
        $item = $this->supplyItem('sr.t.02')->id;
        $key = fn (): array => ['Idempotency-Key' => 'auth-'.Str::random(16)];

        return [
            'review' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/review", [], $key()),
            'prepare' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/prepare", ['lines' => [['line_id' => 1, 'prepared_qty' => 1]]], $key()),
            'issue' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/issue", ['issuances' => [['line_id' => 1, 'quantity' => 1]]], $key()),
            'not-issued' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/not-issued", ['line_id' => 1, 'reason' => 'no'], $key()),
            'close' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/close", ['reason' => 'done'], $key()),
            'reopen' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/reopen", ['reason' => 'again'], $key()),
            'correct' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/correct", ['event_id' => 1, 'new_quantity' => 1, 'reason' => 'x y'], $key()),
            'message' => fn (User $u) => $this->actingAs($u)->postJson("/ems/api/requests/{$this->uuid}/messages", ['body' => 'hello'], $key()),
            'count-start' => fn (User $u) => $this->actingAs($u)->postJson('/ems/api/supply/counts', [], $key()),
            'reconcile' => fn (User $u) => $this->actingAs($u)->postJson('/ems/api/supply/reconciliation', [], $key()),
            'order' => fn (User $u) => $this->actingAs($u)->postJson('/ems/api/supply/orders', ['supply_item_id' => $item, 'packages' => 1], $key()),
            'adjust' => fn (User $u) => $this->actingAs($u)->postJson('/ems/api/supply/adjustments', ['supply_item_id' => $item, 'quantity_base' => 1, 'reason' => 'abc'], $key()),
        ];
    }

    private function configure(User $u, int $threshold = 9): TestResponse
    {
        return $this->actingAs($u)->patchJson('/ems/api/supply/items/'.$this->supplyItem('sr.t.04')->id, ['threshold_qty_base' => $threshold, 'reason' => 'Because'], ['Idempotency-Key' => 'cfg-'.Str::random(16)]);
    }

    public function test_anonymous_callers_are_turned_away_with_json(): void
    {
        $this->logoutCanonicalSession();
        $this->getJson('/ems/api/requests')->assertUnauthorized();
        $this->postJson("/ems/api/requests/{$this->uuid}/issue", [])->assertUnauthorized();
        $this->getJson('/employee/forms/api/ems/form')->assertUnauthorized();
        $this->postJson('/employee/forms/api/ems/requests', [])->assertUnauthorized();
    }

    public function test_a_plain_member_has_no_staff_access_to_anything(): void
    {
        $other = $this->emsMember('Firefighter', 'Other Member');
        foreach ([$this->member, $other] as $user) {
            foreach ($this->reads() as $name => $read) {
                $read($user)->assertForbidden();
            }
            foreach ($this->writes() as $name => $write) {
                $write($user)->assertForbidden();
            }
            $this->configure($user)->assertForbidden();
        }
        $this->assertSame('submitted', $this->requestModel($this->uuid)->status);
    }

    public function test_a_member_can_use_the_member_form_and_nothing_of_another_members(): void
    {
        $other = $this->emsMember('Firefighter', 'Other Member');
        $this->actingAs($other)->getJson('/employee/forms/api/ems/form')->assertOk();
        $this->actingAs($other)->getJson('/employee/forms/api/ems/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/employee/forms/api/ems/requests/{$this->uuid}")->assertNotFound();
        $this->emsPost($other, "/employee/forms/api/ems/requests/{$this->uuid}/messages", ['body' => 'hi'])->assertNotFound();
        $this->emsPost($other, "/employee/forms/api/ems/requests/{$this->uuid}/cancel", [])->assertNotFound();
        $this->assertSame('submitted', $this->requestModel($this->uuid)->status);
        $this->assertSame(0, $this->requestModel($this->uuid)->messages()->count());
    }

    public function test_view_only_admin_can_read_everything_and_write_nothing(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        foreach ($this->reads() as $name => $read) {
            $this->assertSame(200, $read($viewer)->status(), "read {$name}");
        }
        foreach ($this->writes() as $name => $write) {
            $this->assertSame(403, $write($viewer)->status(), "write {$name}");
        }
        $this->configure($viewer)->assertForbidden();
        $this->assertSame(['view' => true, 'act' => false, 'configure' => false], $this->actingAs($viewer)->getJson('/ems/api/meta')->json('data.permissions'));
        $this->assertSame('submitted', $this->requestModel($this->uuid)->status);
    }

    public function test_ems_manage_admin_can_read_act_and_configure_as_ems_admin(): void
    {
        $manager = $this->emsAdmin(['admin.ems.manage'], 'Ema Nager');
        foreach ($this->reads() as $name => $read) {
            $this->assertSame(200, $read($manager)->status(), "read {$name}");
        }
        $this->emsPost($manager, "/ems/api/requests/{$this->uuid}/review")->assertOk();
        $this->emsPost($manager, "/ems/api/requests/{$this->uuid}/messages", ['body' => 'Looking at it'])->assertCreated()->assertJsonPath('data.author_context', 'ems_admin');
        $this->configure($manager)->assertOk();
        $this->assertSame('ems_admin', $this->requestModel($this->uuid)->events()->where('action', 'reviewed')->sole()->actor_context);
        $this->assertSame(['view' => true, 'act' => true, 'configure' => true], $this->actingAs($manager)->getJson('/ems/api/meta')->json('data.permissions'));
    }

    public function test_the_admin_ems_grants_only_count_together_with_admin_access(): void
    {
        $user = $this->emsMember();
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('admin.ems.manage', 'web'));
        $this->forgetEmsMemo();

        foreach ($this->reads() as $name => $read) {
            $read($user->fresh())->assertForbidden();
        }
        $this->emsPost($user->fresh(), "/ems/api/requests/{$this->uuid}/review")->assertForbidden();
    }

    public function test_a_captain_reads_and_acts_but_cannot_reconfigure_the_catalogue(): void
    {
        $captain = $this->emsCaptain();
        foreach ($this->reads() as $name => $read) {
            $this->assertSame(200, $read($captain)->status(), "read {$name}");
        }
        $this->emsPost($captain, "/ems/api/requests/{$this->uuid}/review")->assertOk();
        $this->assertSame('captain5', $this->requestModel($this->uuid)->events()->where('action', 'reviewed')->sole()->actor_context);
        $this->configure($captain)->assertForbidden();
        $this->assertSame(['view' => true, 'act' => true, 'configure' => false], $this->actingAs($captain)->getJson('/ems/api/meta')->json('data.permissions'));
    }

    public function test_captain5_administrators_and_super_administrators_can_configure(): void
    {
        $c5admin = $this->emsAdmin([Captain5Access::PERMISSION_ADMIN], 'C5 Admin');
        $this->configure($c5admin)->assertOk();
        $this->assertSame(200, $this->actingAs($c5admin)->getJson('/ems/api/requests')->status(), 'An administrator with the Captain 5 grant also reads.');

        $super = $this->emsSuperAdmin();
        foreach ($this->reads() as $name => $read) {
            $this->assertSame(200, $read($super)->status(), "read {$name}");
        }
        $this->emsPost($super, "/ems/api/requests/{$this->uuid}/review")->assertOk();
        $this->configure($super, 10)->assertOk();
    }

    public function test_an_account_that_must_change_its_password_is_not_staff(): void
    {
        $captain = $this->emsCaptain();
        $captain->forceFill(['must_change_password' => true])->save();
        $this->forgetEmsMemo();

        $this->assertFalse(app(EmsAccess::class)->canView($captain->fresh()));
        $this->assertFalse(app(EmsAccess::class)->canAct($captain->fresh()));
    }

    public function test_every_ems_route_has_authentication_and_an_access_check(): void
    {
        $uncovered = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'ems/api') || str_starts_with($route->uri(), 'employee/forms/api/ems'))
            ->reject(function ($route): bool {
                $middleware = collect($route->gatherMiddleware())->map(fn ($m): string => is_string($m) ? $m : 'closure');

                return str_starts_with($route->uri(), 'ems/api')
                    ? $middleware->contains('auth:web') && $middleware->contains(fn (string $m): bool => str_starts_with($m, 'App\\Http\\Middleware\\Ems\\EnsureEmsAccess:'))
                    : $middleware->contains('App\\Http\\Middleware\\EnsureEmployeeAuthenticated');
            })
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->values()->all();

        $this->assertSame([], $uncovered);
        $this->assertGreaterThan(30, collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'ems/api'))->count());
    }

    public function test_denied_staff_requests_change_nothing(): void
    {
        $viewer = $this->emsAdmin(['admin.ems.view']);
        $before = InventoryRequest::query()->count();
        $this->writes()['issue']($viewer)->assertForbidden();
        $this->writes()['close']($viewer)->assertForbidden();
        $this->assertSame($before, InventoryRequest::query()->count());
        $this->assertSame(1, $this->requestModel($this->uuid)->events()->count());
    }
}
