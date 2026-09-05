<?php

declare(strict_types=1);

namespace Tests\Feature\DepartmentUpdates;

use App\Enums\DepartmentUpdateStatus;
use App\Models\DepartmentUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DepartmentUpdateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-05 14:00:00');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_homepage_scope_is_due_active_bounded_and_pinned_first(): void
    {
        $author = User::factory()->create();

        $this->update($author, 'Draft', ['status' => DepartmentUpdateStatus::Draft]);
        $this->update($author, 'Future', ['publish_at' => now()->addMinute()]);
        $this->update($author, 'Expired', ['expires_at' => now()->subMinute()]);
        $this->update($author, 'Archived', [
            'status' => DepartmentUpdateStatus::Archived,
            'first_published_at' => now()->subHours(2),
        ]);
        $this->update($author, 'Older pinned', ['is_pinned' => true, 'publish_at' => now()->subHours(3)]);
        $this->update($author, 'Newest pinned', ['is_pinned' => true, 'publish_at' => now()->subHour()]);

        foreach (range(1, 6) as $index) {
            $this->update($author, "Current {$index}", ['publish_at' => now()->subMinutes($index)]);
        }

        self::assertSame([
            'Newest pinned',
            'Older pinned',
            'Current 1',
            'Current 2',
            'Current 3',
        ], DepartmentUpdate::query()->forHomepage()->pluck('title')->all());
    }

    public function test_archive_contains_expired_and_archived_publications_but_not_drafts_or_future_items(): void
    {
        $author = User::factory()->create();

        $this->update($author, 'Expired', ['expires_at' => now()->subMinute()]);
        $this->update($author, 'Archived', [
            'status' => DepartmentUpdateStatus::Archived,
            'first_published_at' => now()->subHours(2),
        ]);
        $this->update($author, 'Draft', ['status' => DepartmentUpdateStatus::Draft]);
        $this->update($author, 'Future', ['publish_at' => now()->addMinute()]);

        self::assertSame(
            ['Archived', 'Expired'],
            DepartmentUpdate::query()->publishedArchive()->orderBy('title')->pluck('title')->all(),
        );
    }

    public function test_archive_and_detail_require_authentication_and_never_expose_a_draft(): void
    {
        $author = User::factory()->create();
        $published = $this->update($author, 'Published');
        $draft = $this->update($author, 'Draft', ['status' => DepartmentUpdateStatus::Draft]);

        $this->get('/updates')->assertRedirect('/login');
        $this->get("/updates/{$published->id}")->assertRedirect('/login');

        $this->actingAsCanonicalFixture();
        $this->get('/updates')->assertOk()->assertSee('Published')->assertDontSee('Draft');
        $this->get("/updates/{$published->id}")->assertOk()->assertSee('Published');
        $this->get("/updates/{$draft->id}")->assertNotFound();
    }

    /** @param array<string, mixed> $overrides */
    private function update(User $author, string $title, array $overrides = []): DepartmentUpdate
    {
        return DepartmentUpdate::query()->create(array_merge([
            'title' => $title,
            'body' => '<p>Operational update body.</p>',
            'category' => 'general',
            'priority' => 'normal',
            'status' => DepartmentUpdateStatus::Published,
            'publish_at' => now()->subHours(2),
            'author_id' => $author->id,
        ], $overrides));
    }
}
