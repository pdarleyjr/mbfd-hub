<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Enums\HubSupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $client_submission_id
 * @property string|null $ticket_number
 * @property int $reported_by_user_id
 * @property int|null $reported_by_employee_id
 * @property string $reporter_name_snapshot
 * @property string|null $reporter_employee_identifier_snapshot
 * @property string $description
 * @property string $generated_title
 * @property HubSupportTicketCategory $category
 * @property HubSupportTicketImpact $impact
 * @property string $affected_component
 * @property HubSupportTicketStatus $status
 * @property int|null $assigned_to_user_id
 * @property string|null $page_path
 * @property string|null $route_name
 * @property string|null $referrer_path
 * @property array<string, mixed>|null $client_metadata
 * @property array<string, mixed>|null $diagnostics
 * @property int $diagnostics_schema_version
 * @property string|null $application_commit
 * @property string|null $application_release
 * @property array<string, mixed>|null $classification_metadata
 * @property string|null $issue_fingerprint
 * @property string|null $resolution_summary
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property-read User $reporter
 * @property-read Employee|null $reportedByEmployee
 * @property-read User|null $assignedTo
 * @property-read \Illuminate\Database\Eloquent\Collection<int, HubSupportTicketUpdate> $updates
 * @property-read \Illuminate\Database\Eloquent\Collection<int, HubSupportTicketAttachment> $attachments
 */
final class HubSupportTicket extends Model
{
    /** @use HasFactory<\Database\Factories\HubSupportTicketFactory> */
    use HasFactory;

    protected $fillable = [
        'client_submission_id', 'ticket_number', 'reported_by_user_id', 'reported_by_employee_id',
        'reporter_name_snapshot', 'reporter_employee_identifier_snapshot', 'description', 'generated_title',
        'category', 'impact', 'affected_component', 'status', 'assigned_to_user_id', 'page_path',
        'route_name', 'referrer_path', 'client_metadata', 'diagnostics', 'diagnostics_schema_version',
        'application_commit', 'application_release', 'classification_metadata', 'issue_fingerprint',
        'acknowledged_at', 'started_at', 'resolved_at', 'closed_at', 'resolution_summary',
    ];

    protected function casts(): array
    {
        return [
            'category' => HubSupportTicketCategory::class,
            'impact' => HubSupportTicketImpact::class,
            'status' => HubSupportTicketStatus::class,
            'client_metadata' => 'array',
            'diagnostics' => 'array',
            'classification_metadata' => 'array',
            'diagnostics_schema_version' => 'integer',
            'acknowledged_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::created(function (self $ticket): void {
            if ($ticket->ticket_number !== null) {
                return;
            }

            $ticket->timestamps = false;
            try {
                $ticket->forceFill([
                    'ticket_number' => sprintf(
                        'HUB-%s-%06d',
                        Carbon::parse($ticket->getRawOriginal('created_at') ?? now())->format('Y'),
                        $ticket->getKey(),
                    ),
                ])->saveQuietly();
            } finally {
                $ticket->timestamps = true;
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function reportedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reported_by_employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<HubSupportTicketUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(HubSupportTicketUpdate::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<HubSupportTicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(HubSupportTicketAttachment::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [HubSupportTicketStatus::Resolved->value, HubSupportTicketStatus::Closed->value]);
    }
}
