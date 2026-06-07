<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of a capital / under-25k project for the unauthenticated
 * daily-checkout SPA. Allowlist only: title/status/priority/dates.
 *
 * Never exposes budget/spend financials, internal notes, AI reasoning, vendor,
 * or project-manager details.
 */
class PublicProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'title' => $this->name ?? ('Project '.$this->id),
            'description' => $this->description,
            'status' => $this->normalizeStatus(),
            'priority' => $this->normalizePriority(),
            'estimated_completion' => $this->target_completion_date,
        ];
    }

    private function normalizeStatus(): string
    {
        $status = $this->enumValue($this->status);

        return match (strtolower(str_replace([' ', '-'], '_', $status))) {
            'in_progress' => 'in_progress',
            'on_hold', 'waiting_for_parts' => 'on_hold',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'planning',
        };
    }

    private function normalizePriority(): string
    {
        $priority = strtolower(str_replace([' ', '-'], '_', $this->enumValue($this->priority)));

        return match ($priority) {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return (string) ($value ?? '');
    }
}
