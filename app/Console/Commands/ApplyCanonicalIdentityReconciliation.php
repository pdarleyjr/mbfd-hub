<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IdentityReconciliation\CanonicalCredentialMigration;
use App\Services\IdentityReconciliation\InvalidOwnerLedger;
use App\Services\IdentityReconciliation\OwnerLedgerLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ApplyCanonicalIdentityReconciliation extends Command
{
    protected $signature = 'identity:reconcile-apply
                            {--approved-ledger= : Required strict JSON owner-approved mapping ledger}
                            {--snapshot-token= : Required token from identity:reconcile-preview using the same ledger}
                            {--confirm= : Must equal APPLY_OWNER_APPROVED_LINKS}';

    protected $description = 'Apply deterministic owner-approved User-to-Employee links and explicit compatible credential transitions.';

    public function handle(OwnerLedgerLoader $ledgers, CanonicalCredentialMigration $migration): int
    {
        $ledgerPath = $this->option('approved-ledger');
        $snapshotToken = $this->option('snapshot-token');
        $confirmation = $this->option('confirm');

        try {
            if (! is_string($ledgerPath) || $ledgerPath === '') {
                throw new RuntimeException('An owner-approved ledger path is required.');
            }

            $result = $migration->apply(
                $ledgers->load($ledgerPath),
                is_string($snapshotToken) ? $snapshotToken : '',
                is_string($confirmation) ? $confirmation : '',
            );
        } catch (InvalidOwnerLedger|RuntimeException $exception) {
            $this->error('Canonical identity apply blocked: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('canonical_identity_migration_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Canonical identity apply failed and no partial transaction was retained.');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
