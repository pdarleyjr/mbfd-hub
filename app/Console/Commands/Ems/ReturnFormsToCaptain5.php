<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Models\Ems\InventoryRequest;
use App\Services\Ems\EmsException;
use App\Services\Ems\RequestWorkflowService;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Put one day's forms in front of Captain 5 on Submitted Inventory Forms, so each is deducted once by reconciling it.
 * A dry run until --apply. Forms never worked are already selectable; forms issued under the older workflow have
 * their issuances reversed with history kept. Run it before the workbook baseline: a count that covers an older
 * issuance refuses, because that count already reflects the shelf. See docs/ems/captain5-medical-inventory-workflow.md section 10.
 */
final class ReturnFormsToCaptain5 extends Command
{
    protected $signature = 'ems:return-forms-to-captain5 {--date= : The Eastern-time day the forms were submitted, YYYY-MM-DD} {--apply : Write the changes}';

    protected $description = "Return one day's medical inventory forms to Captain 5 for reconciliation (dry run unless --apply)";

    public function handle(RequestWorkflowService $workflow): int
    {
        $date = (string) $this->option('date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->error('Give the day with --date=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $day = CarbonImmutable::parse($date, HubTimezone::NAME);
        $apply = (bool) $this->option('apply');
        $reason = "Returned to Submitted Inventory Forms so Captain 5 can reconcile the forms submitted {$date}.";

        $forms = InventoryRequest::query()
            ->whereBetween('submitted_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
            ->orderBy('submitted_at')->orderBy('id')->get();

        $failed = false;
        foreach ($forms as $form) {
            $label = "{$form->number} {$form->target_label} ({$form->submitter_name}), {$form->status}";
            if (in_array($form->status, InventoryRequest::WORKABLE_STATUSES, true) || $form->status === InventoryRequest::RETURNED) {
                $this->line("{$label}: already on Submitted Inventory Forms.");

                continue;
            }
            if (! in_array($form->status, [InventoryRequest::ISSUED, InventoryRequest::PARTIALLY_ISSUED], true)) {
                $this->line("{$label}: left as it is.");

                continue;
            }
            if (! $apply) {
                $this->line("{$label}: would reverse its older issuance and return it.");

                continue;
            }
            try {
                $workflow->returnToForms((int) $form->getKey(), $reason);
                $this->info("{$label}: returned, now ".$form->fresh()?->status.'.');
            } catch (EmsException $exception) {
                $failed = true;
                $this->error("{$label}: {$exception->getMessage()}");
            }
        }

        $this->line($apply ? 'Done.' : 'Dry run. Nothing was written. Add --apply to write.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
