<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Training\TrainingAssignmentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Read-only: explains who the classroom kiosk offers for signing and who it does not.
 *
 * It evaluates the same provider the kiosk uses, so the answer is the kiosk's answer. Nothing
 * is written. Names and full City IDs are never printed; use the profile id and last four digits
 * to look a person up in the Hub.
 */
final class TrainingRosterEligibilityReport extends Command
{
    protected $signature = 'training:roster-eligibility
                            {--as-of= : Roster date, YYYY-MM-DD in the Hub time zone (default: today)}
                            {--details : Also list each Hub-active member who cannot sign, and each derived record}
                            {--json : Machine-readable output}';

    protected $description = 'Read-only report of who the training roster kiosk offers for signing, and why anyone active is left out';

    public function handle(TrainingAssignmentProvider $provider): int
    {
        $asOf = (string) ($this->option('as-of') ?: now(\App\Support\HubTimezone::NAME)->toDateString());
        try {
            $result = $provider->evaluate($asOf);
        } catch (ValidationException $exception) {
            $this->error('The roster could not be evaluated: '.collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $candidates = collect($result['candidates']);
        $activeIds = Employee::query()->where('roster_status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $activeLeftOut = collect($result['exclusions'])->filter(fn (array $row): bool => in_array($row['employee_profile_id'], $activeIds, true));
        $report = [
            'as_of' => $result['as_of'],
            'bid_year' => $result['bid_year'],
            'hub_active_members' => count($activeIds),
            'signable' => $candidates->count(),
            'signable_by_basis' => $candidates->countBy('assignment_basis')->all(),
            'signable_without_published_bid_by_rank' => $candidates->where('assignment_basis', 'hub_active_roster')->countBy('rank')->sortKeys()->all(),
            'signable_not_listed_by_bridge' => $candidates->where('source_employment_status', 'not_listed')->count(),
            'signable_with_hub_derived_name' => $candidates->where('name_source', 'hub_profile_derived')->count(),
            'signable_with_unrecorded_rank' => $candidates->where('rank_source', 'unrecorded')->count(),
            'hub_active_who_cannot_sign' => $activeLeftOut->count(),
            'hub_active_who_cannot_sign_by_reason' => $activeLeftOut->countBy('reason')->sortKeys()->all(),
            'bridge_members_excluded_by_reason' => collect($result['exclusions'])->reject(fn (array $row): bool => in_array($row['employee_profile_id'], $activeIds, true))->countBy('reason')->sortKeys()->all(),
        ];
        if ($this->option('details')) {
            $report['hub_active_who_cannot_sign_details'] = $activeLeftOut->map(fn (array $row): array => $this->row($row['employee_profile_id'], $row['employee_id'], $row['rank'], $row['reason']))->values()->all();
            $report['derived_record_details'] = $candidates->filter(fn (array $row): bool => $row['name_source'] !== 'bridge' || $row['rank_source'] !== 'bridge' || $row['source_employment_status'] === 'not_listed')
                ->map(fn (array $row): array => $this->row($row['employee_profile_id'], $row['employee_id'], $row['rank'], 'name:'.$row['name_source'].' rank:'.$row['rank_source'].' status:'.$row['source_employment_status']))->values()->all();
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->info('Training roster eligibility as of '.$report['as_of'].' (bid year '.$report['bid_year'].'). Read-only; nothing was changed.');
        $this->table(['Measure', 'Count'], [
            ['Hub-active members', $report['hub_active_members']],
            ['Offered for signing', $report['signable']],
            ['  of those: no published bid (chiefs, staff, non-bidding positions)', $report['signable_by_basis']['hub_active_roster'] ?? 0],
            ['  of those: not listed by the Bid bridge', $report['signable_not_listed_by_bridge']],
            ['  of those: name derived from Hub profile', $report['signable_with_hub_derived_name']],
            ['  of those: rank not recorded anywhere', $report['signable_with_unrecorded_rank']],
            ['Hub-active but NOT offered', $report['hub_active_who_cannot_sign']],
        ]);
        foreach (['signable_without_published_bid_by_rank' => 'No published bid, by rank', 'hub_active_who_cannot_sign_by_reason' => 'Hub-active but not offered, by reason', 'bridge_members_excluded_by_reason' => 'Bridge members excluded (not Hub-active), by reason'] as $key => $title) {
            $this->line($title);
            $this->table(['Value', 'Count'], $this->pairs($report[$key]));
        }
        foreach (['hub_active_who_cannot_sign_details' => 'Hub-active members who cannot sign', 'derived_record_details' => 'Derived records'] as $key => $title) {
            if (isset($report[$key])) {
                $this->line($title);
                $this->table(['Profile', 'ID ends', 'Rank', 'Note'], array_map('array_values', $report[$key]));
            }
        }

        return self::SUCCESS;
    }

    /** @return array{profile: int|null, id_ends: string, rank: string|null, note: string} */
    private function row(?int $profile, string $employeeId, ?string $rank, string $note): array
    {
        return ['profile' => $profile, 'id_ends' => substr($employeeId, -4), 'rank' => $rank, 'note' => $note];
    }

    /** @param  array<string, int>  $counts
     * @return list<array{0: string, 1: int}> */
    private function pairs(array $counts): array
    {
        return Collection::make($counts)->map(fn (int $count, string $key): array => [$key, $count])->values()->all();
    }
}
