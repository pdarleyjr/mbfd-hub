<?php

declare(strict_types=1);

namespace App\Enums\Security;

enum AccountSecurityAction: string
{
    case Disable = 'disable';
    case Enable = 'enable';
    case ForcePasswordChange = 'force_password_change';
    case AdministrativeRecovery = 'administrative_recovery';
    case RevokeSessions = 'revoke_sessions';
    case ChangeRecoverySettings = 'change_recovery_settings';
    case ResetSecurityState = 'reset_security_state';
    case SelfServicePasswordChange = 'self_service_password_change';
}
