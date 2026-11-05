<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipmentRuleResource\Pages;

use App\Filament\Resources\SupportEquipmentRuleResource;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\SupportTriage\EquipmentRuleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateSupportEquipmentRule extends CreateRecord
{
    protected static string $resource = SupportEquipmentRuleResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Add equipment';
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            return app(EquipmentRuleService::class)->create($data, $user);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Added. It is used for the next report.';
    }
}
