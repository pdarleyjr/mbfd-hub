<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Field names match the under_25k_projects table and the public station API
 * shape (StationController); the previous version read columns that do not exist.
 *
 * @mixin \App\Models\Under25kProject
 */
class Under25kProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'title' => $this->name,
            'description' => $this->description,
            'station_id' => $this->station_id,
            'budget' => $this->budget_amount,
            'spent' => $this->spend_amount,
            'status' => $this->status,
            'priority' => $this->priority,
            'start_date' => $this->start_date,
            'estimated_completion' => $this->target_completion_date,
            'actual_completion' => $this->actual_completion_date,
            'project_lead' => $this->project_manager,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'station' => $this->whenLoaded('station'),
        ];
    }
}
