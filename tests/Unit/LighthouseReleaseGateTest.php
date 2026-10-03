<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LighthouseReleaseGateTest extends TestCase
{
    public function test_workflow_uses_one_assertion_source_and_propagates_failures(): void
    {
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/lighthouse.yml');
        $configuration = file_get_contents(__DIR__.'/../../.lighthouserc.cjs');

        $this->assertIsString($workflow);
        $this->assertIsString($configuration);
        $this->assertStringContainsString('configPath: ./.lighthouserc.cjs', $workflow);
        $this->assertStringNotContainsString('budgetPath:', $workflow);
        $this->assertStringContainsString(
            'npx --yes @lhci/cli@0.15.1 assert --config=.lighthouserc.cjs --includePassedAssertions',
            $workflow
        );
        $this->assertStringContainsString("'resource-summary:image:size': ['error'", $configuration);
        $this->assertStringContainsString("'resource-summary:total:size': ['error'", $configuration);
        $this->assertFileDoesNotExist(__DIR__.'/../../budget.json');
    }

    public function test_ui_uses_a_bounded_logo_asset(): void
    {
        $logo = __DIR__.'/../../public/images/mbfd-official-seal-256.png';
        $shell = file_get_contents(__DIR__.'/../../resources/views/components/hub/shell.blade.php');
        $dimensions = getimagesize($logo);

        $this->assertIsString($shell);
        $this->assertIsArray($dimensions);
        $this->assertSame(256, $dimensions[0]);
        $this->assertSame(256, $dimensions[1]);
        $this->assertLessThanOrEqual(128 * 1024, filesize($logo));
        $this->assertStringContainsString('/images/mbfd-official-seal-256.png', $shell);
        $this->assertStringNotContainsString('/images/mbfd_logo.png', $shell);
    }
}
