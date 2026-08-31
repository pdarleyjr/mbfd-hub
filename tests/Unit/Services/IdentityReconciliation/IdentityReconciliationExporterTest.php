<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IdentityReconciliation;

use App\Services\IdentityReconciliation\CredentialInspector;
use App\Services\IdentityReconciliation\IdentityReconciliationExporter;
use App\Services\IdentityReconciliation\ReconciliationEngine;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\IdentityReconciliation\IdentityFixtures;

final class IdentityReconciliationExporterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = (new ReconciliationEngine(new CredentialInspector('synthetic-test-fingerprint-key')))
            ->reconcile([IdentityFixtures::user()], [IdentityFixtures::employee()]);
    }

    public function test_json_and_csv_exports_are_deterministic_and_redact_secret_material(): void
    {
        $exporter = new IdentityReconciliationExporter;

        $json = $exporter->toJson($this->report);
        $csv = $exporter->toCsv($this->report);

        $this->assertSame($json, $exporter->toJson($this->report));
        $this->assertSame($csv, $exporter->toCsv($this->report));
        $this->assertStringContainsString('"read_only": true', $json);
        $this->assertStringContainsString('entity_type,entity_id,classification', $csv);
        $this->assertStringNotContainsString(IdentityFixtures::BCRYPT_ONE, $json.$csv);
        $this->assertStringNotContainsString('remember_token', $json.$csv);
        $this->assertStringNotContainsString('recovery_secret', $json.$csv);
        $this->assertStringNotContainsString('api_key', $json.$csv);
        $this->assertStringNotContainsString('hash_fingerprint', $json.$csv);
    }

    public function test_local_artifacts_are_created_exclusively_and_never_overwritten(): void
    {
        $exporter = new IdentityReconciliationExporter;
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'identity-preview-'.bin2hex(random_bytes(8)).'.json';

        try {
            $exporter->writeExclusive($path, $exporter->toJson($this->report));
            $this->assertFileExists($path);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already exists');
            $exporter->writeExclusive($path, 'replacement');
        } finally {
            @unlink($path);
        }
    }
}
