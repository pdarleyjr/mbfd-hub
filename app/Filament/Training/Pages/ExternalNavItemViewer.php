<?php

namespace App\Filament\Training\Pages;

use App\Models\ExternalNavItem;
use App\Services\Baserow\BaserowClient;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ExternalNavItemViewer extends Page
{
    protected static string $view = 'filament.training.pages.external-nav-item-viewer';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'external-viewer/{itemSlug}';

    public string $itemSlug = '';

    public ?ExternalNavItem $navItem = null;

    public array $tableData = [];

    public array $tableFields = [];

    public function mount(string $itemSlug): void
    {
        $this->itemSlug = $itemSlug;

        $this->navItem = ExternalNavItem::query()
            ->active()
            ->forDivision('training')
            ->where('slug', $itemSlug)
            ->first();

        if (! $this->navItem) {
            abort(404);
        }

        // Check role access
        $user = auth()->user();
        $userRoles = $user->getRoleNames()->toArray();
        $allowedRoles = $this->navItem->allowed_roles ?? [];

        if (empty(array_intersect($userRoles, $allowedRoles))) {
            abort(403);
        }

        if ($this->navItem->type === 'api_table' && $this->navItem->externalSource) {
            $client = BaserowClient::fromSource($this->navItem->externalSource);

            if ($this->navItem->baserow_table_id) {
                $this->tableFields = $client->getFields($this->navItem->baserow_table_id);

                $query = [];
                if ($this->navItem->baserow_view_id) {
                    $query['view_id'] = $this->navItem->baserow_view_id;
                }
                $result = $client->listRows($this->navItem->baserow_table_id, array_merge($query, ['size' => 100]));
                $this->tableData = $result['results'] ?? [];
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->navItem?->label ?? 'External View';
    }

    public static function getRouteName(?string $panel = null): string
    {
        return 'filament.training.pages.external-viewer';
    }
}
