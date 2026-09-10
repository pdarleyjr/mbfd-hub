<?php

declare(strict_types=1);

return [
    'critical_roles' => [
        'super_admin',
    ],

    'role_assignment' => [
        'delegator_roles' => [
            'super_admin',
        ],
        'allow_critical_role_changes' => true,
    ],

    'account_security' => [
        'allowed_administrative_actions' => [
            'administrative_recovery',
            'force_password_change',
            'revoke_sessions',
            'reset_security_state',
            'disable',
            'enable',
        ],
    ],

    'recent_authentication' => [
        'session_key' => 'auth.password_confirmed_at',
        'thresholds_seconds' => [
            'security_administration' => 300,
            'credential_change' => 300,
            'sensitive_operation' => 900,
            'ordinary_navigation' => null,
        ],
    ],

    'canonical_login' => [
        'max_attempts' => 5,
        'decay_seconds' => 60,
    ],

    'member_bootstrap' => [
        'max_attempts' => 5,
        'global_max_attempts' => 30,
        'decay_seconds' => 60,
    ],

    'identity_recovery' => [
        'max_attempts' => 3,
        'decay_seconds' => 900,
        'intent_seconds' => 600,
    ],

    'canonical_session' => [
        // Device posture is not inferred from request headers. D01 issues the
        // existing unmanaged-browser profile until an enrollment service is approved.
        'unmanaged_idle_seconds' => 3600,
        'unmanaged_absolute_seconds' => 86400,
    ],
];
