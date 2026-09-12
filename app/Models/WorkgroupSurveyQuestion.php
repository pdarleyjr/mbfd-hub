<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $position @property string $type @property string $prompt @property bool $is_required @property array<string, mixed> $configuration */
class WorkgroupSurveyQuestion extends Model
{
    protected $fillable = ['survey_id', 'position', 'type', 'prompt', 'help_text', 'is_required', 'configuration'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'configuration' => 'array'];
    }

    /** @return array<string, mixed> */
    public function configurationData(): array
    {
        $configuration = $this->getAttribute('configuration');

        return is_array($configuration) ? $configuration : [];
    }

    /** @return BelongsTo<WorkgroupSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(WorkgroupSurvey::class, 'survey_id');
    }
}
