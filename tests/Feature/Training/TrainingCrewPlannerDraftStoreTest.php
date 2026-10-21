<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\User;
use App\Services\Training\CrewPlanner\TrainingCrewPlannerDraftStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class TrainingCrewPlannerDraftStoreTest extends TestCase
{
    private function actor(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    public function test_actor_isolation_and_encrypted_at_rest(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $draft = $store->create($this->actor(101));
        $key = 'training-crew-planner:'.hash('sha256', '101|'.$draft['id']);
        $encrypted = Cache::get($key);
        self::assertIsString($encrypted);
        self::assertStringNotContainsString('course_id', $encrypted);
        self::assertSame($draft, $store->get($this->actor(101), $draft['id']));
        $this->expectException(HttpException::class);
        $store->get($this->actor(102), $draft['id']);
    }

    public function test_stale_tab_cannot_overwrite_saved_selections(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor(101);
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
        $actor = $this->actor(101);
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

    public function test_only_minimal_schedule_and_proposal_data_survive_storage(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor(101);
        $draft = $store->create($actor);
        $draft = $store->update($actor, $draft['id'], 1, function (array $draft): array {
            $draft['raw_pdf'] = '%PDF-sensitive';
            $draft['training_attendances'] = [['signature' => 'forbidden']];
            $draft['metadata'] = ['schedule_date' => '2026-10-21', 'raw_text' => 'confidential'];
            $draft['staff'] = ['SYN-001' => [
                'raw_employee_id' => 'SYN-001', 'raw_name' => 'Synthetic Member',
                'raw_code' => 'PRIVATE LEAVE', 'leave_reason' => 'confidential',
                'working_intervals' => [['start_at' => '2026-10-21T08:00:00-04:00', 'end_at' => '2026-10-21T20:00:00-04:00', 'raw_code' => 'confidential']],
                'needs_review' => ['Staffing requires verification.'],
                'review_intervals' => [['start_at' => '2026-10-21T20:00:00-04:00', 'end_at' => '2026-10-22T08:00:00-04:00', 'reason' => 'Staffing requires verification.', 'raw_code' => 'confidential']],
                'global_review_reasons' => [],
                'observed_unit_context' => [['station' => 'Station Synthetic', 'apparatus' => 'Engine Synthetic', 'leave_reason' => 'confidential']],
            ]];
            $draft['selections'] = ['501' => ['slot_id' => null, 'signature' => 'forbidden']];

            return $draft;
        });
        $json = json_encode($draft, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('confidential', $json);
        self::assertStringNotContainsString('forbidden', $json);
        self::assertStringNotContainsString('PRIVATE LEAVE', $json);
        self::assertSame(['501' => ['slot_id' => null]], $draft['selections']);
        self::assertSame(['Staffing requires verification.'], $draft['staff']['SYN-001']['needs_review']);
        self::assertSame('Staffing requires verification.', $draft['staff']['SYN-001']['review_intervals'][0]['reason']);
        self::assertSame([], $draft['staff']['SYN-001']['global_review_reasons']);
    }

    public function test_expiry_is_absolute_and_not_extended_by_edits(): void
    {
        $store = app(TrainingCrewPlannerDraftStore::class);
        $actor = $this->actor(101);
        $draft = $store->create($actor);
        $this->travel(3)->hours();
        $updated = $store->update($actor, $draft['id'], 1, fn (array $draft): array => $draft);
        self::assertSame($draft['expires_at'], $updated['expires_at']);
        $this->travel(2)->hours();
        $this->expectException(HttpException::class);
        $store->get($actor, $draft['id']);
    }
}
