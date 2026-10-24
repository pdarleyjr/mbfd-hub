<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\StorageLocation;
use App\Services\Ems\InventoryCountService;
use App\Services\Ems\SnapshotPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * The "Counts" tab of the Medical Supply Room: start a count for chosen locations, type cases,
 * boxes and each, save, resume later, finalize. The service converts only with explicit package
 * sizes and refuses anything else; this trait just gathers what was typed. Needs RunsEmsActions.
 */
trait SupplyRoomCounts
{
    private const ALL_LOCATIONS = '__all';

    private const SAVE_CHUNK = 400;

    /** The draft count being worked on (internal id; never taken from the browser). */
    #[Locked]
    public ?int $countId = null;

    /** @var array<int|string, array{cases: string, boxes: string, each: string}> typed entries by supply item id */
    public array $entries = [];

    public function openCount(int $id): void
    {
        $this->emsActor('view');
        $draft = InventorySnapshot::query()->where('kind', InventorySnapshot::KIND_COUNT)->where('status', InventorySnapshot::DRAFT)->find($id);
        abort_if($draft === null, 404);
        $this->countId = (int) $draft->getKey();
        $this->loadEntries();
    }

    public function closeCount(): void
    {
        $this->emsActor('view');
        $this->countId = null;
        $this->entries = [];
    }

    /** @return array<string, mixed>|null the open draft with its items, or null */
    protected function openCountDetail(): ?array
    {
        if ($this->countId === null) {
            return null;
        }
        $draft = InventorySnapshot::query()->where('status', InventorySnapshot::DRAFT)->find($this->countId);
        if ($draft === null) {
            $this->countId = null;
            $this->entries = [];

            return null;
        }

        return app(SnapshotPresenter::class)->detail($draft, withItems: true) + ['internal_id' => (int) $draft->getKey()];
    }

    private function loadEntries(): void
    {
        $detail = $this->openCountDetail();
        $this->entries = [];
        foreach ((array) ($detail['items'] ?? []) as $item) {
            $entry = (array) ($item['entry'] ?? []);
            $this->entries[$item['id']] = [
                'cases' => isset($entry['cases']) ? (string) $entry['cases'] : '',
                'boxes' => isset($entry['boxes']) ? (string) $entry['boxes'] : '',
                'each' => isset($entry['each']) ? (string) $entry['each'] : '',
            ];
        }
    }

    /** @return list<array{supply_item_id: int, cases: ?string, boxes: ?string, each: ?string}> */
    private function typedLines(array $detail): array
    {
        $lines = [];
        foreach ((array) $detail['items'] as $item) {
            $typed = (array) ($this->entries[$item['id']] ?? []);
            $row = ['supply_item_id' => (int) $item['id']];
            $any = false;
            foreach (['cases', 'boxes', 'each'] as $field) {
                $value = trim((string) ($typed[$field] ?? ''));
                $row[$field] = $value === '' ? null : $value;
                $any = $any || $value !== '';
            }
            // A line typed before and now blank is sent as all blank, which clears it.
            if ($any || $item['entry'] !== null) {
                $lines[] = $row;
            }
        }

        return $lines;
    }

    /** Save what is typed. Returns false (after telling the person why) when nothing was saved. */
    private function saveTyped(Action $action, array $detail): bool
    {
        $lines = $this->typedLines($detail);
        if ($lines === []) {
            return true;
        }
        $saved = true;
        foreach (array_chunk($lines, self::SAVE_CHUNK) as $chunk) {
            $this->emsRun($action, 'act', fn ($user) => app(InventoryCountService::class)->saveLines((int) $detail['internal_id'], $user, (string) Str::uuid(), ['lines' => $chunk]), 'Counts saved');
        }

        return $saved;
    }

