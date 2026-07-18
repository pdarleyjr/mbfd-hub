<?php

declare(strict_types=1);

namespace Tests\Unit\OperationalForms;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ControlledPdfGoldenTest extends TestCase
{
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
                '2dae797b4258fbe3adb7cfca9c129f4cbfbf8db3b052d8bcb661b117045c6dfd',
                1,
            ],
            'FROC v11' => [
                'froc_log_001_ff',
                '11',
                'tests/Fixtures/OperationalForms/froc-log-001-ff-sample.json',
                'docs/operational-forms/samples/FROC-LOG-001-FF-v11-sample.pdf',
                '91a0ef2c90bb1fe3196a442b8eaa6133cacbfd40f58c43f1bca1008217685a3c',
                4,
            ],
        ];
    }
}
