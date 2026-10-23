<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications;

use App\Filament\Admin\Pages\CommandCommunications;
use App\Models\CommandCommunicationAuditEvent;
use App\Models\CommandCommunicationPost;
use App\Services\CommandCommunications\CommandCommunicationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\CommandCommunications\Concerns\BuildsSharedCommunicationsWorld;
use Tests\TestCase;

/** The administrator page: audience on compose, audience badges, the channel filter, and controlled removal. */
final class CommunicationsPageChannelsTest extends TestCase
{
    use BuildsSharedCommunicationsWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCommunicationsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_audience_defaults_to_300_only_and_the_administrator_can_choose_captain5_or_both(): void
    {
        $onDuty = $this->officer('Captain', 'On Duty Captain 5');
        $this->seat($onDuty, 'Captain 5', 'A Shift');
        $admin = $this->superAdmin();
        $this->actingAsCanonicalUser($admin);

        $page = Livewire::test(CommandCommunications::class)->assertFormSet(['audience' => 'command_300']);
        $page->fillForm(['body' => 'Default audience'])->call('submit')->assertHasNoFormErrors();
        $page->fillForm(['body' => 'Captain 5 only', 'audience' => 'captain_5'])->call('submit')->assertHasNoFormErrors();
        $page->fillForm(['body' => 'For both', 'audience' => 'both'])->call('submit')->assertHasNoFormErrors()->assertFormSet(['audience' => 'both']);

        $this->assertSame(
            ['Default audience' => 'command_300', 'Captain 5 only' => 'captain_5', 'For both' => 'both'],
            CommandCommunicationPost::query()->orderBy('id')->pluck('audience', 'body')->all(),
        );
        $this->assertSame(['administration'], CommandCommunicationPost::query()->pluck('author_context')->unique()->values()->all());

        // Captain 5 audiences alert the on-duty Captain 5 only.
        $keys = CommandCommunicationPost::query()->whereIn('audience', ['captain_5', 'both'])->pluck('public_id')->map(fn ($id) => 'captain5:communication:'.$id)->all();
        $this->assertSame(2, DB::table('inspection_notification_deliveries')->whereIn('event_key', $keys)->where('recipient_user_id', $onDuty->id)->count());
        $this->assertSame(2, DB::table('inspection_notification_deliveries')->count());

        $page->set('data.audience', 'everyone')->set('data.body', 'bad')->call('submit')->assertHasFormErrors(['audience']);
        $this->assertSame(3, CommandCommunicationPost::query()->count());
    }

    public function test_the_filter_shows_one_channel_or_everything_and_badges_name_the_audience(): void
    {
        $admin = $this->superAdmin();
        $service = app(CommandCommunicationService::class);
        $this->adminPost($admin, 'command_300', 'Alpha for three hundred');
        $this->adminPost($admin, 'captain_5', 'Bravo for captain five');
        $this->adminPost($admin, 'both', 'Charlie for both');
        $this->actingAsCanonicalUser($admin);

        $page = Livewire::test(CommandCommunications::class)
            ->assertSet('filter', 'all')
            ->assertSee('Alpha for three hundred')->assertSee('Bravo for captain five')->assertSee('Charlie for both')
            ->assertSee('300 Command only')->assertSee('Captain 5 only')->assertSee('300 Command + Captain 5');

        $page->call('setFilter', 'command_300')->assertSee('Alpha for three hundred')->assertSee('Charlie for both')->assertDontSee('Bravo for captain five');
        $page->call('setFilter', 'captain_5')->assertSee('Bravo for captain five')->assertSee('Charlie for both')->assertDontSee('Alpha for three hundred');
        $page->call('setFilter', 'all')->assertSee('Alpha for three hundred');
        Livewire::test(CommandCommunications::class)->call('setFilter', 'nonsense')->assertStatus(422);
        $this->assertSame(3, $service->newest($admin, 10, 'all')['posts']->count());
    }

    public function test_the_admin_badge_counts_unread_across_both_channels_once(): void
    {
        $admin = $this->superAdmin();
        $this->postVia($this->command300Member(), 'command_300', 'From 300');
        $this->postVia($this->captain5Viewer(), 'captain_5', 'From Captain 5');
        $this->adminPost($this->superAdmin(), 'both', 'Admin note to both');
        $this->actingAsCanonicalUser($admin);

        $this->assertSame('3', CommandCommunications::getNavigationBadge());
        Livewire::test(CommandCommunications::class)->assertOk();
        $this->assertNull(CommandCommunications::getNavigationBadge());
    }

