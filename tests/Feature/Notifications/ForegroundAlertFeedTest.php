<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Filament\Pages\NotificationSettings;
use App\Http\Controllers\Api\MemberAlertFeedController;
use App\Models\User;
use App\Services\Notifications\ForegroundAlertPresenter;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/**
 * The data behind the in-app pop-up for a NEW alert (resources/js/pwa/foreground-alerts.ts):
 * the snapshot on the Filament bell, the member alert feed for pages without a bell, and the
 * settings page's per-device sound choice. Nothing here may mark an alert read.
 */
final class ForegroundAlertFeedTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPermissionCache();
    }

    private function alert(User $user, string $title, ?string $url = '/employee/messages?thread=7', array $extra = [], ?string $createdAt = null): DatabaseNotification
    {
        $notification = FilamentNotification::make()
            ->title($title)
            ->body('Body for '.$title)
            ->actions($url === null ? [] : [Action::make('view')->url($url)]);
        $data = [...$notification->getDatabaseMessage(), ...$extra];

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Filament\\Notifications\\DatabaseNotification',
            'data' => $data,
        ]);
        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt])->save();
        }

        return $row->fresh();
    }

    /** The route the lead adds in routes/api.php (where the 'api' group applies), registered here so the controller can be tested. */
    private function registerFeedRoute(): void
    {
        Route::get('/api/me/alerts', MemberAlertFeedController::class)
            ->middleware(['api', 'auth:sanctum', 'canonical.api', 'throttle:120,1'])
            ->name('api.me.alerts');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_the_bell_override_is_the_vendor_view_plus_only_the_snapshot(): void
    {
        $vendor = file_get_contents(base_path('vendor/filament/notifications/resources/views/database-notifications.blade.php'));
        $override = file_get_contents(resource_path('views/vendor/filament-notifications/database-notifications.blade.php'));

        $withoutOurLines = collect(explode("\n", (string) $override))
            ->reject(fn (string $line): bool => str_contains($line, 'MBFD'))
            ->implode("\n");

        $this->assertSame(trim((string) $vendor), trim($withoutOurLines), 'Filament changed its bell view: re-copy it and re-add the data-mbfd-alert-feed line.');
    }

    public function test_the_bell_carries_a_plain_snapshot_of_its_newest_alerts_and_marks_nothing_read(): void
    {
        $member = $this->member();
        $this->alert($member, '<b>Station 2</b> request answered');
        $other = $this->member();
        $this->alert($other, 'Not yours');

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $html = Livewire::test(DatabaseNotifications::class)->html();

        $this->assertMatchesRegularExpression('/data-mbfd-alert-feed="([^"]+)"/', $html);
        preg_match('/data-mbfd-alert-feed="([^"]+)"/', $html, $match);
        $snapshot = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

        $this->assertIsInt($snapshot['now']);
        $this->assertCount(1, $snapshot['alerts']);
        $this->assertSame('Station 2 request answered', $snapshot['alerts'][0]['title'], 'markup is stripped');
        $this->assertSame('/employee/messages?thread=7', $snapshot['alerts'][0]['url']);
        $this->assertTrue($snapshot['alerts'][0]['unread']);
        $this->assertFalse($snapshot['alerts'][0]['urgent']);
        $this->assertStringNotContainsString('Not yours', $html);
        $this->assertSame(1, $member->unreadNotifications()->count(), 'rendering the bell marks nothing read');
    }

    public function test_an_older_page_of_the_bell_never_looks_like_new_alerts(): void
    {
        $presenter = app(ForegroundAlertPresenter::class);
        $member = $this->member();
        $row = $this->alert($member, 'One');

        $this->assertNull($presenter->snapshotForPage(new Paginator([$row], 50, 2)));
        $this->assertCount(1, $presenter->snapshotForPage(new Paginator([$row], 50, 1))['alerts']);
    }

    public function test_links_that_would_leave_the_hub_are_dropped(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);
        $this->assertSame('/admin/x?y=1#z', ForegroundAlertPresenter::sameOriginPath('https://www.mbfdhub.com/admin/x?y=1#z'));
        $this->assertSame('/daily/', ForegroundAlertPresenter::sameOriginPath('/daily/'));
        foreach (['https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', 'https://u:p@www.mbfdhub.com/', 'http://www.mbfdhub.com/x', '/a\\b', ''] as $bad) {
            $this->assertNull(ForegroundAlertPresenter::sameOriginPath($bad), $bad);
        }

        $member = $this->member();
        $alert = app(ForegroundAlertPresenter::class)->present($this->alert($member, 'Away', 'https://evil.example/phish'));
        $this->assertNull($alert['url']);
    }

    public function test_only_critical_categories_or_an_explicit_flag_are_urgent(): void
    {
        $presenter = app(ForegroundAlertPresenter::class);
        $member = $this->member();

        $this->assertTrue($presenter->present($this->alert($member, 'Backup failed', null, ['provenance' => ['category' => 'system_alerts']]))['urgent']);
        $this->assertTrue($presenter->present($this->alert($member, 'Flagged', null, ['urgent' => true]))['urgent']);
        $this->assertFalse($presenter->present($this->alert($member, 'Routine', null, ['provenance' => ['category' => 'department_updates']]))['urgent']);
        $this->assertFalse($presenter->present($this->alert($member, 'Unknown', null, ['provenance' => ['category' => 'nope']]))['urgent']);
    }

    public function test_the_feed_returns_only_the_members_own_alerts_newest_first_without_marking_them_read(): void
    {
        $this->registerFeedRoute();
        $member = $this->member();
        $this->alert($member, 'Older', createdAt: now()->subHour()->toDateTimeString());
        $this->alert($member, 'Newer');
        $member->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'X', 'data' => ['title' => 'Not a bell alert']]);
        $this->alert($this->member(), 'Someone else');

        $response = $this->actingAsCanonicalUser($member)->getJson('/api/me/alerts')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('alerts.0.title', 'Newer')
            ->assertJsonPath('alerts.1.title', 'Older')
            ->assertJsonCount(2, 'alerts')
            ->assertJsonStructure(['now', 'unread_count', 'alerts' => [['id', 'title', 'body', 'url', 'urgent', 'unread', 'created']]]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(3, $member->unreadNotifications()->count(), 'reading the feed marks nothing read (two bell alerts plus the other kind)');
    }

    public function test_the_feed_requires_a_signed_in_member(): void
    {
        $this->registerFeedRoute();
        $this->getJson('/api/me/alerts')->assertUnauthorized();
    }

    public function test_signed_in_pages_advertise_the_registered_feed_and_guests_never_do(): void
    {
        $member = $this->member();

        $this->actingAs($member);
        // The route is registered in routes/api.php, so signed-in pages advertise the feed.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('api.me.alerts'));
        $this->assertStringContainsString('<meta name="mbfd-alerts-feed" content="/api/me/alerts">', Blade::render('<x-hub.head />'));

        auth()->logout();
        $this->assertStringNotContainsString('mbfd-alerts-feed', Blade::render('<x-hub.head />'), 'guests never poll');
    }

    public function test_the_settings_page_offers_the_per_device_sound_choice_and_the_lock_screen_note(): void
    {
        $member = $this->rosterMember();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->actingAs($member);

        Livewire::test(NotificationSettings::class)
            ->assertSuccessful()
            ->assertSeeHtml('data-alert-sound-settings')
            ->assertSee('Play a sound for new alerts while the Hub is open')
            ->assertSee('Play test sound')
            ->assertSee("Alerts on your lock screen use your phone's own notification sound and Focus settings; the Hub can't change those.", false);
    }
}
