<?php

declare(strict_types=1);

namespace Tests\Feature\MediaControl;

use App\Enums\AccountStatus;
use App\Filament\Admin\Pages\MediaControlMedia;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MediaControlMediaPageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://media.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('services.media_control.base_url', self::BASE);
        config()->set('services.media_control.ingest_token', 'test-ingest-token');
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_migration_provisions_the_permission_without_granting_it(): void
    {
        $permission = Permission::query()->where('name', MediaControlMedia::PERMISSION)->where('guard_name', 'web')->first();

        self::assertNotNull($permission);
        self::assertSame(0, DB::table('model_has_permissions')->where('permission_id', $permission->id)->count());
        self::assertSame(0, DB::table('role_has_permissions')->where('permission_id', $permission->id)->count());
    }

    public function test_registry_exposes_the_permission_as_a_grantable_hub_capability(): void
    {
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();

        self::assertSame('Media Control — upload and manage media', $options[MediaControlMedia::PERMISSION]);
    }

    public function test_page_is_forbidden_without_the_permission(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember());

        $this->get('/admin/media-control/media')->assertForbidden();
    }

    public function test_page_renders_with_the_permission_and_allows_media_control_in_connect_src(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        $response = $this->get('/admin/media-control/media');

        $response->assertOk()->assertSee('Connected to Media Control')->assertSee('Lobby wallpaper');
        self::assertStringContainsString(self::BASE, (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_other_admin_pages_do_not_allow_media_control_in_connect_src(): void
    {
        $this->actingAs($this->adminMember(withMediaPermission: true));

        $response = $this->get('/admin/set-password');

        self::assertStringNotContainsString(self::BASE, (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_page_renders_for_super_admin(): void
    {
        $this->fakeMediaControl();
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);

        $this->get('/admin/media-control/media')->assertOk();
    }

    public function test_page_reports_missing_configuration_without_calling_media_control(): void
    {
        config()->set('services.media_control.ingest_token', null);
        Http::fake();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        Livewire::test(MediaControlMedia::class)
            ->assertSet('configured', false)
            ->assertSee('MEDIA_CONTROL_INGEST_TOKEN');
        Http::assertNothingSent();
    }

    public function test_requesting_a_grant_returns_upload_details_and_audits(): void
    {
        $this->fakeMediaControl();
        $user = $this->adminMember(withMediaPermission: true);
        $this->actingAs($user);

        $component = Livewire::test(MediaControlMedia::class)
            ->call('requestGrant', $this->file());
        $result = $this->returned($component);

        self::assertTrue($result['ok']);
        self::assertSame('grant-1', $result['grant_id']);
        self::assertSame(self::BASE.'/api/hub-ingest/v1/upload', $result['upload_url']);
        self::assertSame('one-time-upload-secret', $result['upload_token']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/hub-ingest/v1/grants')
            && $request['mime'] === 'image/png' && $request['display_span'] === 3 && $request['tags'] === ['wallpaper']
            && $request->hasHeader('X-MBFD-Hub-Actor'));
        $event = SecurityActionEvent::query()->where('action', 'media_control.media.upload_granted')->sole();
        self::assertSame('allowed', $event->result);
        self::assertSame($user->id, $event->actor_user_id);
        self::assertSame('grant-1', $event->metadata['grant_id']);
    }

    public function test_requesting_a_grant_rechecks_the_permission(): void
    {
        $this->fakeMediaControl();
        $user = $this->adminMember(withMediaPermission: true);
        $this->actingAs($user);
        $component = Livewire::test(MediaControlMedia::class);

        $user->revokePermissionTo(MediaControlMedia::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('requestGrant', $this->file())->assertForbidden();
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/grants'));
        self::assertSame(0, SecurityActionEvent::query()->count());
    }

    public function test_invalid_files_are_rejected_before_calling_media_control(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        $component = Livewire::test(MediaControlMedia::class)
            ->call('requestGrant', ['filename' => 'policy.pdf', 'size' => 10, 'mime' => 'application/pdf']);

        self::assertFalse($this->returned($component)['ok']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/grants'));
    }

    public function test_upload_url_on_another_origin_is_refused(): void
    {
        $this->fakeMediaControl(grant: ['upload_url' => 'https://evil.example.test/upload']);
        $this->actingAs($this->adminMember(withMediaPermission: true));

        $component = Livewire::test(MediaControlMedia::class)->call('requestGrant', $this->file());

        self::assertFalse($this->returned($component)['ok']);
        self::assertSame('rejected', SecurityActionEvent::query()->where('action', 'media_control.media.upload_granted')->sole()->result);
    }

    public function test_grant_status_is_only_available_for_grants_issued_in_this_session(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        $component = Livewire::test(MediaControlMedia::class)->call('grantStatus', 'someone-elses-grant');
        self::assertFalse($this->returned($component)['ok']);

        $component->call('requestGrant', $this->file())->call('grantStatus', 'grant-1');
        $status = $this->returned($component);
        self::assertTrue($status['ok']);
        self::assertSame('complete', $status['status']);
        self::assertSame('allowed', SecurityActionEvent::query()->where('action', 'media_control.media.uploaded')->sole()->result);
    }

    public function test_editing_media_patches_media_control_and_audits(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        Livewire::test(MediaControlMedia::class)
            ->callAction('edit', data: ['title' => 'Lobby wallpaper v2', 'display_span' => 2, 'category' => 'EMS', 'tags' => ['lobby']], arguments: ['id' => 'c-1'])
            ->assertHasNoActionErrors()
            ->assertSee('Lobby wallpaper v2');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/api/hub-ingest/v1/content/c-1')
            && $request['title'] === 'Lobby wallpaper v2' && $request['display_span'] === 2 && $request['category'] === 'EMS');
        $event = SecurityActionEvent::query()->where('action', 'media_control.media.updated')->sole();
        self::assertSame('allowed', $event->result);
        self::assertSame('Lobby wallpaper', $event->metadata['before']['title']);
    }

    public function test_archiving_media_removes_it_and_audits(): void
    {
        $this->fakeMediaControl();
        $this->actingAs($this->adminMember(withMediaPermission: true));

        Livewire::test(MediaControlMedia::class)
            ->callAction('archive', arguments: ['id' => 'c-1'])
            ->assertNotified('Archived in Media Control')
            ->assertDontSee('Lobby wallpaper');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/content/c-1/archive'));
        self::assertSame('allowed', SecurityActionEvent::query()->where('action', 'media_control.media.archived')->sole()->result);
    }

    public function test_archiving_media_in_use_shows_media_control_message(): void
    {
        $this->fakeMediaControl(archive: Http::response(['error' => 'Showing on Classroom display 2.', 'code' => 'content_in_use', 'usage' => []], 409));
        $this->actingAs($this->adminMember(withMediaPermission: true));

        Livewire::test(MediaControlMedia::class)
            ->callAction('archive', arguments: ['id' => 'c-1'])
            ->assertNotified('This media is in use')
            ->assertSee('Lobby wallpaper');

        $event = SecurityActionEvent::query()->where('action', 'media_control.media.archived')->sole();
        self::assertSame('rejected', $event->result);
        self::assertSame('content_in_use', $event->metadata['error_code']);
    }

    public function test_actions_recheck_the_permission(): void
    {
        $this->fakeMediaControl();
        $user = $this->adminMember(withMediaPermission: true);
        $this->actingAs($user);
        $component = Livewire::test(MediaControlMedia::class);

        $user->revokePermissionTo(MediaControlMedia::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('refreshLibrary')->assertForbidden();
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/archive'));
    }

    private function adminMember(bool $withMediaPermission = false): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Pat Example']);
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        if ($withMediaPermission) {
            $user->givePermissionTo(MediaControlMedia::PERMISSION);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function file(): array
    {
        return [
            'filename' => 'EMS_VW1.png', 'size' => 123456, 'mime' => 'image/png',
            'title' => 'EMS wallpaper', 'display_span' => 3, 'category' => 'EMS', 'tags' => ['wallpaper'],
        ];
    }

    /** @param array<string, mixed> $grant */
    private function fakeMediaControl(array $grant = [], mixed $archive = null): void
    {
        $item = [
            'id' => 'c-1', 'title' => 'Lobby wallpaper', 'kind' => 'image', 'mime' => 'image/png', 'width' => 5760, 'height' => 1080,
            'duration_sec' => null, 'file_size' => 2048, 'display_span' => 3, 'display_span_source' => 'aspect', 'category' => 'EMS',
            'tags' => [], 'processing_status' => 'ready', 'processing_error' => null, 'created_at' => '2026-10-15T10:00:00Z',
            'uploaded_via_hub' => true, 'uploaded_by' => 'Pat Example', 'thumbnail_url' => self::BASE.'/thumbs/c-1.jpg?sig=x',
        ];
        Http::fake([
            self::BASE.'/api/hub-ingest/v1/health' => Http::response(['ok' => true, 'version' => '4.2.0']),
            self::BASE.'/api/hub-ingest/v1/content?*' => Http::response(['items' => [$item], 'next_cursor' => null]),
            self::BASE.'/api/hub-ingest/v1/content/c-1/archive' => $archive ?? Http::response(['ok' => true]),
            self::BASE.'/api/hub-ingest/v1/content/c-1' => Http::response(array_merge($item, ['title' => 'Lobby wallpaper v2', 'display_span' => 2, 'display_span_source' => 'explicit'])),
            self::BASE.'/api/hub-ingest/v1/grants/*' => Http::response(['grant_id' => 'grant-1', 'status' => 'complete', 'content_id' => 'c-2', 'error' => null]),
            self::BASE.'/api/hub-ingest/v1/grants' => Http::response(array_merge([
                'grant_id' => 'grant-1', 'upload_url' => self::BASE.'/api/hub-ingest/v1/upload', 'upload_token' => 'one-time-upload-secret',
                'expires_at' => '2026-10-15T12:00:00Z', 'max_bytes' => 123456, 'chunk_size' => 52428800,
            ], $grant), 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function returned(Testable $component): array
    {
        $returns = $component->effects['returns'] ?? [];

        return (array) end($returns);
    }
}
