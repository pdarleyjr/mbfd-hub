<?php

declare(strict_types=1);

namespace App\Services\OperationalForms;

use App\Models\OperationalFormDocument;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OperationalFormDeletionService
{
    public function deleteRecord(OperationalFormRecord $record, User $admin): void
    {
        abort_unless($admin->can('admin.forms.manage'), 403);

        DB::transaction(function () use ($record, $admin): void {
            $locked = OperationalFormRecord::withTrashed()->lockForUpdate()->findOrFail($record->getKey());
            if ($locked->trashed()) {
                return;
            }

            OperationalFormEvent::query()->create([
                'form_record_id' => $locked->getKey(),
                'user_id' => $admin->getKey(),
                'event_type' => 'record_trashed',
                'created_at' => now(),
            ]);
            $locked->delete();
        }, 3);
    }

    public function deleteDocument(OperationalFormDocument $document, User $admin, ?string $requestIp = null): never
    {
        abort(403, 'Operational form document versions are retained as evidence.');
    }
}
