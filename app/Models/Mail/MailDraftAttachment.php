<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MailDraftAttachment extends Model
{
    protected $fillable = ['draft_id', 'filename', 'mime_type', 'size', 'disk', 'path', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<MailDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MailDraft::class, 'draft_id');
    }
}
