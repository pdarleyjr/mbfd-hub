<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Pages;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Models\AssignedEquipment;
use App\Models\EmployeeEquipmentRequest;
use App\Models\PersonnelRequest;
use App\Models\Uniform;
use Filament\Pages\Page;

class PersonnelOverview extends Page
{
    protected static ?string $cluster = PersonnelUniformsEquipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Personnel Uniforms / Equipment';

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = -100;

    protected static string $view = 'filament.clusters.personnel-uniforms-equipment.pages.overview';

    public function getViewData(): array
    {
        $active = [PersonnelRequestStatus::Pending->value, PersonnelRequestStatus::Acknowledged->value, PersonnelRequestStatus::NeedsInformation->value, PersonnelRequestStatus::Ordered->value, PersonnelRequestStatus::Arrived->value, PersonnelRequestStatus::ReadyForPickup->value];
        $base = PersonnelRequest::query();

        return [
            'uniform' => $this->summary((clone $base)->where('type', 'uniform'), $active),
            'equipment' => $this->summary((clone $base)->where('type', 'equipment'), $active),
            'recentUniform' => (clone $base)->where('type', 'uniform')->latest()->limit(5)->get(),
            'recentEquipment' => (clone $base)->where('type', 'equipment')->latest()->limit(5)->get(),
            'expiringSoon' => AssignedEquipment::query()->where('status', 'active')->whereBetween('expires_at', [today(), today()->addDays(60)])->count(),
            'expired' => AssignedEquipment::query()->where('status', 'active')->whereDate('expires_at', '<', today())->count(),
            'lowStock' => Uniform::query()->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count(),
            'legacyOpen' => EmployeeEquipmentRequest::query()->where('is_archived', false)->count(),
        ];
    }

    private function summary($query, array $active): array
    {
        return [
            'open' => (clone $query)->whereIn('status', $active)->count(),
            'needs_information' => (clone $query)->where('status', PersonnelRequestStatus::NeedsInformation)->count(),
            'ordered' => (clone $query)->where('status', PersonnelRequestStatus::Ordered)->count(),
            'arrived' => (clone $query)->where('status', PersonnelRequestStatus::Arrived)->count(),
            'ready' => (clone $query)->where('status', PersonnelRequestStatus::ReadyForPickup)->count(),
        ];
    }
}
