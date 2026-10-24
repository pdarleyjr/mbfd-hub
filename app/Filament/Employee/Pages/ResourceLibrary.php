<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Services\ResourceLibrary\ResourceLibrarySearch;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Employee > Resources: published manuals and guidelines with preview and original-format download. */
class ResourceLibrary extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'Resources';

    protected static ?string $slug = 'resources';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Resources';

    protected ?string $heading = '';

    protected static string $view = 'filament.employee.pages.resource-library';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $category = '';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->category = '';
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $search = app(ResourceLibrarySearch::class);
        $documents = $search->documents($this->search, $this->category);

        return [
            'groups' => $documents->groupBy('category'),
            'total' => $documents->count(),
            'categories' => $search->categories(),
            'isFiltered' => trim($this->search) !== '' || trim($this->category) !== '',
        ];
    }
}
