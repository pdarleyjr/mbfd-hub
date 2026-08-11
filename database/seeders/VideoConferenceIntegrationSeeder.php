<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

class VideoConferenceIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('The video conference integration user may only be seeded locally.');
        }

        $password = env('VIDEO_CONFERENCING_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('Set VIDEO_CONFERENCING_E2E_PASSWORD to at least 12 characters.');
        }

        Employee::query()->updateOrCreate(
            ['employee_id' => env('VIDEO_CONFERENCING_E2E_EMPLOYEE_ID', 'VC-E2E')],
            [
                'name' => 'Conference Integration',
                'rank' => 'Test',
                'password' => $password,
                'must_change_password' => false,
            ],
        );
    }
}
