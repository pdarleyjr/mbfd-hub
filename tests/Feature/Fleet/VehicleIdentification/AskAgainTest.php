<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\IdentificationQuestionsRelationManager;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Services\Fleet\VehicleIdentificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

final class AskAgainTest extends IdentificationTestCase
{
    private function answered(string $field, string $value): ApparatusIdentificationQuestion
    {
        return ApparatusIdentificationQuestion::query()->create([
            'apparatus_id' => $this->unit->id, 'field' => $field, 'state' => 'answered', 'answered_value' => $value,
            'resolved_at' => now(), 'resolved_by_user_id' => $this->member->id,
        ]);
    }

    private function manager(): \App\Models\User
    {
        $manager = $this->fleetOperationsManager();
        $this->actingAsCanonicalUser($manager);

        return $manager;
    }

    private function relationManager(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(IdentificationQuestionsRelationManager::class, [
            'ownerRecord' => $this->unit->fresh(),
            'pageClass' => ViewApparatus::class,
        ]);
    }

    public function test_the_section_shows_only_when_a_question_exists(): void
    {
        $manager = $this->manager();
        $this->assertFalse(IdentificationQuestionsRelationManager::canViewForRecord($this->unit->fresh(), ViewApparatus::class));

        $this->answered('plate', 'XE1234');

        $this->assertTrue(IdentificationQuestionsRelationManager::canViewForRecord($this->unit->fresh(), ViewApparatus::class));
        $this->assertNotNull($manager);
    }

    public function test_the_table_explains_each_question(): void
    {
        $this->manager();
        $question = $this->answered('plate', 'XE1234');
        ApparatusIdentificationQuestion::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'year', 'state' => 'open', 'last_reason' => 'location_unknown']);

        $this->relationManager()
            ->assertSuccessful()
            ->assertCanSeeTableRecords(ApparatusIdentificationQuestion::query()->get())
            ->assertSee('License plate')
            ->assertSee('XE1234')
            ->assertSee("I don't know where to find it on this vehicle")
            ->assertTableActionExists('askAgain', record: $question)
            ->assertTableActionExists('history', record: $question);
    }

    public function test_ask_again_reopens_the_question_and_leaves_an_audited_event(): void
    {
        $manager = $this->manager();
        $question = $this->answered('plate', 'XE1234');
        $this->unitMaster()->forceFill(['tag_number' => null])->save();
        $this->assertNotContains('plate', $this->openFields($this->unit));

        $this->relationManager()->callTableAction('askAgain', $question)->assertHasNoTableActionErrors();

        $question->refresh();
        $this->assertSame(['open', null, null], [$question->state, $question->answered_value, $question->last_reason]);
        $event = ApparatusIdentificationEvent::query()->where('event', 'reopened')->sole();
        $this->assertSame([$manager->id, 'plate', 'XE1234'], [$event->actor_user_id, $event->field, $event->value]);
        $this->assertSame('answered', $event->metadata['previous_state']);
        $this->assertContains('plate', $this->openFields($this->unit), 'the crew is asked again');
    }

    public function test_a_temporary_reason_can_be_cleared_and_a_plain_open_question_offers_no_action(): void
    {
        $this->manager();
        $temporary = ApparatusIdentificationQuestion::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'year', 'state' => 'open', 'last_reason' => 'location_unknown']);
        $plain = ApparatusIdentificationQuestion::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'make', 'state' => 'open']);

        $this->relationManager()
            ->assertTableActionVisible('askAgain', $temporary)
            ->assertTableActionHidden('askAgain', $plain);
    }

    public function test_the_history_lists_every_event_for_the_question(): void
    {
        $this->manager();
        $this->answered('plate', 'XE1234');
        ApparatusIdentificationEvent::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'plate', 'event' => 'answered', 'value' => 'XE1234', 'actor_user_id' => $this->member->id]);
        ApparatusIdentificationEvent::query()->create(['apparatus_id' => $this->unit->id, 'field' => 'year', 'event' => 'answered', 'value' => '2022']);
        $question = ApparatusIdentificationQuestion::query()->where('field', 'plate')->sole();

        $this->relationManager()->mountTableAction('history', $question)->assertSee('XE1234')->assertSee('Swap Reporter')->assertDontSee('2022');
    }

    public function test_someone_who_cannot_update_the_apparatus_cannot_ask_again(): void
    {
        $viewer = $this->fleetViewer();
        $this->actingAsCanonicalUser($viewer);
        $question = $this->answered('plate', 'XE1234');

        $this->expectException(AuthorizationException::class);
        try {
            app(VehicleIdentificationService::class)->reopen($this->unit->fresh(), 'plate', $viewer);
        } finally {
            $this->assertSame('answered', $question->fresh()->state);
            $this->assertSame(0, ApparatusIdentificationEvent::query()->where('event', 'reopened')->count());
        }
    }

    public function test_the_table_hides_ask_again_from_a_viewer(): void
    {
        $this->actingAsCanonicalUser($this->fleetViewer());
        $question = $this->answered('plate', 'XE1234');

        $this->relationManager()->assertTableActionHidden('askAgain', $question);
    }
}
