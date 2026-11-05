<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PersonnelRequestStatus;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Support\MemberReplies\ReplySubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PersonnelRequestUpdate extends Model
{
    protected $guarded = [];

    protected $casts = ['status' => PersonnelRequestStatus::class, 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Personnel request updates are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Personnel request updates are append-only.'));
        static::created(static fn (self $update) => $update->markReplyState());
    }

    /**
     * A member's message leaves the request waiting on Support Services; an employee-visible note written by staff
     * answers it. Internal notes and system rows change nothing (docs/member-replies.md section 4).
     */
    private function markReplyState(): void
    {
        if (blank($this->employee_visible_note)) {
            return;
        }
        $ledger = app(MemberReplyLedger::class);
        $id = (int) $this->personnel_request_id;
        if ($this->event === 'employee_responded' && $this->changed_by_employee_id !== null) {
            $member = User::query()->where('employee_profile_id', $this->changed_by_employee_id)->first();
            $ledger->memberReplied(ReplySubject::PERSONNEL_REQUEST, $id, $member, $this->created_at);

            return;
        }
        if ($this->changed_by_admin_id !== null) {
            $ledger->staffReplied(ReplySubject::PERSONNEL_REQUEST, $id, $this->created_at);
        }
    }

    /** @return BelongsTo<PersonnelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PersonnelRequest::class, 'personnel_request_id');
    }
}
