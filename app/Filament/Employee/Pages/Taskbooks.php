<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\ResourceDocument;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Filament\Pages\Page;

final class Taskbooks extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'Taskbooks';

    protected static ?string $slug = 'taskbooks';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Taskbooks';

    protected ?string $heading = '';

    protected static string $view = 'filament.employee.pages.taskbooks';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $employee = app(AuthenticatedMemberContextResolver::class)->resolve(request())->actor()->requireEmployee();

        return ['documents' => ResourceDocument::query()
            ->select(['id', 'uuid', 'title', 'description', 'original_filename', 'original_mime', 'original_size', 'original_path', 'page_count', 'published_at'])
            ->taskbooksFor((int) $employee->id)
            ->orderBy('sort')->orderBy('title')->get()];
    }
}
