<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\User;
use App\Services\Activity\MemberActivityFeed;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * "View all activity": everything the member sent or follows in the last 180 days, searchable and paged.
 * The card on the dashboard is the short version of the same list, from the same MemberActivityFeed.
 */
class MyActivity extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'My activity';

    protected static ?string $title = 'My activity';

    protected static ?string $slug = 'my-activity';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.employee.pages.my-activity';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $category = '';

    #[Url(as: 'p', except: 1)]
    public int $pageNumber = 1;

    public function updatedSearch(): void
    {
        $this->pageNumber = 1;
    }

    public function updatedCategory(): void
    {
        $this->pageNumber = 1;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->category = '';
        $this->pageNumber = 1;
    }

    public function previousPage(): void
    {
        $this->pageNumber = max(1, $this->pageNumber - 1);
    }

    public function nextPage(): void
    {
        $this->pageNumber++;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        $feed = app(MemberActivityFeed::class);
        $everything = $feed->forPage($user, $this->authenticatedEmployee());
        $matching = $feed->filter($everything, trim($this->search), $this->category);

        $size = max(1, (int) config('activity.member.page_size', 25));
        $pages = max(1, (int) ceil(count($matching) / $size));
        $current = min(max(1, $this->pageNumber), $pages);

        return [
            'items' => array_slice($matching, ($current - 1) * $size, $size),
            'total' => count($matching),
            'categories' => $feed->categories($everything),
            'isFiltered' => trim($this->search) !== '' || $this->category !== '',
            'currentPage' => $current,
            'pages' => $pages,
        ];
    }
}
