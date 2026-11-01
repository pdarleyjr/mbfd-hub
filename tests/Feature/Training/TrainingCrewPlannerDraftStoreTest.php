<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Training\TrainingCrewPlanDraft;
use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class TrainingCrewPlannerDraftStoreTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create();
    }

    public function test_actor_isolation_and_encrypted_at_rest(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $owner = $this->actor();
        $draft = $store->create($owner);
        $raw = TrainingCrewPlanDraft::query()->findOrFail($draft['id'])->getRawOriginal('payload');
        self::assertStringNotContainsString('course_id', $raw);
        self::assertSame($draft, $store->get($owner, $draft['id']));
        try {
            $store->get($this->actor(), $draft['id']);
            self::fail('Another member must not be able to open this proposal.');
        } catch (HttpException $exception) {
            self::assertSame(410, $exception->getStatusCode());
        }
    }

    public function test_it_survives_a_cache_flush(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $owner = $this->actor();
        $draft = $store->create($owner);
        Cache::flush();
        self::assertSame($draft['id'], $store->get($owner, $draft['id'])['id']);
    }

    public function test_coming_back_finds_the_most_recent_plan(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $owner = $this->actor();
        self::assertNull($store->latest($owner));
        $store->create($owner);
        $this->travel(1)->minutes();
        $newest = $store->create($owner);
        self::assertSame($newest['id'], $store->latest($owner)['id']);
        self::assertNull($store->latest($this->actor()));
    }

    public function test_stale_tab_cannot_overwrite_saved_selections(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor();
        $draft = $store->create($actor);
        $current = $store->update($actor, $draft['id'], 1, function (array $draft): array {
            $draft['selections']['501'] = ['slot_id' => null];

            return $draft;
        });
        try {
            $store->update($actor, $draft['id'], 1, fn (array $draft): array => [...$draft, 'selections' => []]);
            self::fail('A stale update must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertSame($current, $store->get($actor, $draft['id']));
    }

    public function test_repeated_group_adds_merge_once_and_remove_can_be_undone(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor();
        $draft = $store->create($actor);
        foreach ([['501'], ['502', '501']] as $ids) {
            $draft = $store->update($actor, $draft['id'], $draft['version'], function (array $draft) use ($ids): array {
                foreach ($ids as $id) {
                    $draft['selections'][$id] ??= ['slot_id' => null];
                }

                return $draft;
            });
        }
        self::assertCount(2, $draft['selections']);
        $draft = $store->update($actor, $draft['id'], $draft['version'], function (array $draft): array {
            $draft['removed']['501'] = $draft['selections']['501'];
            unset($draft['selections']['501']);

            return $draft;
        });
        self::assertCount(1, $draft['selections']);
        $draft = $store->update($actor, $draft['id'], $draft['version'], function (array $draft): array {
            $draft['selections'] += $draft['removed'];
            $draft['removed'] = [];

            return $draft;
        });
        self::assertCount(2, $draft['selections']);
        $store->discard($actor, $draft['id'], $draft['version']);
        $this->expectException(HttpException::class);
        $store->get($actor, $draft['id']);
    }

    public function test_only_minimal_proposal_data_survives_storage(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor();
        $draft = $store->create($actor);
        $draft = $store->update($actor, $draft['id'], 1, function (array $draft): array {
            $draft['raw_pdf'] = '%PDF-sensitive';
            $draft['training_attendances'] = [['signature' => 'forbidden']];
            $draft['staff'] = ['SYN-001' => ['raw_code' => 'PRIVATE LEAVE', 'leave_reason' => 'confidential']];
            $draft['metadata'] = ['schedule_date' => '2026-10-21', 'raw_text' => 'confidential'];
            $draft['selections'] = ['501' => ['slot_id' => null, 'exception' => true, 'signature' => 'forbidden']];
            $draft['slots'] = [['id' => 'a', 'label' => 'One', 'start_at' => 'x', 'end_at' => 'y', 'location' => 'Tower', 'note' => 'confidential']];

            return $draft;
        });
        $json = json_encode($draft, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('confidential', $json);
        self::assertStringNotContainsString('forbidden', $json);
        self::assertStringNotContainsString('PRIVATE LEAVE', $json);
        self::assertArrayNotHasKey('staff', $draft, 'Roster data is read fresh, never kept in a proposal.');
        self::assertSame(['501' => ['slot_id' => null, 'exception' => true]], $draft['selections']);
        self::assertSame('Tower', $draft['slots'][0]['location']);
    }

    public function test_a_week_without_edits_expires_and_each_edit_extends_it(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor();
        $draft = $store->create($actor);
        $this->travel(6)->days();
        $updated = $store->update($actor, $draft['id'], 1, fn (array $draft): array => $draft);
        self::assertGreaterThan($draft['expires_at'], $updated['expires_at']);
        $this->travel(6)->days();
        self::assertSame($updated['id'], $store->get($actor, $draft['id'])['id']);
        $this->travel(2)->days();
        self::assertNull($store->latest($actor));
        try {
            $store->get($actor, $draft['id']);
            self::fail('An expired proposal must not open.');
        } catch (HttpException $exception) {
            self::assertSame(410, $exception->getStatusCode());
        }
        Artisan::call('model:prune', ['--model' => [TrainingCrewPlanDraft::class]]);
        self::assertSame(0, TrainingCrewPlanDraft::query()->count());
    }
}
