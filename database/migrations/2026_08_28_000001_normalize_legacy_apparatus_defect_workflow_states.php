<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeUnresolvedCheckoutStatus('missing');
        $this->normalizeUnresolvedCheckoutStatus('damaged');
    }

    public function down(): void
    {
        // The original workflow status cannot be inferred safely after normalization.
    }

    private function normalizeUnresolvedCheckoutStatus(string $issueType): void
    {
        DB::table('apparatus_defects')
            ->where('resolved', false)
            ->whereRaw('LOWER(status) = ?', [$issueType])
            ->update([
                'status' => 'open',
                'issue_type' => $issueType,
            ]);
    }
};
