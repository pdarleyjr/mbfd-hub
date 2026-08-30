<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Apparatus;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GenerateDailyCheckoutPreactivationManifestTest extends TestCase
{
    private const CONNECTION = 'daily_preactivation_generator_test';

    private const CANDIDATE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACTIVATION_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const CANDIDATE_IMAGE_DIGEST = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private string $candidateDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'mbfd-preactivation-generator-');
        if ($database === false) {
            self::fail('Could not create the disposable candidate database.');
        }
        $this->candidateDatabase = $database;
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => $this->candidateDatabase,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'daily_checkout_preactivation_candidate' => true,
            'daily_checkout_preactivation_read_only' => true,
        ]);
        DB::purge(self::CONNECTION);
        Artisan::call('migrate:fresh', [
            '--database' => self::CONNECTION,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        DB::disconnect(self::CONNECTION);
        if (isset($this->candidateDatabase) && is_file($this->candidateDatabase)) {
            unlink($this->candidateDatabase);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_checksum_bound_manifest_from_read_only_candidate_evidence(): void
    {
        $unknown = $this->makeApparatus('E1', [
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'pending',
        ]);
        $required = $this->makeApparatus('E2', [
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ]);
        $this->activateCutover([$unknown, $required]);
        $output = $this->newManifestPath();
        $queries = [];
        DB::connection(self::CONNECTION)->listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $status = Artisan::call('daily-checkout:generate-preactivation-manifest', [
            '--connection' => self::CONNECTION,
            '--snapshot-id' => 'snapshot-20260827-owner-beta',
            '--as-of' => '2026-08-27T20:00:00Z',
            '--candidate-sha' => self::CANDIDATE_SHA,
            '--candidate-image-digest' => self::CANDIDATE_IMAGE_DIGEST,
            '--approval-reference' => 'owner-beta-pr-220',
            '--output' => $output,
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertFileExists($output);
        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        $byId = collect($manifest['apparatus'])->keyBy('id');

        $this->assertSame('generated', $report['state']);
        $this->assertSame(self::CANDIDATE_SHA, $report['candidate_sha']);
        $this->assertSame(self::CANDIDATE_IMAGE_DIGEST, $report['candidate_image_digest']);
        $this->assertSame(2, $report['source_evidence']['apparatus_count']);
        $this->assertSame(0, $report['source_evidence']['legacy_status_transition_count']);
        $this->assertSame(hash('sha256', (string) file_get_contents($output)), $report['manifest_sha256']);
        $this->assertSame('unknown', $byId[$unknown->id]['daily_checkout_requirement']);
        $this->assertSame('unknown', $byId[$unknown->id]['operational_classification']);
        $this->assertNull($byId[$unknown->id]['expected_checklist_type']);
        $this->assertNull($byId[$unknown->id]['expected_checklist_version']);
        $this->assertSame('history_unavailable', $byId[$unknown->id]['pre_ledger_oos_review']['state']);
        $this->assertSame('legacy-apparatus-status-transition-count:0', $byId[$unknown->id]['pre_ledger_oos_review']['evidence_reference']);
        $this->assertSame('engine', $byId[$required->id]['expected_checklist_type']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $byId[$required->id]['expected_checklist_version']);
        $this->assertSame([], $manifest['expected_absent']);
        $this->assertSame(2, $manifest['schema_version']);
        $this->assertSame([
            'source_sha' => self::CANDIDATE_SHA,
            'image_digest' => self::CANDIDATE_IMAGE_DIGEST,
        ], $manifest['release_candidate']);
        $this->assertSame(self::ACTIVATION_SHA, $manifest['ledger_activation']['release_sha']);
        $this->assertSame(2, $manifest['ledger_activation']['apparatus_count']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|alter|drop|create|replace)\b/', $query);
        }
    }

    public function test_it_refuses_to_label_observed_legacy_status_evidence_as_history_unavailable(): void
    {
        $apparatus = $this->makeApparatus('E3');
        $this->activateCutover([$apparatus]);
        ApparatusOperationalStatusEvent::on(self::CONNECTION)->create([
            'apparatus_id' => $apparatus->id,
            'previous_status' => 'Out of Service',
            'status' => 'In Service',
            'changed_at' => '2026-08-27T19:00:00Z',
        ]);
        $output = $this->newManifestPath();

        $status = Artisan::call('daily-checkout:generate-preactivation-manifest', [
            '--connection' => self::CONNECTION,
            '--snapshot-id' => 'snapshot-20260827-owner-beta',
            '--as-of' => '2026-08-27T20:00:00Z',
            '--candidate-sha' => self::CANDIDATE_SHA,
            '--candidate-image-digest' => self::CANDIDATE_IMAGE_DIGEST,
            '--approval-reference' => 'owner-beta-pr-220',
            '--output' => $output,
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFileDoesNotExist($output);
        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('blocked', $report['state']);
        $this->assertContains('manifest_generator_legacy_status_events_require_review', $report['issues']);
    }

    public function test_it_fails_closed_without_a_persisted_immutable_activation_boundary(): void
    {
        $this->makeApparatus('E4');
        $output = $this->newManifestPath();

        $status = Artisan::call('daily-checkout:generate-preactivation-manifest', [
            '--connection' => self::CONNECTION,
            '--snapshot-id' => 'snapshot-20260827-owner-beta',
            '--as-of' => '2026-08-27T20:00:00Z',
            '--candidate-sha' => self::CANDIDATE_SHA,
            '--candidate-image-digest' => self::CANDIDATE_IMAGE_DIGEST,
            '--approval-reference' => 'owner-beta-pr-220',
            '--output' => $output,
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFileDoesNotExist($output);
        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('daily_checkout_ledger_cutover_missing', $report['issues']);
    }

    /** @param array<string, string> $overrides */
    private function makeApparatus(string $unitId, array $overrides = []): Apparatus
    {
        $station = Station::on(self::CONNECTION)->firstOrCreate([
            'station_number' => 1,
        ], [
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);

        return Apparatus::withoutEvents(fn (): Apparatus => Apparatus::on(self::CONNECTION)->create(array_merge([
            'station_id' => $station->id,
            'unit_id' => $unitId,
            'name' => "Engine {$unitId}",
            'type' => 'Engine',
            'vehicle_number' => "V{$unitId}",
            'designation' => $unitId,
            'slug' => strtolower($unitId),
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'pending',
        ], $overrides)));
    }

    private function newManifestPath(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-preactivation-generator-output-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory.DIRECTORY_SEPARATOR.'manifest.json';
    }

    /** @param list<Apparatus> $apparatuses */
    private function activateCutover(array $apparatuses): void
    {
        $snapshot = collect($apparatuses)
            ->sortBy('id')
            ->map(static fn (Apparatus $apparatus): array => [
                'id' => (int) $apparatus->id,
                'status' => $apparatus->status,
            ])
            ->values()
            ->all();
        $encodedSnapshot = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        DB::connection(self::CONNECTION)->table('daily_checkout_ledger_cutovers')->insert([
            'ledger' => 'daily_checkout',
            'release_sha' => self::ACTIVATION_SHA,
            'source' => 'owner_beta_activation',
            'activated_at' => '2026-08-26T12:00:00Z',
            'apparatus_status_snapshot' => $encodedSnapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
            'created_at' => '2026-08-26T12:00:00Z',
            'updated_at' => '2026-08-26T12:00:00Z',
        ]);
    }
}
