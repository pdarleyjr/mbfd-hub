<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property int $id @property int $position @property string $type @property string $prompt @property bool $is_required @property array<string, mixed> $configuration */
class WorkgroupSurveyQuestion extends Model
{
    protected $fillable = ['survey_id', 'position', 'type', 'prompt', 'help_text', 'is_required', 'configuration'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'configuration' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $question): void {
            self::ensureRevisionCanChange($question);
        });

        static::deleting(function (self $question): void {
            self::ensureRevisionCanChange($question);
        });
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

    private static function ensureRevisionCanChange(self $question): void
    {
        if ($question->survey->hasResponses()) {
            throw new LogicException('Questions with submitted responses are immutable. Duplicate the survey to create a new revision.');
        }
    }
}
