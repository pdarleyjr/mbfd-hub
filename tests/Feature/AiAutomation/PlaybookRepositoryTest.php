<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Enums\AccountStatus;
use App\Filament\Resources\AiPlaybookResource;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\AiAutomation\AiAutomationPlaybookRevision;
use App\Models\User;
use App\Services\AiAutomation\PlaybookRepository;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PlaybookRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_falls_back_to_config_defaults_before_seeding(): void
    {
        $repo = new PlaybookRepository;

        self::assertSame(['body' => 'Acknowledged.', 'version' => 0], $repo->template('receipt'));
        self::assertContains('scba', $repo->terms('safety_terms'));
    }

    public function test_unknown_keys_throw(): void
    {
        $repo = new PlaybookRepository;

        $this->expectException(InvalidArgumentException::class);
        $repo->template('nope');
    }

    public function test_unknown_list_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlaybookRepository)->terms('nope');
    }

    public function test_a_list_key_is_not_a_template(): void
    {
        (new PlaybookRepository)->ensureSeeded();

        $this->expectException(InvalidArgumentException::class);
        (new PlaybookRepository)->template('safety_terms');
    }

    public function test_seeding_inserts_everything_once_and_never_overwrites(): void
    {
        $repo = new PlaybookRepository;
        $expected = count(config('ai_automation.templates')) + count(config('ai_automation.lists'));

        self::assertSame($expected, $repo->ensureSeeded());
        self::assertSame(0, $repo->ensureSeeded());
        self::assertSame($expected, AiAutomationPlaybook::query()->count());
        self::assertSame($expected, AiAutomationPlaybookRevision::query()->count());

        $playbook = AiAutomationPlaybook::query()->where('key', 'receipt')->sole();
        $repo->update($playbook, 'Got it.', $this->editor());
        self::assertSame(0, $repo->ensureSeeded());
        self::assertSame(['body' => 'Got it.', 'version' => 2], $repo->template('receipt'));
    }

    public function test_seeded_rows_carry_kind_label_help_and_newline_lists(): void
    {
        (new PlaybookRepository)->ensureSeeded();

        $list = AiAutomationPlaybook::query()->where('key', 'safety_terms')->sole();
        self::assertSame('list', $list->kind);
        self::assertNotSame('', $list->label);
        self::assertStringContainsString("scba\nair pack", $list->body);
        self::assertSame(1, $list->version);
        self::assertSame('template', AiAutomationPlaybook::query()->where('key', 'receipt')->sole()->kind);
    }

    public function test_every_shipped_default_passes_its_own_validation(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();

        foreach (AiAutomationPlaybook::query()->get() as $playbook) {
            self::assertSame(trim($playbook->body), $repo->validate($playbook, $playbook->body), $playbook->key);
        }
    }

    public function test_terms_are_lower_cased_trimmed_unique_and_non_empty(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'urgency_terms')->sole();
        AiAutomationPlaybook::query()->whereKey($playbook->getKey())->update(['body' => "  ASAP \n\nasap\nRight Now\r\n  \nurgent"]);

        self::assertSame(['asap', 'right now', 'urgent'], $repo->terms('urgency_terms'));
    }

    public function test_update_bumps_version_and_records_a_revision(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $editor = $this->editor();
        $playbook = AiAutomationPlaybook::query()->where('key', 'missing_consumable')->sole();

        $updated = $repo->update($playbook, "Check for extra {item}.\n", $editor);

        self::assertSame(2, $updated->version);
        self::assertSame('Check for extra {item}.', $updated->body);
        self::assertSame($editor->getKey(), $updated->updated_by_user_id);
        $revisions = AiAutomationPlaybookRevision::query()->where('playbook_id', $playbook->getKey())->orderBy('version')->get();
        self::assertSame([1, 2], $revisions->pluck('version')->all());
        self::assertSame('Check for extra {item}.', $revisions->last()->body);
        self::assertSame($editor->getKey(), $revisions->last()->edited_by_user_id);
        self::assertSame('Pat Example', $revisions->last()->edited_by_name);
        self::assertSame('Check for extra {item}.', $repo->template('missing_consumable')['body']);
    }

    public function test_saving_the_live_body_again_changes_nothing(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'receipt')->sole();

        $same = $repo->update($playbook, "Acknowledged.\n", $this->editor());

        self::assertSame(1, $same->version);
        self::assertSame(1, AiAutomationPlaybookRevision::query()->where('playbook_id', $playbook->getKey())->count());
    }

    public function test_revisions_cannot_be_changed_or_deleted(): void
    {
        (new PlaybookRepository)->ensureSeeded();
        $revision = AiAutomationPlaybookRevision::query()->firstOrFail();

        try {
            $revision->update(['body' => 'x']);
            self::fail('Revision was changed.');
        } catch (LogicException) {
            self::assertNotSame('x', $revision->fresh()->body);
        }
        $this->expectException(LogicException::class);
        $revision->delete();
    }

    /** @return array<string, array{string}> */
    public static function badTemplates(): array
    {
        return [
            'empty' => ['   '],
            'too long' => [str_repeat('a', 321)],
            'unknown placeholder' => ['Check {station} please.'],
            'stray brace' => ['Check {item please.'],
            'http link' => ['See https://example.com/help for details.'],
            'www link' => ['See www.example.org for details.'],
            'bare domain' => ['Open example.com tonight.'],
            'phone dashes' => ['Call 305-555-0123 to ask.'],
            'phone plain' => ['Call 3055550123 to ask.'],
            'phone parens' => ['Call (305) 555 0123 to ask.'],
            'email' => ['Write to someone@example.org.'],
        ];
    }

    #[DataProvider('badTemplates')]
    public function test_template_validation_rejects(string $body): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'receipt')->sole();

        try {
            $repo->update($playbook, $body, $this->editor());
            self::fail('Invalid template was saved.');
        } catch (InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame(1, $playbook->fresh()->version);
        self::assertSame('Acknowledged.', $repo->template('receipt')['body']);
    }

    public function test_template_allows_item_placeholder_and_short_extension_numbers(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'weekend_spare')->sole();

        $updated = $repo->update($playbook, 'Ask 300 whether {item} is on the spare list at Station 2.', $this->editor());

        self::assertSame(2, $updated->version);
    }

    public function test_list_validation_limits_lines_and_term_length(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'urgency_terms')->sole();

        $tooMany = implode("\n", array_map(static fn (int $i): string => "term {$i}", range(1, 301)));
        $atLimit = implode("\n", array_map(static fn (int $i): string => "term {$i}", range(1, 300)));
        $tooLong = "fine\n".str_repeat('x', 61);

        foreach ([$tooMany, $tooLong] as $bad) {
            try {
                $repo->update($playbook, $bad, $this->editor());
                self::fail('Invalid list was saved.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, $playbook->fresh()->version);
            }
        }

        self::assertSame(2, $repo->update($playbook, $atLimit, $this->editor())->version);
        self::assertCount(300, $repo->terms('urgency_terms'));
    }

    public function test_the_safety_list_cannot_be_emptied(): void
    {
        $repo = new PlaybookRepository;
        $repo->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'safety_terms')->sole();

        $this->expectException(InvalidArgumentException::class);
        $repo->update($playbook, " \n \n", $this->editor());
    }

    public function test_playbook_resource_screens_require_the_capability(): void
    {
        (new PlaybookRepository)->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'receipt')->sole();

        $this->actingAs($this->user(super: true));
        $this->get('/admin/ai-playbooks')->assertOk()->assertSee('Response Playbooks');
        $this->get('/admin/ai-playbooks/'.$playbook->getKey().'/edit')->assertOk();

        $this->actingAs($this->user(permissions: ['admin.access', 'admin.ai_automation.manage']));
        $this->get('/admin/ai-playbooks')->assertOk();

        foreach ([['admin.access'], ['admin.ai_automation.manage'], []] as $permissions) {
            $this->actingAs($this->user(permissions: $permissions));
            self::assertFalse(AiPlaybookResource::canViewAny(), implode(',', $permissions));
            self::assertNotSame(200, $this->get('/admin/ai-playbooks')->getStatusCode(), implode(',', $permissions));
            self::assertNotSame(200, $this->get('/admin/ai-playbooks/'.$playbook->getKey().'/edit')->getStatusCode());
        }
    }

    public function test_editing_a_playbook_on_the_screen_saves_a_new_version_and_shows_validation_errors(): void
    {
        (new PlaybookRepository)->ensureSeeded();
        $playbook = AiAutomationPlaybook::query()->where('key', 'receipt')->sole();
        $this->actingAs($this->user(super: true));

        Livewire::test(AiPlaybookResource\Pages\EditAiPlaybook::class, ['record' => $playbook->getKey()])
            ->fillForm(['body' => 'Call 305-555-0123 now.'])
            ->call('save')
            ->assertHasFormErrors(['body']);
        self::assertSame(1, $playbook->fresh()->version);

        Livewire::test(AiPlaybookResource\Pages\EditAiPlaybook::class, ['record' => $playbook->getKey()])
            ->fillForm(['body' => 'Received, thank you.'])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame(2, $playbook->fresh()->version);
        self::assertSame('Received, thank you.', (new PlaybookRepository)->template('receipt')['body']);
    }

    private function editor(): User
    {
        return $this->user(super: true);
    }

    /** @param list<string> $permissions */
    private function user(bool $super = false, array $permissions = []): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Pat Example', 'display_name' => 'Pat Example']);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        if ($super) {
            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        }

        return $user;
    }
}
