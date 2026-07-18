<?php

namespace App\Filament\Resources\OperationalFormRecordResource\Pages;

use App\Filament\Resources\OperationalFormRecordResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalFormRecord extends ViewRecord
{
    protected static string $resource = OperationalFormRecordResource::class;

    protected static string $view = 'filament.resources.operational-form-record.view';

    protected function resolveRecord($key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load(['employee', 'documents']);
    }
}
