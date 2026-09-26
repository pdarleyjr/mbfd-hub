<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StationRequestType;
use App\Models\Apparatus;
use App\Models\CityEmailVerification;
use App\Models\DepartmentUpdate;
use App\Models\Employee;
use App\Models\HubSupportTicket;
use App\Models\Room;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventoryEntry;
use App\Models\TrtInventorySession;
use App\Models\TrtInventorySubmission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class ProtectedUiE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'protected_ui_e2e.sqlite') {
            throw new RuntimeException('Protected UI fixtures require the disposable protected_ui_e2e.sqlite database.');
        }
        $password = env('PROTECTED_UI_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 24) {
            throw new RuntimeException('A random ephemeral fixture password is required.');
        }
        // Local presentation fixture only; the external incident integration stays disabled.
        Cache::put('pulsepoint_incidents', [
            'active' => [], 'recent' => [], 'fetchedAt' => now()->toISOString(),
            'fixture' => 'protected-ui-local-empty-feed',
        ], now()->addHour());
        $employee = Employee::query()->create([
            'employee_id' => '99871', 'name' => 'Protected UI Test Captain', 'rank' => 'Captain',
            'roster_status' => 'active', 'city_email' => 'protected-ui@example.test',
            'password' => Hash::make(bin2hex(random_bytes(32))), 'must_change_password' => false,
        ]);
        $user = new User;
        $user->forceFill([
            'name' => $employee->name, 'email' => $employee->city_email, 'email_verified_at' => now(),
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'password' => Hash::make($password), 'must_change_password' => false,
            'account_status' => 'active', 'is_admin' => true,
        ])->save();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $trtSession = TrtInventorySession::findOrCreateForToday();
        foreach ([true, false] as $present) {
            $catalogItem = TrtInventoryCatalogItem::query()->create([
                'name' => $present
                    ? 'Technical rescue confined-space retrieval and high-angle rope equipment package with extended operational label'
                    : 'Local fixture replacement rescue harness',
                'category' => 'Local acceptance rescue equipment', 'expected_quantity' => 2,
                'sort_order' => $present ? 1 : 2, 'active' => true,
            ]);
            TrtInventoryEntry::query()->create([
                'session_id' => $trtSession->id, 'user_id' => $user->id, 'catalog_item_id' => $catalogItem->id,
                'present' => $present, 'actual_quantity' => $present ? 2 : 0,
                'condition' => $present ? 'excellent' : 'poor', 'action' => $present ? 'keep' : 'replace',
            ]);
        }
        TrtInventorySubmission::query()->create([
            'client_submission_id' => (string) Str::uuid(), 'session_id' => $trtSession->id,
            'actor_user_id' => $user->id, 'payload_hash' => hash('sha256', 'disposable-local-presentation-fixture'),
            'entries_count' => 2,
        ]);
        // Keep the reserved fixture address and model an acknowledged, pending review.
        // No mailbox is contacted and no verification token is issued.
        CityEmailVerification::query()->create([
            'user_id' => $user->id, 'employee_profile_id' => $employee->id,
            'email' => 'protected-ui-disposable-fixture@miamibeachfl.gov', 'original_user_email' => $user->email,
            'original_employee_city_email' => $employee->city_email,
            'security_version' => $user->fresh()->security_version,
            'acknowledged_at' => now(), 'delivery_status' => 'pending',
        ]);
        foreach ([
            'Apparatus checkout confirmation remains unavailable after reconnecting the station tablet to the operational network',
            'LongUnbrokenOperationalReference'.str_repeat('1234567890', 9),
        ] as $title) {
            HubSupportTicket::query()->create([
                'client_submission_id' => (string) Str::uuid(), 'reported_by_user_id' => $user->id,
                'reported_by_employee_id' => $employee->id, 'reporter_name_snapshot' => $employee->name,
                'description' => 'Disposable local presentation fixture; no operational issue or outbound notification.',
                'generated_title' => $title, 'category' => 'other', 'impact' => 'task_blocking',
                'affected_component' => 'daily_checkout', 'status' => 'new', 'diagnostics_schema_version' => 1,
            ]);
        }
        DepartmentUpdate::query()->create([
            'title' => 'Local browser acceptance update', 'body' => '<p>Disposable UI fixture for update details and contextual return navigation.</p>',
            'category' => 'general', 'priority' => 'normal', 'status' => 'published', 'audience' => 'everyone',
            'publish_at' => now()->subHour(), 'first_published_at' => now()->subHour(),
            'author_id' => $user->id, 'send_in_app' => false, 'send_web_push' => false,
        ]);
        foreach ([1, 2, 3, 4, 6] as $number) {
            $station = Station::query()->create([
                'station_number' => $number, 'name' => "Station {$number}",
                'address' => "{$number} Test Street", 'is_active' => true,
            ]);
            if ($number === 1) {
                $room = Room::query()->create(['station_id' => $station->id, 'name' => 'Local UI test day room']);
                File::ensureDirectoryExists(base_path('test-results/protected-ui-auth'));
                File::put(base_path('test-results/protected-ui-auth/fixture-routes.json'), json_encode([
                    '/daily/stations/:id' => '/daily/stations/'.$station->id,
                    '/daily/stations/:stationId/rooms/:roomId' => '/daily/stations/'.$station->id.'/rooms/'.$room->id,
                ], JSON_THROW_ON_ERROR));
            }
            Apparatus::query()->create([
                'station_id' => $station->id, 'unit_id' => "E{$number}", 'designation' => "E{$number}",
                'vehicle_number' => "E{$number}", 'make' => 'Fixture', 'model' => 'Engine',
                'status' => 'In Service', 'daily_checkout_requirement' => 'required',
                'current_engine_hours' => 100, 'last_pm_engine_hours' => 0, 'pm_interval_hours' => 300,
            ]);
            StationRequest::query()->create([
                'station_id' => $station->id, 'requester_name_snapshot' => $employee->name,
                'request_type' => StationRequestType::RepairService->value,
                'title' => 'Apparatus bay overhead door intermittently stops before closing and requires facilities inspection',
                'description' => 'Disposable local browser fixture for long attention labels and timestamp containment.',
                'priority' => 'high', 'status' => 'pending',
            ]);
        }
    }
}
