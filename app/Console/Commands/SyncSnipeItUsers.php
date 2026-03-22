<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSnipeItUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snipeit:sync-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync admin users to Snipe-IT via REST API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiUrl = config('services.snipeit.url');
        $apiToken = config('services.snipeit.token');

        if (empty($apiUrl) || empty($apiToken)) {
            $this->error('Snipe-IT API URL or Token is not configured in services.php');
            return Command::FAILURE;
        }

        $this->info('Starting Snipe-IT user sync...');

        // Get only admin users
        // Based on User::canAccessPanel('admin') logic
        $adminUsers = User::role([
            'super_admin', 
            'admin', 
            'logistics_admin',
            'training_admin',
            'training_viewer'
        ])->get();

        if ($adminUsers->isEmpty()) {
            $this->info('No admin users found to sync.');
            return Command::SUCCESS;
        }

        $this->info("Found {$adminUsers->count()} admin users to sync.");

        $successCount = 0;
        $errorCount = 0;

        foreach ($adminUsers as $user) {
            $this->line("Processing user: {$user->email}");

            // Explicitly check roles before pushing payload to satisfy constraints
            if (!$user->hasRole(['super_admin', 'admin', 'logistics_admin', 'training_admin', 'training_viewer'])) {
                $this->warn("Skipping user {$user->email} - does not have an admin role.");
                continue;
            }

            // Split name into first and last
            $nameParts = explode(' ', $user->name, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? 'User'; // Snipe-IT might require last name, provide fallback

            // Username can be the email prefix or the email itself
            $username = explode('@', $user->email)[0];

            $payload = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'email' => $user->email,
                'password' => \Illuminate\Support\Str::random(16), // Generate a random password
                'password_confirmation' => null, // Will be set to same as password
                'activated' => 1,
            ];
            $payload['password_confirmation'] = $payload['password'];

            // Check if user already exists by email
            try {
                $searchResponse = Http::withToken($apiToken)
                    ->acceptJson()
                    ->get(rtrim($apiUrl, '/') . '/users', [
                        'search' => $user->email,
                    ]);

                if ($searchResponse->successful()) {
                    $data = $searchResponse->json();
                    $existingUser = collect($data['rows'] ?? [])->firstWhere('email', $user->email);

                    if ($existingUser) {
                        $this->info("User {$user->email} already exists in Snipe-IT. Updating...");
                        
                        // Update existing user
                        $updateResponse = Http::withToken($apiToken)
                            ->acceptJson()
                            ->patch(rtrim($apiUrl, '/') . '/users/' . $existingUser['id'], [
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                // Don't update password for existing users
                            ]);

                        if ($updateResponse->successful()) {
                            $this->info("Successfully updated {$user->email}");
                            $successCount++;
                        } else {
                            $this->error("Failed to update {$user->email}: " . $updateResponse->body());
                            Log::error("Snipe-IT Sync Update Error for {$user->email}", ['response' => $updateResponse->json()]);
                            $errorCount++;
                        }
                        continue;
                    }
                }

                // Create new user
                $this->info("Creating new user {$user->email} in Snipe-IT...");
                $createResponse = Http::withToken($apiToken)
                    ->acceptJson()
                    ->post(rtrim($apiUrl, '/') . '/users', $payload);

                if ($createResponse->successful()) {
                    $this->info("Successfully created {$user->email}");
                    $successCount++;
                } else {
                    $this->error("Failed to create {$user->email}: " . $createResponse->body());
                    Log::error("Snipe-IT Sync Create Error for {$user->email}", ['response' => $createResponse->json()]);
                    $errorCount++;
                }

            } catch (\Exception $e) {
                $this->error("Exception while processing {$user->email}: " . $e->getMessage());
                Log::error("Snipe-IT Sync Exception for {$user->email}", ['exception' => $e->getMessage()]);
                $errorCount++;
            }
        }

        $this->info("Sync complete. Success: {$successCount}, Errors: {$errorCount}");

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
