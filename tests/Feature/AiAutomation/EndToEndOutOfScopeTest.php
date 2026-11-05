<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\EmployeeMessaging\EmployeeLogisticsMessage;
use App\Models\StationRequestUpdate;
use App\Services\AiAutomation\AiAutomationSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Companion to EndToEndTest: the EMS / medical inventory workflow (Captain 5 scope) lives in its own fixture world,
 * so its out-of-scope check sits in its own class. With the master switch ON, nothing in it reaches the ledger.
 */
final class EndToEndOutOfScopeTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->buildEmsWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    public function test_an_ems_inventory_request_never_touches_the_ai_ledger_even_with_the_switch_on(): void
    {
        app(AiAutomationSwitch::class)->turnOn(null, 'console');
        $member = $this->emsMember('Paramedic', 'Pat Medic');

        $submitted = $this->submitRequest($member, [
            ['form' => 'airway-bag', 'item_key' => 'airway.top.stethoscope', 'quantity' => 2],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 5],
        ]);

        $this->assertNotEmpty($submitted['uuid'], 'The EMS request itself was saved.');
        $this->assertSame(0, AiIntakeEvent::query()->count());
        $this->assertSame(0, ApparatusIssueMessage::query()->where('author_context', 'assistant')->count());
        $this->assertSame(0, StationRequestUpdate::query()->autoReceipts()->count());
        $this->assertSame(0, EmployeeLogisticsMessage::query()->where('author_side', EmployeeLogisticsMessage::SIDE_ASSISTANT)->count());
        $this->assertSame(0, DB::table('notifications')->where('type', \App\Notifications\AiResponseNotification::class)->count());
        Mail::assertNothingSent();
    }
}
