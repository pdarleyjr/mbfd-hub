<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Explicitly adopted by pages whose table query already applies their native access scope. */
trait HasPageTableExportActions
{
    use HasExportActions;

    public function canExportTableData(User $actor): bool
    {
        if ($this instanceof ResourcePage) {
            $resource = $this->getResource();
            $record = $this->tableExportOwner();

            return $record instanceof Model && $resource::canViewAny() && Gate::forUser($actor)->allows('view', $record);
        }

        return $this->canAccess();
    }

    public function tableExportTitle(): string
    {
        return (string) $this->getTitle();
    }

    protected function tableExportOwner(): ?Model
    {
        return $this instanceof ViewRecord ? $this->getRecord() : null;
    }
}
