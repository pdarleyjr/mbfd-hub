<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use Filament\Resources\Pages\Page as ResourcePage;

/** Keep relation exports inside both the parent record and the relation's native access rules. */
trait HasRelationExportActions
{
    use HasExportActions;

    public function canExportTableData(User $actor): bool
    {
        $page = $this->getPageClass();
        if (! is_subclass_of($page, ResourcePage::class)) {
            return false;
        }
        $resource = $page::getResource();
        $owner = $this->getOwnerRecord();

        return $resource::canViewAny()
            && $resource::canView($owner)
            && static::canViewForRecord($owner, $page);
    }

    public function tableExportTitle(): string
    {
        return (string) static::getTitle($this->getOwnerRecord(), $this->getPageClass());
    }
}
