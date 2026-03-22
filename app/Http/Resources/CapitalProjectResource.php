<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapitalProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_number' => $this->project_number,
            'title' => $this->title,
            'description' => $this->description,
            'station_id' => $this->station_id,
            'budget' => $this->budget,
            'spent' => $this->spent,
            'status' => $this->status,
            'priority' => $this->priority,
            'start_date' => $this->start_date,
            'estimated_completion' => $this->estimated_completion,
            'actual_completion' => $this->actual_completion,
            'project_manager' => $this->project_manager,
            'vendor' => $this->vendor,
            'is_approved' => $this->is_approved,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'station' => $this->whenLoaded('station'),
        ];
    }
}