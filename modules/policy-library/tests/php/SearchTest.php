<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['policy-library.pin_hash' => Hash::make('search-test-pin')]);
    }

    private function user(): User
    {
        $email = 'policy.search.'.bin2hex(random_bytes(8)).'@miamibeachfl.gov';
        $employee = Employee::query()->create(['employee_id' => 'SEARCH-'.bin2hex(random_bytes(4)), 'name' => 'Search Test Member', 'rank' => 'Firefighter', 'password' => 'not-used-by-tests', 'must_change_password' => false, 'city_email' => $email]);

        return User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => AccountStatus::Active, 'email' => $email, 'must_change_password' => false])->load('employeeProfile');
    }

    private function accessGrant(User $user): array
    {
        return ['user_id' => (string) $user->id, 'expires_at' => now()->addHour()->timestamp, 'pin_version' => hash('sha256', (string) config('policy-library.pin_hash'))];
    }

    private function document(string $slug, string $text, array $options = []): ManualNode
    {
        $manual = Manual::query()->firstOrCreate(['slug' => $slug], ['name' => strtoupper($slug), 'type' => $slug, 'is_active' => true]);
        $edition = $manual->activeEdition ?? $manual->editions()->create(['label' => 'Published edition', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        if ($options['old_edition'] ?? false) {
            $edition = $manual->editions()->create(['label' => 'Previous edition', 'state' => 'archived']);
        }
        $section = $edition->nodes()->create(['manual_id' => $manual->id, 'type' => 'section', 'slug' => Str::uuid(), 'title' => 'Operations', 'is_active' => ! ($options['hidden'] ?? false)]);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'parent_id' => $section->id, 'title' => 'Document title without search words', 'slug' => Str::uuid(), 'is_active' => ! ($options['inactive'] ?? false)]);
        $revision = $node->revisions()->create(['uuid' => Str::uuid(), 'source_filename' => 'test.pdf', 'storage_path' => 'test.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 2, 'state' => $options['state'] ?? 'published', 'published_at' => now()]);
        $revision->pages()->createMany([['page' => 1, 'text' => 'Unrelated page'], ['page' => 2, 'printed_label' => 'A-12', 'text' => $text]]);
        $node->update(['current_revision_id' => $revision->id]);

        return $node;
    }

    private function member(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)]);
    }

    public function test_page_text_search_returns_exact_page_path_and_excerpt_with_manual_scope(): void
    {
        $sog = $this->document('sog', 'Follow the incident command procedure at all times.');
        $this->document('medical', 'Incident command must be coordinated.');
        $this->member();
        $this->getJson('https://files.mbfdhub.com/api/search?q=INCIDENT%20command&manual=sog')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.node_id', $sog->id)
            ->assertJsonPath('results.0.page', 2)->assertJsonPath('results.0.printed_label', 'A-12')
            ->assertJsonPath('results.0.path.0', 'Operations')->assertJsonPath('results.0.manual.slug', 'sog')
            ->assertJsonPath('results.0.excerpt', 'Follow the incident command procedure at all times.');
        $this->getJson('https://files.mbfdhub.com/api/search?q=incident%20command')->assertOk()->assertJsonCount(2, 'results');
    }

    public function test_search_never_exposes_drafts_archives_inactive_nodes_hidden_ancestors_or_old_editions(): void
    {
        $visible = $this->document('sog', 'Unique searchable term');
        foreach ([['state' => 'draft'], ['state' => 'archived'], ['inactive' => true], ['hidden' => true], ['old_edition' => true]] as $options) {
            $this->document('sog', 'Unique searchable term', $options);
        }
        $old = $visible->revisions()->create(['uuid' => Str::uuid(), 'source_filename' => 'old.pdf', 'storage_path' => 'old.pdf', 'sha256' => str_repeat('b', 64), 'page_count' => 1, 'state' => 'published']);
        $old->pages()->create(['page' => 1, 'text' => 'Unique searchable term']);
        $inactiveManual = $this->document('medical', 'Unique searchable term');
        $inactiveManual->manual->update(['is_active' => false]);
        $this->member();
        $this->getJson('https://files.mbfdhub.com/api/search?q=unique%20searchable')->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.node_id', $visible->id);
    }

    public function test_search_requires_existing_identity_pin_and_bounded_query(): void
    {
        $this->getJson('https://files.mbfdhub.com/api/search?q=incident')->assertUnauthorized();
        $this->actingAs($this->user())->getJson('https://files.mbfdhub.com/api/search?q=incident')->assertForbidden();
        $this->withSession(['policy-library.access' => $this->accessGrant(auth()->user())]);
        $this->getJson('https://files.mbfdhub.com/api/search?q=a')->assertUnprocessable();
        $this->getJson('https://files.mbfdhub.com/api/search?q='.str_repeat('a', 161))->assertUnprocessable();
        $this->getJson('https://files.mbfdhub.com/api/search?q=%25%25')->assertOk()->assertJsonCount(0, 'results');
    }
}
