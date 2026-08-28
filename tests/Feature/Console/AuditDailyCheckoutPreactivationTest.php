<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\Station;
use App\Services\DailyCheckoutChecklistResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditDailyCheckoutPreactivationTest extends TestCase
{
    private const CONNECTION = 'daily_preactivation_test';

    private const CANDIDATE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private array $manifestPaths = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    private string $candidateDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'mbfd-preactivation-candidate-');
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
        $this->app->forgetInstance(DailyCheckoutChecklistResolver::class);

        foreach ($this->manifestPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

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

    public function test_it_requires_explicit_snapshot_manifest_and_as_of_inputs(): void
    {
        $status = Artisan::call('daily-checkout:preactivation', ['--json' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('preactivation_input_missing', Artisan::output());
    }

    public function test_it_requires_a_valid_immutable_candidate_sha(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);

        [$status, $report] = $this->preactivate(
            $this->writeManifest($this->manifestFor(
                $apparatus,
                expectedChecklistType: 'engine',
                expectedChecklistVersion: (string) $resolution['checklist_version'],
            )),
            candidateSha: 'not-an-immutable-sha',
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('preactivation_input_invalid:candidate_sha', $report['issues']);
    }

    public function test_it_requires_the_owner_supplied_manifest_to_bind_the_candidate_sha(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        );
        $manifest['candidate_sha'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('policy_manifest_candidate_sha_mismatch', $report['issues']);
    }

    public function test_it_requires_an_explicit_expected_absent_attestation(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        );
        unset($manifest['expected_absent']);

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('policy_manifest_expected_absent_missing', $report['issues']);
    }

    public function test_it_requires_an_explicit_candidate_read_only_connection_declaration(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        config()->set('database.connections.'.self::CONNECTION.'.daily_checkout_preactivation_candidate', false);
        config()->set('database.connections.'.self::CONNECTION.'.daily_checkout_preactivation_read_only', false);

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('preactivation_connection_not_authorized_candidate', $report['issues']);
        $this->assertContains('preactivation_connection_not_declared_read_only', $report['issues']);
    }

    public function test_it_rejects_default_connection_read_write_aliases_before_any_query(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        ));
        $queryConnections = [];
        DB::listen(static function (QueryExecuted $query) use (&$queryConnections): void {
            $queryConnections[] = $query->connectionName;
        });

        $defaultConnection = (string) config('database.default');
        [$defaultStatus, $defaultReport] = $this->preactivate($manifest, $defaultConnection);
        [$readStatus, $readReport] = $this->preactivate($manifest, $defaultConnection.'::read');
        [$writeStatus, $writeReport] = $this->preactivate($manifest, $defaultConnection.'::write');

        $this->assertSame(Command::FAILURE, $defaultStatus);
        $this->assertContains('preactivation_connection_must_not_be_default', $defaultReport['issues']);
        $this->assertSame(Command::FAILURE, $readStatus);
        $this->assertContains('preactivation_connection_alias_not_allowed', $readReport['issues']);
        $this->assertSame(Command::FAILURE, $writeStatus);
        $this->assertContains('preactivation_connection_alias_not_allowed', $writeReport['issues']);
        $this->assertSame([], $queryConnections);
    }

    public function test_it_emits_a_complete_reconciled_matrix_without_mutating_the_candidate_dataset(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
            'vehicle_number' => 'E-1',
        ]);
        $resolver = app(DailyCheckoutChecklistResolver::class);
        $resolution = $resolver->resolve($apparatus);
        $this->assertTrue($resolution['usable']);

        $completedAt = CarbonImmutable::parse('2026-08-26T14:00:00Z');
        $this->makeInspection($apparatus, $completedAt, (string) $resolution['checklist_version']);

        $before = [
            'apparatuses' => Apparatus::on(self::CONNECTION)->count(),
            'inspections' => ApparatusInspection::on(self::CONNECTION)->count(),
            'status' => $this->freshApparatus($apparatus)->status,
            'requirement' => $this->freshApparatus($apparatus)->getRawOriginal('daily_checkout_requirement'),
            'template' => $this->freshApparatus($apparatus)->getRawOriginal('daily_checkout_template'),
        ];
        $queryConnections = [];
        $mutatingStatements = [];
        DB::listen(static function (QueryExecuted $query) use (&$queryConnections, &$mutatingStatements): void {
            $queryConnections[] = $query->connectionName;
            if (preg_match('/^\s*(?:alter|create|delete|drop|insert|replace|truncate|update)\b/i', $query->sql) === 1) {
                $mutatingStatements[] = $query->sql;
            }
        });

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertTrue($report['gate_passed']);
        $this->assertTrue($report['read_only']);
        $this->assertSame('snapshot-20260826-a', $report['input']['snapshot_id']);
        $this->assertCount(1, $report['apparatus']);

        $row = $report['apparatus'][0];
        $this->assertSame($apparatus->id, $row['apparatus_id']);
        $this->assertSame('E-1', $row['vehicle_number']);
        $this->assertSame('active', $row['operational_classification']);
        $this->assertSame('required', $row['policy']['manifest_requirement']);
        $this->assertSame('required', $row['policy']['database_requirement']);
        $this->assertSame('engine', $row['checklist']['checklist_type']);
        $this->assertSame($resolution['checklist_version'], $row['checklist']['checklist_version']);
        $this->assertGreaterThan(0, $row['checklist']['item_count']);
        $this->assertSame([], $row['checklist']['duplicate_identifiers']);
        $this->assertSame('checked', $row['canonical_daily']['state']);
        $this->assertSame('approved', $row['current_inspection']['latest']['review_status']);
        $this->assertSame('no_same_day_return', $row['oos_ledger']['pre_ledger_review']['state']);
        $this->assertNull($row['oos_ledger']['return_to_service']);
        $this->assertNotEmpty($queryConnections);
        $this->assertSame([self::CONNECTION], array_values(array_unique($queryConnections)));
        $this->assertSame([], $mutatingStatements);

        $this->assertSame($before['apparatuses'], Apparatus::on(self::CONNECTION)->count());
        $this->assertSame($before['inspections'], ApparatusInspection::on(self::CONNECTION)->count());
        $this->assertSame($before['status'], $this->freshApparatus($apparatus)->status);
        $this->assertSame($before['requirement'], $this->freshApparatus($apparatus)->getRawOriginal('daily_checkout_requirement'));
        $this->assertSame($before['template'], $this->freshApparatus($apparatus)->getRawOriginal('daily_checkout_template'));
    }

    public function test_it_blocks_unknown_policy_by_default_while_leaving_the_classification_visible_and_excluded_from_completion(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'engine',
        ]);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFalse($report['gate_passed']);
        $this->assertFalse($report['input']['allow_classification_required']);
        $this->assertSame([], $report['technical_issues']);
        $this->assertSame(['classification_required'], $report['policy_issues']);
        $this->assertSame(['classification_required'], $report['blocking_policy_issues']);
        $this->assertContains('classification_required', $report['issues']);

        $row = $report['apparatus'][0];
        $this->assertTrue($row['classification_required']);
        $this->assertSame('unknown', $row['policy']['manifest_requirement']);
        $this->assertSame('unknown', $row['policy']['database_requirement']);
        $this->assertSame('classification_required', $row['canonical_daily']['state']);
        $this->assertFalse($row['canonical_daily']['included_in_required_total']);
        $this->assertFalse($row['canonical_daily']['included_in_completed']);
    }

    public function test_it_allows_an_unknown_policy_only_with_the_explicit_beta_classification_flag(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'engine',
        ]);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);

        [$status, $report] = $this->preactivate(
            $this->writeManifest($this->manifestFor(
                $apparatus,
                expectedChecklistType: 'engine',
                expectedChecklistVersion: (string) $resolution['checklist_version'],
            )),
            allowClassificationRequired: true,
        );

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertTrue($report['gate_passed']);
        $this->assertTrue($report['input']['allow_classification_required']);
        $this->assertSame([], $report['technical_issues']);
        $this->assertSame(['classification_required'], $report['policy_issues']);
        $this->assertSame([], $report['blocking_policy_issues']);
        $this->assertSame([], $report['issues']);

        $row = $report['apparatus'][0];
        $this->assertTrue($row['classification_required']);
        $this->assertSame('unknown', $row['policy']['manifest_requirement']);
        $this->assertSame('unknown', $row['policy']['database_requirement']);
        $this->assertSame('classification_required', $row['canonical_daily']['state']);
        $this->assertFalse($row['canonical_daily']['included_in_required_total']);
        $this->assertFalse($row['canonical_daily']['included_in_completed']);
    }

    public function test_it_blocks_an_unresolved_operational_classification_by_default_and_allows_it_only_for_owner_beta(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ]);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->makeInspection($apparatus, CarbonImmutable::parse('2026-08-26T14:00:00Z'), (string) $resolution['checklist_version']);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            operationalClassification: 'unknown',
        );

        [$defaultStatus, $defaultReport] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $defaultStatus);
        $this->assertFalse($defaultReport['gate_passed']);
        $this->assertSame([], $defaultReport['technical_issues']);
        $this->assertSame(['operational_classification_required'], $defaultReport['policy_issues']);
        $this->assertSame(['operational_classification_required'], $defaultReport['blocking_policy_issues']);
        $this->assertFalse($defaultReport['apparatus'][0]['classification_required']);
        $this->assertSame('unknown', $defaultReport['apparatus'][0]['operational_classification']);

        [$betaStatus, $betaReport] = $this->preactivate(
            $this->writeManifest($manifest),
            allowClassificationRequired: true,
        );

        $this->assertSame(Command::SUCCESS, $betaStatus);
        $this->assertTrue($betaReport['gate_passed']);
        $this->assertSame([], $betaReport['technical_issues']);
        $this->assertSame(['operational_classification_required'], $betaReport['policy_issues']);
        $this->assertSame([], $betaReport['blocking_policy_issues']);
        $this->assertFalse($betaReport['apparatus'][0]['classification_required']);
        $this->assertSame('unknown', $betaReport['apparatus'][0]['operational_classification']);
    }

    public function test_it_rejects_an_unrecognized_operational_classification(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            operationalClassification: 'guessed',
        );

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('policy_manifest_operational_classification_invalid', $report['issues']);
    }

    public function test_the_beta_classification_flag_does_not_waive_a_policy_integrity_mismatch(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'unknown',
            'daily_checkout_template' => 'engine',
        ]);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            operationalClassification: 'unknown',
        );
        $manifest['apparatus'][0]['designation'] = 'MISMATCHED-UNIT';

        [$status, $report] = $this->preactivate(
            $this->writeManifest($manifest),
            allowClassificationRequired: true,
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFalse($report['gate_passed']);
        $this->assertTrue($report['input']['allow_classification_required']);
        $this->assertContains('policy_data_mismatch:designation', $report['technical_issues']);
        $this->assertSame(['operational_classification_required', 'classification_required'], $report['policy_issues']);
        $this->assertSame([], $report['blocking_policy_issues']);
        $this->assertContains('policy_data_mismatch:designation', $report['issues']);
    }

    public function test_it_fails_closed_when_snapshot_and_policy_records_do_not_reconcile_both_directions(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ]);
        $resolver = app(DailyCheckoutChecklistResolver::class);
        $resolution = $resolver->resolve($apparatus);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        );
        $manifest['apparatus'][0]['id'] = $apparatus->id + 1000;

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFalse($report['gate_passed']);
        $this->assertContains('expected_apparatus_missing', $report['issues']);
        $this->assertContains('apparatus_present_but_not_in_approved_policy', $report['issues']);
    }

    public function test_it_surfaces_duplicate_checklist_identifiers_as_structured_evidence_and_blocks_a_required_apparatus(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-preactivation-checklist-'.uniqid('', true);
        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;
        file_put_contents($directory.DIRECTORY_SEPARATOR.'default_checklist.json', json_encode([
            'compartments' => [[
                'id' => 'scba_radio',
                'title' => 'SCBA Numbers & Radio Numbers',
                'items' => [
                    ['name' => 'Crew Radio # - FI'],
                    ['name' => 'Crew Radio # - FI'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->app->instance(DailyCheckoutChecklistResolver::class, new DailyCheckoutChecklistResolver($directory));

        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'default',
        ]);
        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'default',
            expectedChecklistVersion: str_repeat('0', 64),
        );

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertFalse($row['checklist']['usable']);
        $this->assertSame('invalid_schema', $row['checklist']['error']);
        $this->assertSame('duplicate_item_label', $row['checklist']['duplicate_identifiers'][0]['code']);
        $this->assertSame('scba_radio', $row['checklist']['duplicate_identifiers'][0]['compartment_id']);
        $this->assertSame('Crew Radio # - FI', $row['checklist']['duplicate_identifiers'][0]['label']);
        $this->assertContains('required_apparatus_checklist_unusable', $row['issues']);
    }

    public function test_it_requires_an_approved_checkout_strictly_after_a_manually_confirmed_pre_ledger_return(): void
    {
        $apparatus = $this->makeApparatus([
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ]);
        $resolver = app(DailyCheckoutChecklistResolver::class);
        $resolution = $resolver->resolve($apparatus);
        $returnedAt = CarbonImmutable::parse('2026-08-26T13:00:00Z');
        $this->makeInspection($apparatus, $returnedAt, (string) $resolution['checklist_version']);

        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'returned_to_service',
                'return_to_service_at_utc' => $returnedAt->toIso8601String(),
                'evidence_reference' => 'owner-cutover-review-1',
            ],
        );

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertSame($returnedAt->toIso8601String(), $row['oos_ledger']['return_to_service']['at_utc']);
        $this->assertNull($row['oos_ledger']['qualifying_post_return_inspection']);
        $this->assertContains('post_return_checkout_missing_or_not_strictly_after_return', $row['issues']);
    }

    public function test_it_excludes_a_post_snapshot_checkout_from_canonical_and_return_evidence(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $returnedAt = CarbonImmutable::parse('2026-08-26T13:00:00Z');
        $this->makeInspection(
            $apparatus,
            CarbonImmutable::parse('2026-08-26T17:00:00Z'),
            (string) $resolution['checklist_version'],
        );

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'returned_to_service',
                'return_to_service_at_utc' => $returnedAt->toIso8601String(),
                'evidence_reference' => 'owner-cutover-review-1',
            ],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertSame('not_checked', $row['canonical_daily']['state']);
        $this->assertNull($row['oos_ledger']['qualifying_post_return_inspection']);
        $this->assertContains('post_return_checkout_missing_or_not_strictly_after_return', $row['issues']);
    }

    public function test_it_requires_a_checked_required_apparatus_to_have_the_manifest_checklist_version(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->makeInspection($apparatus, CarbonImmutable::parse('2026-08-26T14:00:00Z'), str_repeat('a', 64));

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertSame('checked', $row['canonical_daily']['state']);
        $this->assertContains('current_approved_checkout_checklist_version_mismatch', $row['issues']);
    }

    public function test_it_requires_a_post_return_checkout_to_have_the_manifest_checklist_version(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $returnedAt = CarbonImmutable::parse('2026-08-26T13:00:00Z');
        $this->makeInspection($apparatus, CarbonImmutable::parse('2026-08-26T14:00:00Z'), str_repeat('a', 64));

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'returned_to_service',
                'return_to_service_at_utc' => $returnedAt->toIso8601String(),
                'evidence_reference' => 'owner-cutover-review-1',
            ],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertNull($row['oos_ledger']['qualifying_post_return_inspection']);
        $this->assertContains('post_return_checkout_checklist_version_mismatch', $row['issues']);
    }

    public function test_it_treats_zero_legacy_transition_evidence_as_history_unavailable_not_ambiguous(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $this->makeInspection($apparatus, CarbonImmutable::parse('2026-08-26T11:00:00Z'), (string) $resolution['checklist_version']);

        $manifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'history_unavailable',
                'return_to_service_at_utc' => null,
                'evidence_reference' => 'legacy-status-transition-count:0',
            ],
        );

        [$status, $report] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], $report['technical_issues']);
        $row = $report['apparatus'][0];
        $this->assertSame('history_unavailable', $row['oos_ledger']['pre_ledger_review']['state']);
        $this->assertNull($row['oos_ledger']['return_to_service']);
        $this->assertSame('not_checked', $row['canonical_daily']['state']);
        $this->assertFalse($row['canonical_daily']['included_in_completed']);
        $this->assertFalse(in_array('historical_oos_return_ambiguous', $row['technical_issues'], true));

        $this->makeInspection($apparatus, CarbonImmutable::parse('2026-08-26T14:00:00Z'), (string) $resolution['checklist_version']);
        [$postCutoverStatus, $postCutoverReport] = $this->preactivate($this->writeManifest($manifest));

        $this->assertSame(Command::SUCCESS, $postCutoverStatus);
        $this->assertSame('checked', $postCutoverReport['apparatus'][0]['canonical_daily']['state']);
        $this->assertTrue($postCutoverReport['apparatus'][0]['canonical_daily']['cutover_checkout_verified']);
    }

    public function test_it_requires_a_matching_persisted_cutover_boundary_without_mutating_the_candidate(): void
    {
        $apparatus = $this->makeApparatus(activateCutover: false);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        ));

        [$missingStatus, $missingReport] = $this->preactivate($manifest);

        $this->assertSame(Command::FAILURE, $missingStatus);
        $this->assertContains('daily_checkout_ledger_cutover_missing', $missingReport['technical_issues']);
        $this->assertTrue($missingReport['read_only']);

        $this->activateCutover(
            $apparatus,
            CarbonImmutable::parse('2026-08-26T12:00:00Z'),
            str_repeat('b', 40),
        );
        [$mismatchStatus, $mismatchReport] = $this->preactivate($manifest);

        $this->assertSame(Command::FAILURE, $mismatchStatus);
        $this->assertContains('daily_checkout_ledger_cutover_candidate_sha_mismatch', $mismatchReport['technical_issues']);
    }

    public function test_it_fails_closed_when_a_persisted_cutover_snapshot_is_malformed(): void
    {
        $apparatus = $this->makeApparatus(activateCutover: false);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $manifest = $this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        ));
        $this->activateCutover(
            $apparatus,
            CarbonImmutable::parse('2026-08-26T12:00:00Z'),
            snapshot: [['id' => (int) $apparatus->id]],
        );

        [$status, $report] = $this->preactivate($manifest);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertContains('daily_checkout_ledger_cutover_snapshot_invalid', $report['technical_issues']);
    }

    public function test_it_requires_evidence_for_an_actual_unresolved_historical_chronology_and_keeps_it_blocking(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);

        $missingEvidenceManifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'unresolved',
                'return_to_service_at_utc' => null,
                'evidence_reference' => null,
            ],
        );
        [$missingEvidenceStatus, $missingEvidenceReport] = $this->preactivate($this->writeManifest($missingEvidenceManifest));

        $this->assertSame(Command::FAILURE, $missingEvidenceStatus);
        $this->assertContains('policy_manifest_pre_ledger_oos_evidence_missing', $missingEvidenceReport['issues']);

        $ambiguousManifest = $this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'unresolved',
                'return_to_service_at_utc' => null,
                'evidence_reference' => 'legacy-dispatch-conflict:record-42',
            ],
        );
        [$ambiguousStatus, $ambiguousReport] = $this->preactivate(
            $this->writeManifest($ambiguousManifest),
            allowClassificationRequired: true,
        );

        $this->assertSame(Command::FAILURE, $ambiguousStatus);
        $this->assertContains('historical_oos_return_ambiguous', $ambiguousReport['technical_issues']);
        $this->assertContains('historical_oos_return_ambiguous', $ambiguousReport['apparatus'][0]['issues']);
    }

    public function test_it_does_not_treat_a_submission_without_a_payload_hash_as_post_return_evidence(): void
    {
        $apparatus = $this->makeApparatus();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $returnedAt = CarbonImmutable::parse('2026-08-26T13:00:00Z');
        $this->makeInspection(
            $apparatus,
            CarbonImmutable::parse('2026-08-26T14:00:00Z'),
            (string) $resolution['checklist_version'],
            withPayloadHash: false,
        );

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
            preLedgerReview: [
                'state' => 'returned_to_service',
                'return_to_service_at_utc' => $returnedAt->toIso8601String(),
                'evidence_reference' => 'owner-cutover-review-1',
            ],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertSame('not_checked', $row['canonical_daily']['state']);
        $this->assertNull($row['oos_ledger']['qualifying_post_return_inspection']);
        $this->assertContains('post_return_checkout_missing_or_not_strictly_after_return', $row['issues']);
    }

    public function test_it_fails_closed_when_an_oos_transition_ends_in_an_ambiguous_reserve_status(): void
    {
        $apparatus = $this->makeApparatus(['status' => 'Reserve']);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        ApparatusOperationalStatusEvent::on(self::CONNECTION)->create([
            'apparatus_id' => $apparatus->id,
            'previous_status' => 'Out of Service',
            'status' => 'Reserve',
            'changed_at' => CarbonImmutable::parse('2026-08-26T13:00:00Z'),
        ]);

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::FAILURE, $status);
        $row = $report['apparatus'][0];
        $this->assertSame('ambiguous', $row['oos_ledger']['state']);
        $this->assertContains('operational_status_ambiguous', $row['issues']);
    }

    public function test_it_includes_a_return_recorded_in_the_cutover_storage_second(): void
    {
        $apparatus = $this->makeApparatus(['status' => 'Out of Service']);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus);
        $cutoverAt = CarbonImmutable::parse('2026-08-26T12:00:00Z');
        Apparatus::on(self::CONNECTION)
            ->whereKey($apparatus->id)
            ->update(['status' => 'In Service']);
        ApparatusOperationalStatusEvent::on(self::CONNECTION)->create([
            'apparatus_id' => $apparatus->id,
            'previous_status' => 'Out of Service',
            'status' => 'In Service',
            'changed_at' => $cutoverAt,
        ]);
        $this->makeInspection(
            $apparatus,
            CarbonImmutable::parse('2026-08-26T13:00:00Z'),
            (string) $resolution['checklist_version'],
        );

        [$status, $report] = $this->preactivate($this->writeManifest($this->manifestFor(
            $apparatus,
            expectedChecklistType: 'engine',
            expectedChecklistVersion: (string) $resolution['checklist_version'],
        )));

        $this->assertSame(Command::SUCCESS, $status);
        $return = $report['apparatus'][0]['oos_ledger']['return_to_service'];
        $this->assertSame('operational_status_ledger', $return['source']);
        $this->assertSame($cutoverAt->toIso8601String(), $return['at_utc']);
    }

    /** @param array<string, mixed> $overrides */
    private function makeApparatus(array $overrides = [], bool $activateCutover = true): Apparatus
    {
        $station = Station::on(self::CONNECTION)->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);

        $apparatus = Apparatus::withoutEvents(fn (): Apparatus => Apparatus::on(self::CONNECTION)->create(array_merge([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V1',
            'designation' => 'E1',
            'slug' => 'e1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
            'daily_checkout_template' => 'engine',
        ], $overrides)));
        if ($activateCutover) {
            $this->activateCutover($apparatus, CarbonImmutable::parse('2026-08-26T12:00:00Z'));
        }

        return $apparatus;
    }

    /**
     * @param  array<string, mixed>|null  $preLedgerReview
     * @return array<string, mixed>
     */
    private function manifestFor(
        Apparatus $apparatus,
        string $expectedChecklistType,
        string $expectedChecklistVersion,
        ?array $preLedgerReview = null,
        string $operationalClassification = 'active',
    ): array {
        $station = Station::on(self::CONNECTION)->findOrFail($apparatus->station_id);

        return [
            'schema_version' => 1,
            'snapshot_id' => 'snapshot-20260826-a',
            'as_of_utc' => '2026-08-26T16:00:00+00:00',
            'candidate_sha' => self::CANDIDATE_SHA,
            'approval' => [
                'reference' => 'owner-policy-review-1',
            ],
            'apparatus' => [[
                'id' => $apparatus->id,
                'unit_id' => $apparatus->unit_id,
                'designation' => $apparatus->designation,
                'name' => $apparatus->name,
                'vehicle_number' => $apparatus->vehicle_number,
                'type' => $apparatus->type,
                'station_number' => (string) $station->station_number,
                'operational_classification' => $operationalClassification,
                'daily_checkout_requirement' => $apparatus->getRawOriginal('daily_checkout_requirement'),
                'daily_checkout_template' => $apparatus->getRawOriginal('daily_checkout_template'),
                'expected_checklist_type' => $expectedChecklistType,
                'expected_checklist_version' => $expectedChecklistVersion,
                'slug' => $apparatus->slug,
                'pre_ledger_oos_review' => $preLedgerReview ?? [
                    'state' => 'no_same_day_return',
                    'return_to_service_at_utc' => null,
                    'evidence_reference' => 'owner-cutover-review-1',
                ],
            ]],
            'expected_absent' => [],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-preactivation-manifest-');
        if ($path === false) {
            self::fail('Could not create a temporary Daily Checkout policy manifest.');
        }

        $this->manifestPaths[] = $path;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array{int, array<string, mixed>} */
    private function preactivate(
        string $manifestPath,
        ?string $connection = null,
        ?string $candidateSha = self::CANDIDATE_SHA,
        bool $allowClassificationRequired = false,
    ): array {
        $arguments = [
            '--json' => true,
            '--policy-manifest' => $manifestPath,
            '--snapshot-id' => 'snapshot-20260826-a',
            '--as-of' => '2026-08-26T16:00:00Z',
            '--connection' => $connection ?? self::CONNECTION,
            '--candidate-sha' => $candidateSha,
        ];
        if ($allowClassificationRequired) {
            $arguments['--allow-classification-required'] = true;
        }

        $status = Artisan::call('daily-checkout:preactivation', $arguments);
        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return [$status, $report];
    }

    private function freshApparatus(Apparatus $apparatus): Apparatus
    {
        return Apparatus::on(self::CONNECTION)->findOrFail($apparatus->id);
    }

    /** @param list<array{id: int, status?: string|null}>|null $snapshot */
    private function activateCutover(
        Apparatus $apparatus,
        CarbonImmutable $activatedAt,
        string $releaseSha = self::CANDIDATE_SHA,
        ?array $snapshot = null,
    ): void {
        $snapshot ??= [[
            'id' => $apparatus->id,
            'status' => $apparatus->status,
        ]];
        $encodedSnapshot = json_encode($snapshot, JSON_THROW_ON_ERROR);

        DB::connection(self::CONNECTION)->table('daily_checkout_ledger_cutovers')->insert([
            'ledger' => 'daily_checkout',
            'release_sha' => $releaseSha,
            'source' => 'owner_beta_activation',
            'activated_at' => $activatedAt,
            'apparatus_status_snapshot' => $encodedSnapshot,
            'snapshot_sha256' => hash('sha256', $encodedSnapshot),
            'apparatus_count' => count($snapshot),
            'created_at' => $activatedAt,
            'updated_at' => $activatedAt,
        ]);
    }

    private function makeInspection(
        Apparatus $apparatus,
        CarbonImmutable $completedAt,
        string $checklistVersion,
        bool $withPayloadHash = true,
    ): ApparatusInspection {
        $attributes = [
            'apparatus_id' => $apparatus->id,
            'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $checklistVersion,
            'operator_name' => 'Test Operator',
            'rank' => 'Firefighter',
            'review_status' => 'approved',
            'completed_at' => $completedAt,
        ];
        if ($withPayloadHash) {
            $attributes['submission_payload_hash'] = hash('sha256', $checklistVersion.$completedAt->toIso8601String());
        }

        return ApparatusInspection::withoutEvents(
            fn (): ApparatusInspection => ApparatusInspection::on(self::CONNECTION)->create($attributes),
        );
    }
}
