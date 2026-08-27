<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Models\Employee;
use App\Support\BidApiUrl;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only view of the certifications the bid app holds for this member.
 *
 * The bid Cloudflare Worker is the system-of-record for bid credentials —
 * admins toggle pills on /admin/members/roster to grant or revoke certs,
 * and each toggle writes an `override_cert` audit row. This page surfaces
 * the same list to the member so they can verify what's on file (and ask
 * an admin to update anything that's wrong).
 *
 * Members cannot edit from here. The "Bid Console" button at the top
 * jumps them to the bid app to participate in the live bid.
 */
class MyBidCertificationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static string $view = 'filament.employee.pages.my-bid-certifications';

    protected static ?string $title = 'My Bid Certifications';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Bid Certifications';

    protected static ?string $slug = 'my-bid-certifications';

    public function getViewData(): array
    {
        /** @var Employee $employee */
        $employee = auth('employee')->user();

        $payload = $this->fetchFromBidApp((string) $employee->employee_id);

        return [
            'user' => $employee,
            'certifications' => $payload['credentials'] ?? [],
            'bidConsoleUrl' => (string) config('services.bid.console_url', ''),
            'fetchError' => $payload['error'] ?? null,
            'lastUpdated' => $payload['lastUpdated'] ?? null,
        ];
    }

    /**
     * @return array{credentials?: array<int, string>, error?: string, lastUpdated?: string}
     */
    private function fetchFromBidApp(string $employeeId): array
    {
        $base = (string) (config('services.bid.console_url') ?? '');
        $token = (string) (config('services.bid.reader_token') ?? '');

        if ($base === '' || $token === '') {
            return ['error' => 'Bid Console not configured. Ask an admin to set BID_CONSOLE_URL and BID_READER_TOKEN.'];
        }

        // The bid Worker exposes a read endpoint scoped by employee_id with
        // the same bearer token used by /verify-credentials. Worker side
        // returns `{ credentials: string[], lastUpdated: ISO8601 }`.
        $url = BidApiUrl::fromConsoleUrl($base)
            .'/api/portal/members/'
            .rawurlencode($employeeId)
            .'/credentials';

        try {
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('bid.fetchCertifications.exception', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Could not reach the bid app. Try again in a minute.'];
        }

        if ($response->status() === 404) {
            return ['credentials' => []];
        }

        if (! $response->successful()) {
            Log::warning('bid.fetchCertifications.bad_status', [
                'employee_id' => $employeeId,
                'status' => $response->status(),
            ]);

            return ['error' => 'Bid app returned status '.$response->status().'.'];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return ['error' => 'Bid app returned an unexpected response.'];
        }

        return [
            'credentials' => array_values(array_filter(
                (array) ($body['credentials'] ?? []),
                static fn ($v): bool => is_string($v) && $v !== '',
            )),
            'lastUpdated' => (string) ($body['lastUpdated'] ?? ''),
        ];
    }
}
