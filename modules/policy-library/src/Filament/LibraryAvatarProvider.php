<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

final class LibraryAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        return asset('vendor/policy-library/images/mbfd-logo.png');
    }
}
