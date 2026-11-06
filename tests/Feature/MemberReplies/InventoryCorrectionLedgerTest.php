<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Models\User;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/** Equipment change requests feed the shared "awaiting staff" ledger (docs/member-replies.md section 4). */
final class InventoryCorrectionLedgerTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private ApparatusInventoryCorrection $correction;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $passports = $this->item('E1', 'Passports');
        $this->correction = $this->reported('E1', [
            'reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'],
            'proposal' => ['to_compartment_id' => 'comp_a1'], 'note' => 'Now in Driver L4.',
        ]);
        $this->reviewer = $this->logistics();
    }

    public function test_a_member_reply_awaits_logistics_and_only_a_member_visible_answer_clears_it(): void
    {
        $ledger = app(MemberReplyLedger::class);
        $service = $this->service();

        $service->postMessage($this->correction->id, $this->member, 'It is the lower shelf.', ApparatusInventoryCorrectionEvent::VISIBILITY_MEMBER);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::INVENTORY_CORRECTION, $this->correction->id));

        $service->postMessage($this->correction->id, $this->reviewer, 'Check with Fleet first.', ApparatusInventoryCorrectionEvent::VISIBILITY_INTERNAL);
        $this->assertTrue($ledger->isAwaiting(ReplySubject::INVENTORY_CORRECTION, $this->correction->id));

        $service->postMessage($this->correction->id, $this->reviewer, 'Thanks, we will move it on the list.', ApparatusInventoryCorrectionEvent::VISIBILITY_MEMBER);
        $this->assertFalse($ledger->isAwaiting(ReplySubject::INVENTORY_CORRECTION, $this->correction->id));
    }

    public function test_a_member_who_is_not_on_the_request_cannot_reply(): void
    {
        $stranger = $this->actingAsCanonicalFixture('INV-404', 'Una Unrelated', 'Firefighter');

        $this->expectException(AuthorizationException::class);
        $this->service()->postMessage($this->correction->id, $stranger, 'Hi', ApparatusInventoryCorrectionEvent::VISIBILITY_MEMBER);
    }
}
