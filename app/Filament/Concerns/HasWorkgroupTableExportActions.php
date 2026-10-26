<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/** Keep member-facing exports in the active workgroup and the page's own access rule. */
trait HasWorkgroupTableExportActions
{
    use HasExportActions;

    public function canExportTableData(User $actor): bool
    {
        $workgroup = app(WorkgroupContext::class)->current($actor);

        return $workgroup !== null
            && app(WorkgroupAccess::class)->canViewWorkgroup($actor, $workgroup)
            && ($this instanceof Page ? $this->canAccess() : $this->canView());
    }

    public function tableExportTitle(): string
    {
        return $this instanceof Page ? (string) $this->getTitle() : Str::headline(str_replace('Widget', '', class_basename($this)));
    }
}
