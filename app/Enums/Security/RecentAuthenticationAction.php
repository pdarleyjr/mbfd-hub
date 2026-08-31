<?php

declare(strict_types=1);

namespace App\Enums\Security;

enum RecentAuthenticationAction: string
{
    case SecurityAdministration = 'security_administration';
    case CredentialChange = 'credential_change';
    case SensitiveOperation = 'sensitive_operation';
    case OrdinaryNavigation = 'ordinary_navigation';
}
