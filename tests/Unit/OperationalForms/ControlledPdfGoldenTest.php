<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ControlledPdfGoldenTest extends TestCase
{
    public function test_froc_generator_accepts_normalized_null_optional_numeric_fields(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/OperationalForms/froc-log-001-ff-sample.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $fixture['data']['labor'][0]['manual_override_hours'] = null;
        $fixture['data']['labor'][0]['override_reason'] = null;
        $fixture['data']['vehicle_mileage'][0]['manual_miles'] = null;
        $fixture['data']['vehicle_mileage'][0]['correction_reason'] = null;
        $input = tempnam(sys_get_temp_dir(), 'mbfd-form-null-input-');
        $output = tempnam(sys_get_temp_dir(), 'mbfd-form-null-output-');
        $this->assertNotFalse($input);
        $this->assertNotFalse($output);

        try {
            file_put_contents($input, json_encode($fixture, JSON_THROW_ON_ERROR));
            $process = new Process([
                config('operational-forms.node_binary', 'node'),
                base_path('scripts/operational-forms/generate.mjs'),
                '--form', 'froc_log_001_ff',
                '--version', '11',
                '--input', $input,
                '--output', $output,
            ], base_path(), timeout: 60);
            $process->mustRun();

            $metadata = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(4, $metadata['page_count']);
            $this->assertSame('12.50', $metadata['calculated_totals']['p3_mileage_total_event']);
            $this->assertSame(0, $metadata['remaining_form_fields']);
            $this->assertSame(0, $metadata['remaining_annotations']);
        } finally {
            foreach ([$input, $output] as $path) {
                if (is_string($path) && file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[DataProvider('goldenCases')]
    public function test_controlled_generator_matches_pinned_golden_output(
        string $form,
        string $version,
        string $fixture,
        string $golden,
        string $sha256,
        int $pages,
    ): void {
        $output = tempnam(sys_get_temp_dir(), 'mbfd-form-golden-');
        $this->assertNotFalse($output);

        try {
            $process = new Process([
                config('operational-forms.node_binary', 'node'),
                base_path('scripts/operational-forms/generate.mjs'),
                '--form', $form,
                '--version', $version,
                '--input', base_path($fixture),
                '--output', $output,
            ], base_path(), timeout: 60);
            $process->mustRun();

            $metadata = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($sha256, hash_file('sha256', $output));
            $this->assertSame($sha256, hash_file('sha256', base_path($golden)));
            $this->assertSame(file_get_contents(base_path($golden)), file_get_contents($output));
            $this->assertSame($pages, $metadata['page_count']);
            $this->assertSame(0, $metadata['remaining_form_fields']);
            $this->assertSame(0, $metadata['remaining_annotations']);
        } finally {
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
        }
    }

    public static function goldenCases(): array
    {
        return [
            'ICS 214' => [
                'ics_214',
                '1.0',
                'tests/Fixtures/OperationalForms/ics-214-sample.json',
                'docs/operational-forms/samples/ICS-214-sample.pdf',
                '6c832bd5c2def1938fde79fb42bc062c18191b05f1975aada3f8753849d7e69f',
                1,
            ],
            'FROC v11' => [
                'froc_log_001_ff',
                '11',
                'tests/Fixtures/OperationalForms/froc-log-001-ff-sample.json',
                'docs/operational-forms/samples/FROC-LOG-001-FF-v11-sample.pdf',
                '9826099d7b1efaffa8820339e7712b6c65050f95e2e4918e4cc4a796f88a5991',
                4,
            ],
        ];
    }
}
