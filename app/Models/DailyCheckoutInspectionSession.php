<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class DailyCheckoutInspectionSession extends Model
{
    protected $fillable = [
        'public_id',
        'apparatus_id',
        'actor_user_id',
        'actor_session_hash',
        'issuance_key',
        'issued_at',
        'duty_date',
        'checklist_template_id',
        'checklist_template_version',
        'checklist_hash',
        'checklist_snapshot',
        'due_tasks',
        'due_tasks_hash',
        'replay_key',
        'token_hash',
        'expires_at',
        'submitted_inspection_id',
        'prior_inspection_session_id',
        'abandoned_at',
        'abandoned_by_user_id',
        'abandoned_by_session_hash',
        'abandonment_reason',
        'abandonment_transition_type',
        'abandonment_transition_key',
        'replacement_session_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'duty_date' => 'immutable_date',
            'checklist_snapshot' => 'array',
            'due_tasks' => 'array',
            'expires_at' => 'immutable_datetime',
            'abandoned_at' => 'immutable_datetime',
        ];
    }

    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function submittedInspection(): BelongsTo
    {
        return $this->belongsTo(ApparatusInspection::class, 'submitted_inspection_id');
    }

    public function priorInspectionSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_inspection_session_id');
    }

    public function replacementSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_session_id');
    }

    public function abandonedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abandoned_by_user_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (self $session): void {
            foreach ([
                'public_id',
                'apparatus_id',
                'actor_user_id',
                'actor_session_hash',
                'issuance_key',
                'issued_at',
                'duty_date',
                'checklist_template_id',
                'checklist_template_version',
                'checklist_hash',
                'checklist_snapshot',
                'due_tasks',
                'due_tasks_hash',
                'replay_key',
                'token_hash',
                'expires_at',
                'prior_inspection_session_id',
            ] as $attribute) {
                if ($session->isDirty($attribute)) {
                    throw new LogicException('Daily Checkout inspection session contracts are immutable.');
                }
            }

            $transitionAttributes = [
                'abandoned_at',
                'abandoned_by_user_id',
                'abandoned_by_session_hash',
                'abandonment_reason',
                'abandonment_transition_type',
                'abandonment_transition_key',
                'replacement_session_id',
            ];
            if ($session->getOriginal('abandoned_at') !== null
                && $session->isDirty($transitionAttributes)) {
                throw new LogicException('Daily Checkout inspection session abandonment transitions are immutable.');
            }
        });
    }
}
