<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Filament\Admin\Pages\EmailCenter;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\InboundEmailResource;
use App\Filament\Resources\InboundEmailResource\Pages\ListInboundEmails;
use App\Filament\Resources\InboundEmailResource\Pages\ViewInboundEmail;
use App\Filament\Resources\OutboundEmailResource;
use App\Filament\Resources\OutboundEmailResource\Pages\ListOutboundEmails;
use App\Filament\Resources\OutboundEmailResource\Pages\ViewOutboundEmail;
use App\Models\Mail\MailThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Mail\Concerns\UsesEmailCenterUi;
use Tests\TestCase;

/** The old Inbound, Outbound and Compose addresses keep working and now lead into the Email Center. */
final class UiLegacyAdaptersTest extends TestCase
{
    use RefreshDatabase;
    use UsesEmailCenterUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootEmailCenterUi();
    }

    public function test_the_old_lists_and_a_bare_compose_address_redirect_to_the_email_center(): void
    {
        $user = $this->mailAdmin();

        $this->actingAs($user)->get('/admin/inbound-emails')->assertRedirect(EmailCenter::getUrl());
        $this->actingAs($user)->get('/admin/outbound-emails')->assertRedirect(EmailCenter::getUrl(['folder' => 'sent']));
        $this->actingAs($user)->get('/admin/compose-email')->assertRedirect(EmailCenter::getUrl());
    }

    public function test_readers_with_either_permission_family_open_the_old_lists(): void
    {
        foreach ([$this->mailViewer(), $this->mailUser(['admin.communications.view'])] as $user) {
            $this->actingAs($user)->get('/admin/inbound-emails')->assertRedirect(EmailCenter::getUrl());
            $this->actingAs($user)->get('/admin/outbound-emails')->assertRedirect(EmailCenter::getUrl(['folder' => 'sent']));
        }
    }

    public function test_writers_with_either_permission_family_open_the_old_compose_address(): void
    {
        foreach ([$this->mailUser(['admin.email.view', 'admin.email.send']), $this->legacyMailAdmin()] as $user) {
            $this->actingAs($user)->get('/admin/compose-email')->assertRedirect(EmailCenter::getUrl());
        }
    }

    // A refused Livewire mount leaves Livewire's redirector bound for the rest of a test's app instance, so the
    // refusals are checked last in their own test and nothing here redirects afterwards.
    public function test_reading_is_not_writing_and_strangers_are_refused_at_every_old_address(): void
    {
        $this->actingAs($this->mailViewer())->get('/admin/compose-email')->assertForbidden();
        $this->actingAs($this->mailUser(['admin.communications.view']))->get('/admin/compose-email')->assertForbidden();
        foreach (['/admin/inbound-emails', '/admin/outbound-emails', '/admin/compose-email'] as $path) {
            $this->actingAs($this->mailUser([]))->get($path)->assertForbidden();
        }
    }

    public function test_a_message_that_is_indexed_opens_in_its_conversation(): void
    {
        $user = $this->mailAdmin();
        $inbound = $this->inbound(['subject' => 'Old link']);
        $outbound = $this->outbound(['source_type' => 'admin_compose', 'subject' => 'Old sent link']);
        $inboundThread = $this->threadOf($inbound);
        $outboundThread = $this->threadOf($outbound);

        $this->actingAs($user)->get("/admin/inbound-emails/{$inbound->id}")
            ->assertRedirect(EmailCenter::getUrl(['folder' => 'inbox', 'thread' => $inboundThread->id]));
        $this->actingAs($user)->get("/admin/outbound-emails/{$outbound->id}")
            ->assertRedirect(EmailCenter::getUrl(['folder' => 'sent', 'thread' => $outboundThread->id]));
    }

    public function test_automated_mail_opens_in_the_automated_folder(): void
    {
        $automated = $this->outbound(['source_type' => 'password_reset', 'subject' => 'Reset']);
        $thread = $this->threadOf($automated);

        $this->actingAs($this->mailViewer())->get("/admin/outbound-emails/{$automated->id}")
            ->assertRedirect(EmailCenter::getUrl(['folder' => 'automated', 'thread' => $thread->id]));
    }

    public function test_a_message_the_index_has_not_seen_keeps_rendering_the_old_page(): void
    {
        $inbound = $this->inbound(['subject' => 'Not indexed', 'text_body' => 'Old page body.']);
        MailThreadMessage::query()->where('inbound_email_id', $inbound->id)->delete();

        $this->actingAs($this->mailAdmin())->get("/admin/inbound-emails/{$inbound->id}")->assertOk()->assertSee('Old page body.');
    }

    public function test_the_old_view_pages_refuse_people_who_cannot_read_mail(): void
    {
        $inbound = $this->inbound();

        $this->actingAs($this->mailUser([]))->get("/admin/inbound-emails/{$inbound->id}")->assertForbidden();
    }

    public function test_an_old_reply_or_forward_link_opens_the_equivalent_message_in_the_center(): void
    {
        $user = $this->mailAdmin();
        $inbound = $this->inbound(['subject' => 'Please reply']);
        $thread = $this->threadOf($inbound);

        foreach (['reply', 'reply_all', 'forward'] as $mode) {
            $this->actingAs($user)->get(ComposeEmail::getUrl(['source' => 'inbound', 'message' => $inbound->id, 'mode' => $mode]))
                ->assertRedirect(EmailCenter::getUrl(['thread' => $thread->id, 'compose' => $mode, 'msg' => "inbound:{$inbound->id}"]));
        }
    }

    public function test_an_old_reply_link_indexes_a_message_the_indexer_had_not_reached(): void
    {
        $outbound = $this->outbound(['source_type' => 'admin_compose']);
        MailThreadMessage::query()->where('outbound_email_id', $outbound->id)->delete();

        $response = $this->actingAs($this->mailAdmin())->get(ComposeEmail::getUrl(['source' => 'outbound', 'message' => $outbound->id, 'mode' => 'reply']));

        $thread = $this->threadOf($outbound);
        $this->assertNotNull($thread);
        $response->assertRedirect(EmailCenter::getUrl(['thread' => $thread->id, 'compose' => 'reply', 'msg' => "outbound:{$outbound->id}"]));
    }

    public function test_bad_compose_parameters_fall_back_to_the_plain_center(): void
    {
        $user = $this->mailAdmin();

        foreach ([
            ['source' => 'inbound', 'message' => '999999', 'mode' => 'reply'],
            ['source' => 'draft', 'message' => '1', 'mode' => 'reply'],
            ['source' => 'inbound', 'message' => 'abc', 'mode' => 'reply'],
            ['source' => 'inbound', 'message' => (string) $this->inbound()->id, 'mode' => 'delete'],
        ] as $query) {
            $this->actingAs($user)->get(ComposeEmail::getUrl($query))->assertRedirect(EmailCenter::getUrl());
        }
    }

    public function test_the_resources_stay_out_of_the_sidebar_and_cannot_delete_inbound_mail(): void
    {
        $this->signIn($this->mailAdmin());

        $this->assertFalse(InboundEmailResource::shouldRegisterNavigation());
        $this->assertFalse(OutboundEmailResource::shouldRegisterNavigation());
        $this->assertFalse(ComposeEmail::shouldRegisterNavigation());
        // TeleStaff cleanup owns deletion of received mail: no person-facing path may ever offer it.
        $this->assertFalse(InboundEmailResource::canDelete(new \App\Models\InboundEmail));
        $this->assertFalse(InboundEmailResource::canDeleteAny());
        $this->assertFalse(InboundEmailResource::canCreate());
    }

    public function test_the_records_view_keeps_the_filterable_logs_and_their_exports(): void
    {
        $user = $this->mailAdmin();
        $this->inbound(['subject' => 'Logged inbound']);

        $this->actingAs($user)->get('/admin/inbound-emails?records=1')->assertOk()->assertSee('Logged inbound');
        $this->actingAs($user)->get('/admin/outbound-emails?records=1')->assertOk();
        $this->actingAs($this->mailUser([]))->get('/admin/inbound-emails?records=1')->assertForbidden();

        $this->signIn($user);
        Livewire::test(ListInboundEmails::class)->call('loadTable')->assertTableActionVisible('export');
        Livewire::test(ListOutboundEmails::class)->call('loadTable')->assertTableActionVisible('export');
    }

    public function test_the_view_pages_redirect_to_the_thread_when_mounted_directly(): void
    {
        $this->signIn($this->mailAdmin());
        $inbound = $this->inbound();
        $outbound = $this->outbound(['source_type' => 'admin_compose']);

        Livewire::test(ViewInboundEmail::class, ['record' => $inbound->id])->assertRedirect(EmailCenter::getUrl(['folder' => 'inbox', 'thread' => $this->threadOf($inbound)->id]));
        Livewire::test(ViewOutboundEmail::class, ['record' => $outbound->id])->assertRedirect(EmailCenter::getUrl(['folder' => 'sent', 'thread' => $this->threadOf($outbound)->id]));
    }
}
