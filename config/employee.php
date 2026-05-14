<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Temporary Password
    |--------------------------------------------------------------------------
    |
    | Used by:
    |   - php artisan mbfd:reset-employee-password (when --password is omitted)
    |   - EmployeeResource bulk-reset action
    |
    | Set via env (EMPLOYEE_DEFAULT_TEMP_PASSWORD). NEVER hardcode here. The
    | reset action forces must_change_password=true so this is single-use.
    */
    'default_temp_password' => env('EMPLOYEE_DEFAULT_TEMP_PASSWORD', ''),
];
