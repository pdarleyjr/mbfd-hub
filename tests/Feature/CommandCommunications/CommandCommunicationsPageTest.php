<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Enums\AccountStatus;
use App\Filament\Admin\Pages\CommandCommunications;
use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The Filament administrator surface shares one backend with the 300 page API. */
final class CommandCommunicationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('command.300.access', 'web');
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_sees_the_page_and_the_navigation_item_in_the_communications_group(): void
    {
        $this->actingAsCanonicalUser($this->admin());

        $this->assertTrue(CommandCommunications::canAccess());
        $this->assertSame('Communications', CommandCommunications::getNavigationGroup());
        $this->assertSame('300 Command Communications', CommandCommunications::getNavigationLabel());
        $this->assertSame(5, CommandCommunications::getNavigationSort());
        $this->assertSame('command-communications', CommandCommunications::getSlug());

        $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Communications');
        $this->assertNotNull($group, 'Communications group present');
        $this->assertContains('300 Command Communications', collect($group->getItems())->map(fn ($item) => $item->getLabel())->all());

        Livewire::test(CommandCommunications::class)->assertOk()->assertSee('300 Command Communications')->assertSee('No messages yet');
    }

    public function test_captain_cannot_open_the_admin_page_but_can_use_the_api(): void
    {
        $this->actingAsCanonicalFixture('E-CAP-1001', 'Page Captain', 'Captain');

        $this->assertFalse(CommandCommunications::canAccess());
        $this->assertNull(CommandCommunications::getNavigationBadge());
        Livewire::test(CommandCommunications::class)->assertForbidden();
        $this->getJson('/api/command-communications/posts')->assertOk();
        $this->postJson('/api/command-communications/posts', ['body' => 'From the 300 page', 'client_submission_id' => 'captain-0001'])->assertCreated();
    }

    public function test_admin_post_is_stored_as_administration_and_visible_to_a_captain_through_the_api(): void
    {
        $admin = $this->admin();
        $captain = $this->captainUser();
        $this->actingAsCanonicalUser($admin);

        Livewire::test(CommandCommunications::class)
            ->fillForm(['body' => 'Chief wants a status update https://example.test/status', 'subject' => 'Status'])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertSee('Chief wants a status update')
            ->assertSee('href="https://example.test/status"', false)
            ->assertSee('rel="noopener noreferrer nofollow"', false);

        $post = CommandCommunicationPost::query()->firstOrFail();
        $this->assertSame('administration', $post->author_context);
        $this->assertSame($admin->id, $post->author_user_id);
        $this->assertSame('Status', $post->subject);

        $this->actingAsCanonicalUser($captain);
        $this->getJson('/api/command-communications/posts')->assertOk()
            ->assertJsonPath('data.0.id', $post->public_id)
            ->assertJsonPath('data.0.author.side', 'administration')
            ->assertJsonPath('data.0.is_mine', false)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_composing_requires_text_or_an_attachment_and_escapes_html(): void
    {
        $this->actingAsCanonicalUser($this->admin());

        Livewire::test(CommandCommunications::class)->fillForm(['body' => '   '])->call('submit')->assertHasFormErrors(['body']);
        $this->assertSame(0, CommandCommunicationPost::query()->count());

        Livewire::test(CommandCommunications::class)
            ->fillForm(['body' => '<script>alert(1)</script> and javascript:alert(2)'])->call('submit')->assertHasNoFormErrors()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_reply_and_pin_through_livewire_share_the_same_records(): void
    {
        $admin = $this->admin();
        $captain = $this->captainUser();
        $top = app(CommandCommunicationService::class)->post($captain, 'command', 'Need help', 'Truck is down', null, 'page-seed-0001');
        $this->actingAsCanonicalUser($admin);

        Livewire::test(CommandCommunications::class)
            ->call('startReply', $top->public_id)
            ->assertSet('replyingTo', $top->public_id)
            ->set('replyBody', '')->call('sendReply')->assertHasErrors(['replyBody' => 'required'])
            ->set('replyBody', 'Acknowledged, sending relief')->call('sendReply')->assertHasNoErrors()
            ->assertSet('replyingTo', null)
            ->assertSee('Acknowledged, sending relief')
            ->call('togglePin', $top->public_id)
            ->assertSee('Unpin');

        $reply = CommandCommunicationPost::query()->whereNotNull('parent_id')->firstOrFail();
        $this->assertSame($top->id, $reply->parent_id);
        $this->assertSame('administration', $reply->author_context);
        $this->assertNotNull($top->fresh()->pinned_at);
        $this->assertSame($admin->id, $top->fresh()->pinned_by_user_id);

        Livewire::test(CommandCommunications::class)->call('togglePin', $top->public_id);
        $this->assertNull($top->fresh()->pinned_at);

        $this->actingAsCanonicalUser($captain);
        $this->getJson('/api/command-communications/posts')->assertOk()
            ->assertJsonPath('data.0.replies.0.body', 'Acknowledged, sending relief')
            ->assertJsonPath('data.0.replies.0.author.side', 'administration');
    }

    public function test_reply_to_unknown_or_nested_posts_is_refused(): void
    {
        $admin = $this->admin();
        $service = app(CommandCommunicationService::class);
        $top = $service->post($admin, 'administration', null, 'Top', null, 'nested-top-01');
        $reply = $service->post($admin, 'administration', null, 'Reply', $top->public_id, 'nested-rep-01');
        $this->actingAsCanonicalUser($admin);

        Livewire::test(CommandCommunications::class)
            ->call('startReply', $reply->public_id)->assertNotFound()
            ->call('startReply', '01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound()
            ->call('togglePin', $reply->public_id)->assertNotFound();
    }

    public function test_mount_marks_read_tags_new_posts_once_and_clears_the_badge(): void
    {
        Carbon::setTestNow('2026-10-22 09:00:00');
        $admin = $this->admin();
        $captain = $this->captainUser();
        app(CommandCommunicationService::class)->post($captain, 'command', null, 'Fresh from command', null, 'unread-seed-01');
        $this->actingAsCanonicalUser($admin);

        $this->assertSame('1', CommandCommunications::getNavigationBadge());
        $this->assertSame('info', CommandCommunications::getNavigationBadgeColor());

        Carbon::setTestNow('2026-10-22 09:05:00');
        $first = Livewire::test(CommandCommunications::class);
        $this->assertMatchesRegularExpression('/>\s*New\s*</', $first->html());
        $this->assertNull(CommandCommunications::getNavigationBadge());

        Carbon::setTestNow('2026-10-22 09:10:00');
        $second = Livewire::test(CommandCommunications::class);
        $this->assertDoesNotMatchRegularExpression('/>\s*New\s*</', $second->html());
        Carbon::setTestNow();
    }

    public function test_search_and_load_more(): void
    {
        $admin = $this->admin();
        $service = app(CommandCommunicationService::class);
        foreach (range(1, 25) as $i) {
            $service->post($admin, 'administration', $i === 3 ? 'Hydrant inspection' : null, "Entry number {$i}", null, sprintf('search-%06d', $i));
        }
        $this->actingAsCanonicalUser($admin);

        $component = Livewire::test(CommandCommunications::class)
            ->assertSee('Entry number 25')->assertDontSee('Entry number 3<', false)->assertSee('Load older messages');
        $component->call('loadMore')->assertSee('Entry number 1')->assertDontSee('Load older messages');
        $component->set('search', 'hydrant')->assertSee('1 result')->assertSee('Hydrant inspection')->assertDontSee('Entry number 25');
        $component->set('search', 'zzzz-no-match')->assertSee('No messages match');
    }

    /** @return array<string, array{0: string}> */
    public static function livewireActions(): array
    {
        return ['poll' => ['poll'], 'load more' => ['loadMore'], 'start reply' => ['startReply'], 'toggle pin' => ['togglePin'], 'search' => ['search'], 'form field update' => ['data'], 'submit' => ['submit']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('livewireActions')]
    public function test_every_livewire_action_reauthorizes(string $action): void
    {
        $admin = $this->admin();
        $top = app(CommandCommunicationService::class)->post($this->captainUser(), 'command', null, 'Hello', null, 'auth-seed-0001');
        $this->actingAsCanonicalUser($admin);
        $component = Livewire::test(CommandCommunications::class);

        // The account became unusable after the page was opened: the action must be refused server-side.
        $admin->forceFill(['must_change_password' => true])->save();

        match ($action) {
            'startReply', 'togglePin' => $component->call($action, $top->public_id),
            'search' => $component->set('search', 'hello'),
            'data' => $component->set('data.body', 'sneaky'),
            default => $component->call($action),
        };
        $component->assertForbidden();

        $this->assertSame(1, CommandCommunicationPost::query()->count());
        $this->assertNull($top->fresh()->pinned_at);
    }

    public function test_an_administrator_whose_account_is_not_usable_is_denied(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAsCanonicalUser($admin);

        $this->assertFalse(CommandCommunications::canAccess());
        Livewire::test(CommandCommunications::class)->assertForbidden();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Hub Administrator']);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }

    private function captainUser(): User
    {
        $employee = \App\Models\Employee::query()->create([
            'employee_id' => 'E-CAP-PAGE', 'name' => 'Casey Captain', 'rank' => 'Captain',
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'name' => 'Casey Captain',
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
        ]);
    }
}
