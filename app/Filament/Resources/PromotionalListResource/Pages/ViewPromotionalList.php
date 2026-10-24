<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalListResource\Pages;

use App\Filament\Resources\PromotionalListResource;
use App\Filament\Support\PromotionalListForms;
use App\Models\PromotionalList;
use App\Services\Personnel\Promotions\PromotionalListAccess;
use App\Services\Personnel\Promotions\PromotionalListExporter;
use App\Services\Personnel\Promotions\PromotionalListService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewPromotionalList extends ViewRecord
{
    protected static string $resource = PromotionalListResource::class;

    /** Entry changes made in the entries table can change list totals and ranks. */
    #[On('promotional-list-changed')]
    public function refreshList(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        $access = fn (): PromotionalListAccess => app(PromotionalListAccess::class);

        return [
            Actions\Action::make('addEntry')->label('Add entry')->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => $access()->canManage(auth()->user(), false))
                ->modalHeading('Add an entry')->modalWidth('3xl')->form(PromotionalListForms::entryFields())
                ->action(function (array $data, Actions\Action $action): void {
                    PromotionalListForms::run($action, fn () => app(PromotionalListService::class)->addEntry(auth()->user(), $this->getRecord(), $data), 'Entry added');
                    $this->afterChange();
                }),
            Actions\Action::make('changeStatus')->label('Change list status')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (): bool => $access()->canManage(auth()->user(), false))
                ->form(PromotionalListForms::listStatusFields())
                ->action(function (array $data, Actions\Action $action): void {
                    PromotionalListForms::run($action, fn () => app(PromotionalListService::class)
                        ->changeListStatus(auth()->user(), $this->getRecord(), $data['status'], $data['reason']), 'List status changed');
                    $this->afterChange();
                }),
            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')->label('Excel (.xlsx)')->icon('heroicon-o-table-cells')
                    ->action(fn (): StreamedResponse => $this->export('xlsx')),
                Actions\Action::make('exportPdf')->label('PDF')->icon('heroicon-o-document-arrow-down')
                    ->action(fn (): StreamedResponse => $this->export('pdf')),
            ])->label('Export')->icon('heroicon-o-arrow-down-tray')->button()->color('gray')
                ->visible(fn (): bool => $access()->canExport(auth()->user())),
            Actions\Action::make('history')->label('History')->icon('heroicon-o-clock')->color('gray')
                ->modalHeading('List history')->modalWidth('3xl')
                ->modalContent(fn () => view('filament.resources.promotional-list.history', [
                    'events' => $this->getRecord()->events()->with(['actor:id,name', 'entry'])->orderByDesc('id')->limit(200)->get(),
                ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Actions\EditAction::make()->label('Edit list details'),
        ];
    }

    private function export(string $format): StreamedResponse
    {
        /** @var PromotionalList $list */
        $list = $this->getRecord();

        return app(PromotionalListExporter::class)->export(auth()->user(), $list, $format);
    }

    private function afterChange(): void
    {
        $this->record->refresh();
        $this->dispatch('promotional-list-changed');
    }
}
