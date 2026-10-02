<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$version = json_decode((string) file_get_contents('/opt/mbfd-policy-library/version.json'), true, 512, JSON_THROW_ON_ERROR);
if ($version['revision'] !== getenv('POLICY_LIBRARY_REVISION')
    || $version['base_image'] !== getenv('POLICY_LIBRARY_BASE_IMAGE')
    || $version['hub_composer_lock_sha256'] !== hash_file('sha256', '/var/www/html/composer.lock')
    || config('policy-library.domain') !== 'files.mbfdhub.com'
    || config('policy-library.scanner_enabled') !== true
    || (password_get_info((string) config('policy-library.pin_hash'))['algo'] ?? null) === null
    || config('queue.connections.policy-library.retry_after') !== 1860
    || config('policy-library.job_timeout') !== 1800) {
    throw new RuntimeException('The policy library runtime/provenance configuration is incomplete.');
}
$clam = @stream_socket_client('tcp://'.config('policy-library.clamd_host').':'.config('policy-library.clamd_port'), $errno, $error, 5);
if ($clam === false) {
    throw new RuntimeException('The configured policy library scanner is unavailable.');
}
stream_set_timeout($clam, 5);
fwrite($clam, "zPING\0");
$reply = stream_get_line($clam, 32, "\0");
fclose($clam);
if ($reply !== 'PONG') {
    throw new RuntimeException('The configured policy library scanner did not respond.');
}
echo "POLICY_LIBRARY_RUNTIME=PASS\n";
