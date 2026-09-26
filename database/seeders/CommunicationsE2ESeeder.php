<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\InboundEmail;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class CommunicationsE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'communications_e2e.sqlite') {
            throw new RuntimeException('Communications browser fixtures require their disposable testing database.');
        }
        $password = env('COMMUNICATIONS_E2E_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('The browser test password must be supplied.');
        }
        $employee = Employee::query()->create(['employee_id' => '99881', 'name' => 'Communications Test Admin', 'rank' => 'Captain', 'city_email' => 'communicationsfixture@miamibeachfl.gov', 'roster_status' => 'active']);
        $admin = User::factory()->create([
            'name' => $employee->name, 'email' => $employee->city_email, 'email_verified_at' => now(),
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'password' => Hash::make($password), 'must_change_password' => false, 'account_status' => 'active',
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        foreach (['99882' => 'Selected Member', '99883' => 'Unselected Member'] as $number => $name) {
            $member = Employee::query()->create(['employee_id' => (string) $number, 'name' => $name, 'rank' => 'Firefighter', 'city_email' => "fixture{$number}@miamibeachfl.gov", 'roster_status' => 'active']);
            User::factory()->create(['name' => $name, 'email' => $member->city_email, 'employee_id' => $member->employee_id, 'employee_profile_id' => $member->id, 'account_status' => 'pending_activation', 'must_change_password' => true, 'bootstrap_onboarding_eligible' => true, 'bootstrap_onboarding_eligible_at' => now()]);
            MemberOnboardingRosterBinding::query()->create(['employee_profile_id' => $member->id, 'employee_id' => $member->employee_id, 'city_email' => $member->city_email, 'approved_at' => now(), 'source_sha256' => hash('sha256', 'communications_browser_fixture')]);
        }
        $outbound = OutboundEmail::query()->create([
            'provider' => 'cloudflare', 'provider_message_id' => '<fixture-outbound@mbfdhub.com>', 'message_id' => '<fixture-outbound@mbfdhub.com>',
            'initiated_by_user_id' => $admin->id, 'source_type' => 'admin_compose',
            'from_address' => 'info@mbfdhub.com', 'to_recipients' => ['recipient@example.test'],
            'cc_recipients' => ['colleague@example.test'], 'bcc_recipients' => ['private@example.test'],
            'subject' => 'Communications browser fixture', 'text_body' => 'A visible original message for browser verification.',
            'recipient_count' => 3, 'chargeable_budget_units' => 3, 'status' => 'delivered',
            'queued_at' => now()->subMinutes(4), 'submitted_at' => now()->subMinutes(3),
            'accepted_at' => now()->subMinutes(2), 'delivered_at' => now()->subMinute(),
        ]);
        $ledger = app(\App\Services\Communications\OutboundDeliveryLedger::class);
        foreach (['recipient@example.test', 'colleague@example.test', 'private@example.test'] as $address) {
            $ledger->record($outbound, $address, 'delivered', 'newEmailSending', \Carbon\CarbonImmutable::now()->subMinute());
        }
        InboundEmail::query()->create([
            'provider_message_id' => '<fixture-inbound@example.test>', 'from_address' => 'sender@example.test',
            'from_display_name' => 'Test Sender', 'to_address' => 'info@mbfdhub.com',
            'subject' => 'Incoming browser fixture', 'text_body' => 'Incoming message body.',
            'received_at' => now(), 'processing_status' => 'received',
            'safe_headers' => ['message-id' => '<fixture-inbound@example.test>', 'to' => 'info@mbfdhub.com', 'cc' => 'colleague@example.test'],
        ]);
    }
}
