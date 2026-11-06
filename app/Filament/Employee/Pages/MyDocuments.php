<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use App\Models\OperationalFormDocument;
use App\Models\PersonnelDocument;
use App\Models\ResourceDocument;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Support\HubTimezone;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * My Documents: the one list of files a member may open. It reads the records where each file already lives
 * (personnel documents shared with the member, their own completed forms, their taskbooks); nothing is copied
 * here, and nothing appears that the member is not allowed to open.
 */
final class MyDocuments extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'My Documents';

    protected static ?string $slug = 'documents';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'My Documents';

    protected ?string $heading = '';

    protected static string $view = 'filament.employee.pages.my-documents';

    public string $search = '';

    public string $type = '';

    /** Upper bound per source so the page stays fast for long careers; the newest come first. */
    private const PER_SOURCE = 200;

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $employee = app(AuthenticatedMemberContextResolver::class)->resolve(request())->actor()->requireEmployee();
        $items = $this->items($employee);
        $types = $items->pluck('type')->unique()->sort()->values()->all();
        $search = mb_strtolower(trim($this->search));
        $filtered = $items->filter(fn (array $item): bool => ($this->type === '' || $item['type'] === $this->type)
            && ($search === '' || str_contains(mb_strtolower($item['title'].' '.$item['type'].' '.($item['related'] ?? '')), $search)));

        return ['items' => $filtered->values(), 'types' => $types, 'total' => $items->count()];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function items(Employee $employee): Collection
    {
        $personnel = PersonnelDocument::query()->visibleToEmployee((int) $employee->getKey())->current()
            ->latest()->limit(self::PER_SOURCE)
            ->get(['id', 'ulid', 'title', 'category', 'description', 'original_name', 'mime_type', 'size_bytes', 'version', 'source_type', 'created_at'])
            ->map(fn (PersonnelDocument $document): array => [
                'key' => 'p-'.$document->ulid,
                'title' => $document->title,
                'type' => $document->categoryLabel(),
                'related' => $document->description,
                'date' => $document->created_at,
                'status' => $document->version > 1 ? 'Version '.$document->version : null,
                'meta' => strtoupper(pathinfo((string) $document->original_name, PATHINFO_EXTENSION) ?: 'file').' · '.$document->readableSize(),
                'pdf' => $document->isPdf(),
                'preview' => route('personnel-documents.view', $document),
                'download' => route('personnel-documents.download', $document),
            ]);

        $forms = OperationalFormDocument::query()
            ->whereHas('record', fn ($query) => $query->where('employee_id', $employee->getKey()))
            ->with('record:id,title,form_type')
            ->latest()->limit(self::PER_SOURCE)->get()
            ->map(fn (OperationalFormDocument $document): array => [
                'key' => 'f-'.$document->getKey(),
                'title' => $document->record?->title ?: 'Completed form',
                'type' => 'Completed form',
                'related' => 'Forms',
                'date' => $document->created_at,
                'status' => 'Version '.$document->version_number,
                'meta' => 'PDF',
                'pdf' => true,
                'preview' => route('employee.forms.api.documents.preview', ['document' => $document->getKey()]),
                'download' => route('employee.forms.api.documents.download', ['document' => $document->getKey()]),
            ]);

        $taskbooks = ResourceDocument::query()->taskbooksFor((int) $employee->getKey())
            ->limit(self::PER_SOURCE)->get(['id', 'uuid', 'title', 'original_size', 'published_at', 'created_at'])
            ->map(fn (ResourceDocument $document): array => [
                'key' => 't-'.$document->uuid,
                'title' => $document->title,
                'type' => 'Taskbook',
                'related' => 'Taskbooks',
                'date' => $document->published_at ?? $document->created_at,
                'status' => null,
                'meta' => 'PDF · '.$document->readableSize(),
                'pdf' => true,
                'preview' => route('employee.taskbooks.preview', ['document' => $document->uuid]),
                'download' => route('employee.taskbooks.download', ['document' => $document->uuid]),
            ]);

        return $personnel->concat($forms)->concat($taskbooks)
            ->map(fn (array $item): array => $this->withDateLabel($item))
            ->sortByDesc(fn (array $item) => $item['date']?->getTimestamp() ?? 0)->values();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withDateLabel(array $item): array
    {
        $date = $item['date'] ?? null;

        return $item + ['date_label' => $date instanceof CarbonInterface ? $date->copy()->setTimezone(HubTimezone::NAME)->format('M j, Y') : null];
    }
}