    public function startCountAction(): Action
    {
        return Action::make('startCount')->label('Start a count')->icon('heroicon-o-plus')->color('primary')
            ->visible(fn (): bool => $this->emsCan('act'))
            ->modalHeading('Start a count')
            ->modalDescription('Pick the locations you will count. Each location gets its own count, and you can leave and come back to it. If a count for a location is already started, you will pick up where it left off.')
            ->modalSubmitActionLabel('Start')
            ->fillForm(fn (): array => ['idempotency_key' => (string) Str::uuid()])
            ->form([
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                CheckboxList::make('locations')->label('Locations')->required()->columns(2)->bulkToggleable()
                    ->options(fn (): array => [self::ALL_LOCATIONS => 'Everything in one count'] + $this->countableLocations()),
            ])
            ->action(function (array $data, Action $action): void {
                $chosen = array_values((array) ($data['locations'] ?? []));
                $codes = in_array(self::ALL_LOCATIONS, $chosen, true) ? [null] : array_values(array_diff($chosen, [self::ALL_LOCATIONS]));
                $base = $this->emsKey($data);
                $first = null;
                foreach ($codes as $code) {
                    $result = $this->emsRun($action, 'act', fn ($user) => app(InventoryCountService::class)->start($user, substr($base, 0, 90).'-'.($code ?? 'all'), $code, null), 'Count started');
                    $first ??= is_array($result) ? ($result['result']['id'] ?? null) : null;
                }
                if ($first !== null) {
                    $this->countId = (int) InventorySnapshot::query()->where('uuid', $first)->value('id');
                    $this->loadEntries();
                }
            });
    }

    /** @return array<string, string> */
    private function countableLocations(): array
    {
        return StorageLocation::query()->where('active', true)
            ->withCount(['supplyItems as tracked_count' => fn ($q) => $q->where('active', true)->where('controlled_substance', false)])
            ->orderBy('sort')->orderBy('code')->get()->filter(fn (StorageLocation $l): bool => $l->tracked_count > 0)
            ->mapWithKeys(fn (StorageLocation $l): array => [$l->code => $l->code.(filled($l->label) ? ' - '.$l->label : '').' ('.$l->tracked_count.' items)'])->all();
    }

    public function saveCountAction(): Action
    {
        return Action::make('saveCount')->label('Save counts')->icon('heroicon-o-check')->color('gray')
            ->visible(fn (): bool => $this->emsCan('act') && $this->countId !== null)
            ->action(function (Action $action): void {
                $detail = $this->openCountDetail();
                abort_if($detail === null, 404);
                if ($this->typedLines($detail) === []) {
                    Notification::make()->warning()->title('Nothing typed yet')->body('Enter at least one count, then save.')->send();

                    return;
                }
                $this->saveTyped($action, $detail);
                $this->loadEntries();
            });
    }

    public function finalizeCountAction(): Action
    {
        return Action::make('finalizeCount')->label('Finalize count')->icon('heroicon-o-lock-closed')->color('success')
            ->visible(fn (): bool => $this->emsCan('act') && $this->countId !== null)
            ->requiresConfirmation()
            ->modalHeading('Finalize this count?')
            ->modalDescription('This becomes the official count for these items, with your name and the time on it. After that it cannot be changed. What you typed is saved first. Items you did not count keep their earlier number.')
            ->modalSubmitActionLabel('Finalize count')
            ->action(function (Action $action): void {
                $detail = $this->openCountDetail();
                abort_if($detail === null, 404);
                $this->saveTyped($action, $detail);
                $id = (int) $detail['internal_id'];
                $this->emsRun($action, 'act', fn ($user) => app(InventoryCountService::class)->finalize($id, $user, 'finalize:'.$id.':'.$user->getKey()), 'Count finalized', 'It is now the latest count for the items in it.');
                $this->countId = null;
                $this->entries = [];
                $this->forgetCurrentStock();
            });
    }

    public function discardCountAction(): Action
    {
        return Action::make('discardCount')->label('Throw away draft')->icon('heroicon-o-trash')->color('danger')->outlined()
            ->visible(fn (): bool => $this->emsCan('act') && $this->countId !== null)
            ->requiresConfirmation()
            ->modalHeading('Throw away this draft count?')
            ->modalDescription('Everything typed in this draft is deleted. A finalized count can never be deleted.')
            ->modalSubmitActionLabel('Throw away')
            ->action(function (Action $action): void {
                $id = (int) $this->countId;
                $this->emsRun($action, 'act', fn ($user) => app(InventoryCountService::class)->discard($id, $user, 'discard:'.$id.':'.$user->getKey()), 'Draft count thrown away');
                $this->countId = null;
                $this->entries = [];
            });
    }
}
