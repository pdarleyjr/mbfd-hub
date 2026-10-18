<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ApparatusServiceTicketSubmissionResult;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Explicit, administrator-initiated conversion of one reported equipment issue
 * into a Fleet service ticket. An inspection finding never reaches this class
 * on its own; it never resolves the issue, and it changes the unit's
 * operational status only when the administrator asks for it.
 */
final class ApparatusDefectServiceTicketService
{
    public function __construct(private readonly ApparatusServiceTicketWorkflowService $workflow) {}

    /**
     * Deterministic, shared with ApparatusInspectionExceptionService so a defect
     * can never end up with two tickets whichever path opens it first.
     */
    public static function clientSubmissionId(ApparatusDefect $defect): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'mbfd:inspection-defect:'.$defect->getKey())->toString();
    }

    /**
     * Prefill values for the creation form.
     *
     * @return array{title: string, description: string, category: string, priority: string}
     */
    public function defaults(ApparatusDefect $defect): array
    {
        $defect->loadMissing(['apparatus', 'inspection']);
        $unit = $this->unitLabel($defect->apparatus);
        $issue = str((string) $defect->issue_type)->replace('_', ' ')->lower()->toString();
        $lines = [
            sprintf('%s reported %s in %s on %s.', $defect->item, $issue !== '' ? $issue : 'as an issue', $defect->compartment, $unit),
        ];
        if ($defect->reported_date !== null) {
            $lines[] = 'Reported: '.$defect->reported_date->format('M j, Y').'.';
        }
        if (filled($defect->notes)) {
            $lines[] = 'Reporter notes: '.trim((string) $defect->notes);
        }
        $lines[] = sprintf('Source: equipment issue #%d%s.', $defect->getKey(), $this->inspectionSuffix($defect));

        return [
            'title' => Str::limit(sprintf('%s %s - %s', $defect->item, $issue, $unit), 255, ''),
            'description' => implode("\n", $lines),
            'category' => ApparatusServiceTicketCategory::RepairMechanical->value,
            'priority' => ApparatusServiceTicketPriority::Attention->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  category, priority, title, description
     *
     * @throws ValidationException when the issue is resolved or the data is invalid
     */
    public function create(
        ApparatusDefect $defect,
        User $actor,
        array $data,
        bool $alsoMarkOutOfService = false,
    ): ApparatusServiceTicketSubmissionResult {
        Gate::forUser($actor)->authorize('createFromDefect', ApparatusServiceTicket::class);

        return DB::transaction(function () use ($defect, $actor, $data, $alsoMarkOutOfService): ApparatusServiceTicketSubmissionResult {
            $locked = ApparatusDefect::query()->lockForUpdate()->findOrFail($defect->getKey());
            $apparatus = Apparatus::query()->lockForUpdate()->findOrFail($locked->apparatus_id);

            // Idempotent: a linked defect already has its ticket. Nothing else
            // (including operational status) is touched on a repeat.
            if ($locked->service_ticket_id !== null) {
                return new ApparatusServiceTicketSubmissionResult(
                    ApparatusServiceTicket::query()->findOrFail($locked->service_ticket_id),
                    false,
                );
            }

            if ($locked->resolved || $locked->status === 'resolved') {
                throw ValidationException::withMessages([
                    'defect' => 'A resolved equipment issue cannot start a service ticket.',
                ]);
            }

            $result = $this->workflow->createFleetTicket($apparatus, $actor, [
                'category' => $data['category'] ?? null,
                'priority' => $data['priority'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'internal_note' => $this->provenanceNote($locked, $actor),
                'client_submission_id' => self::clientSubmissionId($locked),
            ]);
            $ticket = $result->ticket;
            if ((int) $ticket->apparatus_id !== (int) $apparatus->id) {
                throw ValidationException::withMessages([
                    'defect' => 'The service ticket belongs to a different apparatus.',
                ]);
            }

            $locked->service_ticket_id = $ticket->id;
            if ($alsoMarkOutOfService) {
                $locked->operational_impact = 'out_of_service';
            }
            $locked->save();

            if ($alsoMarkOutOfService) {
                $this->workflow->changeOperationalStatus(
                    $apparatus,
                    $actor,
                    'Out of Service',
                    $ticket,
                    'Unit placed out of service pending repair.',
                    sprintf('Placed out of service by %s when opening this ticket from equipment issue #%d.', $actor->name, $locked->getKey()),
                );
            }

            return $result;
        }, 3);
    }

    private function provenanceNote(ApparatusDefect $defect, User $actor): string
    {
        return sprintf(
            'Opened by %s from equipment issue #%d (%s / %s)%s.',
            $actor->name,
            $defect->getKey(),
            $defect->compartment,
            $defect->item,
            $this->inspectionSuffix($defect),
        );
    }

    /** ", inspection INS-..." / ", inspection #id" / "" when there is no source inspection. */
    private function inspectionSuffix(ApparatusDefect $defect): string
    {
        if ($defect->apparatus_inspection_id === null) {
            return '';
        }
        $reference = $defect->inspection?->inspection_reference;

        return ', inspection '.($reference ?: '#'.$defect->apparatus_inspection_id);
    }

    private function unitLabel(?Apparatus $apparatus): string
    {
        if ($apparatus === null) {
            return 'the unit';
        }
        $label = trim((string) ($apparatus->designation ?: $apparatus->vehicle_number ?: $apparatus->name ?: $apparatus->getAttribute('unit_id')));

        return $label !== '' ? $label : 'the unit';
    }
}
