<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\User;

/** A non-resource table must explicitly retain its own server-side access rule. */
interface ExportableTable
{
    public function canExportTableData(User $actor): bool;

    public function tableExportTitle(): string;
}
