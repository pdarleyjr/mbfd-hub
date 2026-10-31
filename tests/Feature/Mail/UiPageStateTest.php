<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Filament\Admin\Pages\EmailCenter;
use App\Livewire\Mail\MailboxList;
use App\Models\Mail\MailDraft;
use App\Services\Mail\MailDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

/** What the address bar carries (folder, conversation, compose, draft, search) and how the page treats bad values. */
final class UiPageStateTest extends TestCase
{
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
    }

    public function test_a_valid_deep_link_opens_that_folder_and_conversation_and_marks_it_read_for_the_viewer_only(): void
    {
        $thread = $this->conversation('Radio inventory', 'Counts attached.');
        $user = $this->mailAdmin();
        $other = $this->mailViewer();

        $this->actingAs($user)->get('/admin/email?folder=inbox&thread='.$thread->id)->assertOk()
            ->assertSee('Radio inventory', false)->assertSee('Counts attached.', false);

        $this->signIn($user);
        self::assertSame('0', (string) (EmailCenter::getNavigationBadge() ?? '0'));
        $this->signIn($other);
        request()->attributes->remove('email_center.unread');
        self::assertSame('1', EmailCenter::getNavigationBadge(), 'another member still has it unread');
    }

    public function test_garbage_in_the_address_bar_falls_back_quietly(): void
    {
        $this->signIn($this->mailAdmin());

        Livewire::withQueryParams(['folder' => 'nonsense', 'thread' => 'abc', 'compose' => 'zzz', 'draft' => '-4', 'msg' => 'x', 'priority' => 'urgent', 'unread' => 'yes', 'q' => "bad\x00text"])
            ->test(EmailCenter::class)
            ->assertSet('folder', 'inbox')
            ->assertSet('thread', null)
            ->assertSet('compose', null)
            ->assertSet('draft', null)
            ->assertSet('message', null)
            ->assertSet('priority', '')
            ->assertSet('unread', '')
            ->assertSet('q', 'bad text')
            ->assertOk();
    }

    public function test_a_stale_conversation_link_says_so_instead_of_failing(): void
    {
        $this->signIn($this->mailAdmin());

        Livewire::withQueryParams(['thread' => '999999'])->test(EmailCenter::class)
            ->assertSee('That conversation could not be found');
    }

    public function test_a_member_who_cannot_send_never_gets_the_composer_from_the_address_bar(): void
    {
        $this->signIn($this->mailViewer());

        Livewire::withQueryParams(['compose' => 'new'])->test(EmailCenter::class)
            ->assertSet('compose', null)
            ->assertDontSeeHtml('mail-compose__header');
    }

    public function test_opening_a_conversation_and_a_folder_changes_only_what_it_should(): void
    {
        $thread = $this->conversation();
        $this->signIn($this->mailAdmin());

        Livewire::test(EmailCenter::class)
            ->dispatch('mail-open-thread', id: $thread->id)->assertSet('thread', $thread->id)
            ->call('openFolder', 'sent')->assertSet('folder', 'sent')->assertSet('thread', null)
            ->call('toggleFilter', 'unread')->assertSet('unread', '1')
            ->call('toggleFilter', 'unread')->assertSet('unread', '')
            ->call('toggleFilter', 'priority')->assertSet('priority', 'high')
            ->call('clearFilters')->assertSet('priority', '');
    }

    public function test_the_list_shows_the_threads_of_the_chosen_folder_with_search_and_cursor_paging(): void
    {
        $this->signIn($this->mailAdmin());
        foreach (range(1, 35) as $n) {
            $this->inbound(['subject' => "Cursor subject {$n}", 'received_at' => now()->subMinutes(100 - $n)]);
        }

        $list = Livewire::test(MailboxList::class, ['folder' => 'inbox']);
        $list->assertSee('Cursor subject 35')->assertDontSee('Cursor subject 1<', false)->assertSee('Show older conversations');
        $list->call('loadMore')->assertSee('Cursor subject 1')->assertDontSee('Show older conversations');

        Livewire::test(MailboxList::class, ['folder' => 'inbox', 'search' => 'Cursor subject 17'])
            ->assertSee('Cursor subject 17')->assertDontSee('Cursor subject 18');
        Livewire::test(MailboxList::class, ['folder' => 'inbox', 'search' => 'no such words'])
            ->assertSee('No conversations match');
        Livewire::test(MailboxList::class, ['folder' => 'trash'])->assertSee('Trash is empty');
    }

    public function test_an_open_draft_on_a_conversation_opens_in_the_composer_from_the_drafts_folder(): void
    {
        $thread = $this->conversation('Reply me', 'Question?');
        $user = $this->mailAdmin();
        $this->signIn($user);
        $source = (string) $thread->messages()->first()->inbound_email_id;
        $draft = app(MailDraftService::class)->open($user, MailDraft::MODE_REPLY_ALL, $thread->id, 'inbound', (int) $source);

        Livewire::test(EmailCenter::class)
            ->call('openFolder', 'drafts')
            ->dispatch('mail-open-draft', id: $draft->id)
            ->assertSet('compose', 'reply_all')
            ->assertSet('thread', $thread->id)
            ->assertSet('draft', $draft->id)
            ->assertSeeHtml('data-mail-compose');
    }
}
