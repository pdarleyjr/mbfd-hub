<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\MemberBootstrapCohortInitializer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class InitializeMemberBootstrapCohort extends Command
{
    protected $signature = 'identity:initialize-member-bootstrap-cohort
                            {--manifest= : Protected provenance manifest for the exact cohort}
                            {--confirm= : Must equal INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT}';

    protected $description = 'Initialize only a manifest-proven cohort of canonical Users with unrecoverable provisioner credentials.';

    public function handle(MemberBootstrapCohortInitializer $initializer): int
    {
        if ($this->option('confirm') !== 'INITIALIZE_PROVEN_MEMBER_BOOTSTRAP_COHORT') {
            $this->error('Bootstrap cohort initialization requires the exact confirmation token.');

            return self::FAILURE;
        }
        $manifestPath = trim((string) $this->option('manifest'));
        if ($manifestPath === '') {
            $this->error('Bootstrap cohort initialization requires --manifest=<protected-path>.');

            return self::FAILURE;
        }

        try {
            $result = $initializer->initialize($manifestPath, CarbonImmutable::now());
            $this->line('BOOTSTRAP_COHORT_COUNT='.$result['cohort_count']);
            $this->line('BOOTSTRAP_COHORT_INITIALIZED='.$result['initialized']);
            $this->line('BOOTSTRAP_COHORT_ALREADY_ELIGIBLE='.$result['already_eligible']);
            $this->line('BOOTSTRAP_COHORT_MANIFEST_SHA256='.$result['manifest_sha256']);
            $this->line('BOOTSTRAP_COHORT_SOURCE_BACKUP_SHA256='.$result['source_backup_sha256']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Bootstrap cohort initialization failed safely: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
