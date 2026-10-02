<?php

declare(strict_types=1);
use App\Support\ApplicationAccessRegistry;

require __DIR__.'/bootstrap.php';

// Test the one additive image integration without changing the pinned Hub source.
$original = (string) file_get_contents(MBFD_POLICY_HUB_FIXTURE_ROOT.'/app/Support/ApplicationAccessRegistry.php');
if (hash('sha256', $original) !== '9743b449e336ff6939e481fc528ea452acc43093e2cf68be3580909ccf37d48d') {
    throw new RuntimeException('Review the accepted Hub registry before updating this additive integration test.');
}
$needle = "        \$options['admin.communications.send'] = 'Communications — send email';";
$patched = str_replace($needle, $needle."\n        \$options['files.manage'] = 'Policy library — manage';", $original, $replacements);
if ($replacements !== 1) {
    throw new RuntimeException('The policy capability integration must replace exactly one reviewed anchor.');
}
$directory = MBFD_POLICY_HUB_FIXTURE_ROOT.'/var/policy-capability-overlay';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$overlay = $directory.'/ApplicationAccessRegistry.php';
file_put_contents($overlay, $patched);
$loader->addClassMap([ApplicationAccessRegistry::class => $overlay]);
