<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\MemberBootstrapCredential;
use Illuminate\Console\Command;

final class MemberBootstrapStatus extends Command
{
    protected $signature = 'identity:member-bootstrap-status
                            {--require-available : Fail unless the kill switch and protected credential hash are valid}';

    protected $description = 'Report whether restricted member bootstrap authentication is available without exposing its credential.';

    public function handle(MemberBootstrapCredential $credential): int
    {
        $available = $credential->available();
        $this->line('MEMBER_BOOTSTRAP_AVAILABLE='.($available ? '1' : '0'));

        return $this->option('require-available') && ! $available
            ? self::FAILURE
            : self::SUCCESS;
    }
}
