<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Resources\AiPlaybookResource\Pages\EditAiPlaybook;
use App\Filament\Resources\AiPlaybookResource\Pages\ListAiPlaybooks;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\User;
use App\Services\AiAutomation\Admin\ResponseMessageGroups;
use App\Services\AiAutomation\PlaybookRepository;
use Livewire\Livewire;

final class AdminResponseMessagesTest extends AdminScreensTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PlaybookRepository::class)->ensureSeeded();
        $this->admin = $this->actingAsSuperAdmin();
    }

    public function test_every_shipped_message_lands_in_a_named_section_and_word_lists_go_under_advanced(): void
    {
        foreach (AiAutomationPlaybook::query()->get() as $playbook) {
            $section = ResponseMessageGroups::sectionOf((string) $playbook->key, (string) $playbook->kind);
            self::assertNotSame('', ResponseMessageGroups::label($section), $playbook->key);
            if ($playbook->kind === AiAutomationPlaybook::KIND_LIST) {
                self::assertSame(ResponseMessageGroups::WORD_LISTS, $section, $playbook->key);
            }
        }

        self::assertSame('standard', ResponseMessageGroups::sectionOf('receipt'));
        self::assertSame('missing', ResponseMessageGroups::sectionOf('missing_low'));
        self::assertSame('batteries', ResponseMessageGroups::sectionOf('missing_battery'));
        self::assertSame('air_tech', ResponseMessageGroups::sectionOf('medium_air_tech'));
        self::assertSame('captain5', ResponseMessageGroups::sectionOf('captain5_low'));
        self::assertSame('weekend', ResponseMessageGroups::sectionOf('weekend_line'));
        self::assertSame('safety', ResponseMessageGroups::sectionOf('readiness'));
        self::assertSame('station2', ResponseMessageGroups::sectionOf('station2_guidance'));
        self::assertSame('other', ResponseMessageGroups::sectionOf('something_new'), 'An unknown key is never hidden.');
        self::assertSame('Other messages', ResponseMessageGroups::label('other'));
    }

    public function test_the_list_groups_messages_by_section_with_plain_labels_and_hides_word_lists_until_asked(): void
    {
        $receipt = $this->playbook('receipt');
        $list = AiAutomationPlaybook::query()->where('kind', 'list')->firstOrFail();

        $component = Livewire::test(ListAiPlaybooks::class)->loadTable()
            ->assertCanSeeTableRecords([$receipt])->assertCanNotSeeTableRecords([$list])
            ->assertSee('Standard acknowledgment')
            ->assertSee('Messages')->assertSee('Advanced: word lists');

        $component->set('activeTab', 'lists')->assertCanSeeTableRecords([$list])->assertCanNotSeeTableRecords([$receipt]);
    }

    public function test_editing_a_message_previews_saves_and_applies_to_the_next_reply(): void
    {
        $receipt = $this->playbook('receipt');

        $edit = Livewire::test(EditAiPlaybook::class, ['record' => $receipt->getKey()])
            ->assertSee('What should this message say?')
            ->assertSee('Preview')
            ->fillForm(['body' => 'Got it, thank you.'])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame('Got it, thank you.', app(PlaybookRepository::class)->template('receipt')['body']);
        self::assertSame(2, $receipt->fresh()->version);
        $edit->assertSet('data.body', 'Got it, thank you.');
    }

    public function test_a_bad_message_shows_a_plain_error_and_changes_nothing(): void
    {
        $receipt = $this->playbook('receipt');

        Livewire::test(EditAiPlaybook::class, ['record' => $receipt->getKey()])
            ->fillForm(['body' => 'Call 305-555-0123 now.'])
            ->call('save')
            ->assertHasFormErrors(['body']);

        self::assertSame(1, $receipt->fresh()->version);
    }

    public function test_restore_previous_brings_back_earlier_wording_as_a_new_version(): void
    {
        $receipt = $this->playbook('receipt');
        $original = $receipt->body;
        app(PlaybookRepository::class)->update($receipt, 'Second wording.', $this->admin);
        app(PlaybookRepository::class)->update($receipt->fresh(), 'Third wording.', $this->admin);

        $first = $receipt->revisions()->get()->firstWhere('version', 1);
        Livewire::test(EditAiPlaybook::class, ['record' => $receipt->getKey()])
            ->callAction('restorePrevious', ['revision_id' => $first->getKey()])
            ->assertHasNoActionErrors();

        $receipt->refresh();
        self::assertSame($original, $receipt->body);
        self::assertSame(4, $receipt->version, 'Restoring adds a version; the history stays complete.');
        self::assertSame($original, app(PlaybookRepository::class)->template('receipt')['body']);
    }

    public function test_restore_previous_is_hidden_until_there_is_something_to_go_back_to(): void
    {
        $receipt = $this->playbook('receipt');

        Livewire::test(EditAiPlaybook::class, ['record' => $receipt->getKey()])->assertActionHidden('restorePrevious');

        app(PlaybookRepository::class)->update($receipt, 'Second wording.', $this->admin);

        Livewire::test(EditAiPlaybook::class, ['record' => $receipt->getKey()])->assertActionVisible('restorePrevious');
    }

    public function test_the_screens_show_no_keys_and_the_list_exports(): void
    {
        $html = (string) $this->get('/admin/ai-playbooks')->assertOk()->getContent();
        $visible = (string) preg_replace('/wire:(?:snapshot|effects)="[^"]*"/', '', $html);

        self::assertStringContainsString('Response Messages', $visible);
        self::assertStringNotContainsString('Response Playbooks', $visible);
        self::assertStringNotContainsString('missing_battery_dewalt', $visible);

        Livewire::test(ListAiPlaybooks::class)->loadTable()
            ->assertTableActionVisible('export')
            ->assertTableBulkActionExists('export_selected')
            ->callTableAction('export', data: ['format' => 'pdf', 'scope' => 'all', 'include_toggled_off' => true])
            ->assertHasNoTableActionErrors();
    }

    public function test_a_word_list_is_edited_under_advanced_with_its_own_label(): void
    {
        $list = AiAutomationPlaybook::query()->where('kind', 'list')->firstOrFail();

        $html = (string) $this->get('/admin/ai-playbooks/'.$list->getKey().'/edit')->assertOk()->getContent();

        self::assertStringContainsString('Words and phrases, one per line', $html);
    }

    private function playbook(string $key): AiAutomationPlaybook
    {
        return AiAutomationPlaybook::query()->where('key', $key)->firstOrFail();
    }
}