    public function test_removal_asks_for_a_reason_then_shows_a_tombstone_with_the_reason_for_administrators(): void
    {
        $moderator = $this->communicationsModerator();
        $author = $this->command300Member('Casey Author');
        $post = app(CommandCommunicationService::class)->post($author, 'command', null, 'Please remove this text', null, $this->submissionId());
        $reply = app(CommandCommunicationService::class)->post($author, 'command', null, 'A reply stays visible', $post->public_id, $this->submissionId());
        $this->actingAsCanonicalUser($moderator);

        Livewire::test(CommandCommunications::class)
            ->assertSee('Please remove this text')
            ->call('startDelete', $post->public_id)->assertSet('deleting', $post->public_id)->assertSee('Remove this message?')
            ->set('deleteReason', '')->call('confirmDelete')->assertHasErrors(['deleteReason'])
            ->set('deleteReason', 'x')->call('confirmDelete')->assertHasErrors(['deleteReason'])
            ->set('deleteReason', 'Contains a mistake')->call('confirmDelete')->assertHasNoErrors()
            ->assertSet('deleting', null)
            ->assertDontSee('Please remove this text')
            ->assertSee('Message removed by '.$moderator->name)->assertSee('Contains a mistake')
            ->assertSee('A reply stays visible');

        $fresh = $post->fresh();
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame($moderator->id, $fresh->deleted_by_user_id);
        $this->assertSame('Contains a mistake', $fresh->deletion_reason);
        $this->assertSame(['deleted'], CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->pluck('action')->all());
        $this->assertNull($reply->fresh()->deleted_at);

        // The removed message cannot be replied to, pinned-in or removed twice.
        Livewire::test(CommandCommunications::class)->call('startReply', $post->public_id)->assertNotFound();
        Livewire::test(CommandCommunications::class)->call('startDelete', $post->public_id)->assertNotFound();
    }

    public function test_an_administrator_without_the_manage_grant_removes_only_their_own_messages(): void
    {
        $viewer = $this->communicationsViewer('View Only Admin');
        $author = $this->command300Member('Someone Else');
        $theirs = app(CommandCommunicationService::class)->post($author, 'command', null, 'Not removable by a viewer', null, $this->submissionId());
        $mine = $this->adminPost($viewer, 'command_300', 'My own message');
        $this->actingAsCanonicalUser($viewer);

        Livewire::test(CommandCommunications::class)->call('startDelete', $theirs->public_id)->assertForbidden();
        Livewire::test(CommandCommunications::class)
            ->call('startDelete', $mine->public_id)->assertOk()->assertSet('deleting', $mine->public_id)
            ->set('deleteReason', 'Wrong wording')->call('confirmDelete')->assertHasNoErrors();

        $this->assertNull($theirs->fresh()->deleted_at);
        $this->assertNotNull($mine->fresh()->deleted_at);
        $this->assertSame(1, CommandCommunicationAuditEvent::query()->count());
    }

    public function test_pinning_a_shared_post_from_the_admin_page_is_audited_and_the_new_actions_reauthorize(): void
    {
        $admin = $this->superAdmin();
        $post = $this->adminPost($admin, 'both', 'Pin from the page');
        $this->actingAsCanonicalUser($admin);

        Livewire::test(CommandCommunications::class)->call('togglePin', $post->public_id)->assertSee('Unpin');
        $this->assertNotNull($post->fresh()->pinned_at);
        $this->assertSame(['pinned'], CommandCommunicationAuditEvent::query()->where('post_id', $post->id)->pluck('action')->all());

        foreach (['setFilter' => ['captain_5'], 'startDelete' => [$post->public_id], 'cancelDelete' => [], 'confirmDelete' => []] as $action => $arguments) {
            $component = Livewire::test(CommandCommunications::class);
            $admin->forceFill(['must_change_password' => true])->save();
            $component->call($action, ...$arguments)->assertForbidden();
            $admin->forceFill(['must_change_password' => false])->save();
        }
        $this->assertNull($post->fresh()->deleted_at);
    }
}
