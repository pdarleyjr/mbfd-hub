<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalFormDocument extends Model
{
    use HasUlids;

    protected $fillable = [
        'form_record_id', 'version_number', 'source_revision', 'storage_disk',
        'storage_path', 'display_name', 'mime_type', 'file_size', 'page_count',
        'pdf_sha256', 'source_snapshot', 'template_version', 'template_sha256',
        'mapping_sha256', 'generator_version', 'created_by_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'source_revision' => 'integer',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'source_snapshot' => 'array',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(OperationalFormRecord::class, 'form_record_id');
    }

    public function createdByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    public function isInlinePreviewable(): bool
    {
        return $this->mime_type === 'application/pdf'
            || $this->mime_type === 'text/plain'
            || (str_starts_with($this->mime_type, 'image/') && $this->mime_type !== 'image/svg+xml')
            || str_starts_with($this->mime_type, 'audio/')
            || str_starts_with($this->mime_type, 'video/');
    }
}
