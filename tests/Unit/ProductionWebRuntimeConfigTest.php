<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract for the production web tier: nginx buffers whole requests and
 * responses in front of a php-fpm pool, replacing the PHP built-in server.
 */
class ProductionWebRuntimeConfigTest extends TestCase
{
    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertIsString($contents, "{$path} must exist");

        return $contents;
    }

    public function test_image_installs_and_wires_nginx_and_php_fpm(): void
    {
        $dockerfile = $this->read('docker/production/Dockerfile');

        $this->assertMatchesRegularExpression('/^\s+nginx \\\\$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^\s+php8\.5-fpm \\\\$/m', $dockerfile);
        $this->assertStringContainsString('COPY docker/production/nginx.conf /etc/nginx/hub/nginx.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/production/php-fpm.conf /etc/php/8.5/fpm/php-fpm-hub.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/production/php.ini /etc/php/8.5/fpm/conf.d/99-mbfd-production.ini', $dockerfile);
        $this->assertStringContainsString('COPY docker/production/php-fpm.ini /etc/php/8.5/fpm/conf.d/99-mbfd-web.ini', $dockerfile);
        // Same extensions in the web pool as in the CLI, and configs proven as the runtime user.
        $this->assertStringContainsString('diff -u /tmp/cli-modules /tmp/fpm-modules', $dockerfile);
        $this->assertMatchesRegularExpression('/USER sail\s+.*?nginx -t -q -e stderr -c \/etc\/nginx\/hub\/nginx\.conf/s', $dockerfile);
        $this->assertStringNotContainsString('artisan serve', $dockerfile);
    }

    public function test_nginx_buffers_uploads_and_responses_so_slow_clients_never_hold_php(): void
    {
        $nginx = $this->read('docker/production/nginx.conf');

        $this->assertStringContainsString('client_max_body_size 100m;', $nginx);
        $this->assertStringContainsString('client_body_buffer_size 512k;', $nginx);
        $this->assertStringContainsString('client_body_temp_path /var/lib/hub-web/tmp/client-body 1 2;', $nginx);
        $this->assertStringContainsString('fastcgi_request_buffering on;', $nginx);
        $this->assertStringContainsString('fastcgi_buffering on;', $nginx);
        $this->assertStringContainsString('fastcgi_pass unix:/var/lib/hub-web/run/php-fpm.sock;', $nginx);
        $this->assertStringContainsString('gzip on;', $nginx);
        $this->assertStringContainsString('keepalive_timeout 100s;', $nginx);
        $this->assertStringNotContainsString('fastcgi_keep_conn on', $nginx, 'kept-alive FastCGI connections would pin idle php-fpm children');

        // The read timeout outlives php-fpm's own wall-clock ceiling.
        $fpm = $this->read('docker/production/php-fpm.conf');
        preg_match('/fastcgi_read_timeout (\d+)s;/', $nginx, $read);
        preg_match('/^request_terminate_timeout = (\d+)s$/m', $fpm, $terminate);
        $this->assertNotEmpty($read);
        $this->assertNotEmpty($terminate);
        $this->assertGreaterThan((int) $terminate[1], (int) $read[1]);
    }

    public function test_access_log_never_writes_a_token_from_the_path_or_query(): void
    {
        $nginx = $this->read('docker/production/nginx.conf');

        $this->assertStringContainsString('"path":"$hub_log_path_safe"', $nginx);
        $this->assertStringNotContainsString('"path":"$hub_log_path"', $nginx);
        $this->assertStringNotContainsString('$http_referer', $nginx, 'a Referer can carry a token link');
        $this->assertStringNotContainsString('$args', $nginx);

        $token = str_repeat('a1b2c3d4', 8);
        $redacted = [
            "/reset-password/{$token}" => '/reset-password/[redacted]',
            "/reset-password/{$token}?email=member%40example.test" => '/reset-password/[redacted]',
            "//Reset-Password/{$token}" => '/reset-password/[redacted]',
            "/account/city-email/verify/{$token}" => '/account/city-email/verify/[redacted]',
            "/supply-kiosk/pair/{$token}" => '/supply-kiosk/pair/[redacted]',
            "/some/future/{$token}/route" => '/some/future/[redacted]/route',
            "/signed/{$token}" => '/signed/[redacted]',
        ];
        foreach ($redacted as $requestUri => $logged) {
            $this->assertSame($logged, $this->loggedPath($nginx, $requestUri), $requestUri);
        }

        // Ordinary paths, hashed assets and record ids stay readable; a query string never reaches the log.
        foreach ([
            '/daily/vehicle-inspections/engine-2',
            '/daily/assets/index-Bx7d9Kp2.js',
            '/api/public/inspection-photos',
            '/take-home-inspections/photos/0f8fad5b-d9cb-469f-a165-70867728950e',
            '/employee/activity/open/inspection:42',
            '/supply-kiosk/pair-code',
        ] as $path) {
            $this->assertSame($path, $this->loggedPath($nginx, $path.'?token=secret'), $path);
        }
    }

    /**
     * What the access log writes for one request URI, by applying the two nginx maps
     * ($request_uri -> $hub_log_path -> $hub_log_path_safe) with PCRE, as nginx does.
     */
    private function loggedPath(string $nginx, string $requestUri): string
    {
        return $this->applyMap($nginx, 'map $hub_log_path $hub_log_path_safe', $this->applyMap($nginx, 'map $request_uri $hub_log_path', $requestUri));
    }

    private function applyMap(string $nginx, string $header, string $input): string
    {
        $start = strpos($nginx, $header.' {');
        $this->assertNotFalse($start, "{$header} must exist");
        $end = strpos($nginx, "\n    }", $start);
        $block = substr($nginx, $start, (int) $end - $start);
        preg_match_all('/^\s+"(~\*?)([^"]+)"\s+("[^"]*"|\$\w+);$/m', $block, $entries, PREG_SET_ORDER);
        $this->assertNotEmpty($entries, "{$header} has regex entries");
        foreach ($entries as [, $operator, $pattern, $value]) {
            $flags = $operator === '~*' ? 'i' : '';
            if (preg_match('#'.str_replace('#', '\#', $pattern).'#'.$flags, $input, $match) !== 1) {
                continue;
            }

            return (string) preg_replace_callback('/\$\{?(\w+)\}?/', static fn (array $name): string => $match[$name[1]] ?? '', trim($value, '"'));
        }
        preg_match('/^\s+default\s+("[^"]*"|\$\w+);$/m', $block, $default);
        $this->assertNotEmpty($default, "{$header} has a default");

        return $default[1] === '$hub_log_path' ? $input : trim($default[1], '"');
    }

    public function test_php_fpm_pool_is_non_root_inherits_the_environment_and_is_sized(): void
    {
        $fpm = $this->read('docker/production/php-fpm.conf');

        $this->assertDoesNotMatchRegularExpression('/^\s*(user|group)\s*=/m', $fpm);
        $this->assertStringContainsString('listen = /var/lib/hub-web/run/php-fpm.sock', $fpm);
        $this->assertStringContainsString('pm = dynamic', $fpm);
        $this->assertStringContainsString('pm.max_children = ${PHP_FPM_MAX_CHILDREN}', $fpm);
        $this->assertStringContainsString('pm.max_requests = 1000', $fpm);
        $this->assertStringContainsString('clear_env = no', $fpm);
        $this->assertStringContainsString('daemonize = no', $fpm);
        $this->assertStringContainsString('process_control_timeout = 60s', $fpm);
    }

    public function test_web_pool_opcache_never_revalidates_and_limits_match_uploads(): void
    {
        $shared = $this->read('docker/production/php.ini');
        $web = $this->read('docker/production/php-fpm.ini');

        $this->assertStringContainsString('upload_max_filesize = 100M', $shared);
        $this->assertStringContainsString('post_max_size = 100M', $shared);
        $this->assertStringContainsString('variables_order = EGPCS', $shared);
        $this->assertStringContainsString('opcache.validate_timestamps = 0', $web);
        $this->assertStringContainsString('output_buffering = 0', $web);
        $this->assertStringContainsString('expose_php = Off', $web);
        $this->assertStringNotContainsString('validate_timestamps', $shared, 'the CLI keeps validating timestamps');
    }

    public function test_supervisor_stops_the_web_tier_gracefully(): void
    {
        $supervisor = $this->read('docker/production/supervisord.conf');

        $this->assertMatchesRegularExpression('/\[program:php-fpm\].*?stopsignal=QUIT/s', $supervisor);
        $this->assertMatchesRegularExpression('/\[program:nginx\].*?stopsignal=QUIT/s', $supervisor);
        $this->assertStringNotContainsString('artisan serve', $supervisor);
        $this->assertStringNotContainsString('SUPERVISOR_PHP_COMMAND', $supervisor);
    }

    public function test_storage_app_can_move_to_another_disk_without_changing_container_paths(): void
    {
        $compose = $this->read('compose.prod.image.yaml');

        $this->assertStringContainsString('source: "${HUB_STORAGE_APP_PATH:-${HUB_STORAGE_PATH}/app}"', $compose);
        $this->assertMatchesRegularExpression(
            '/source: "\$\{HUB_STORAGE_APP_PATH:-\$\{HUB_STORAGE_PATH\}\/app\}"\s+target: \/var\/www\/html\/storage\/app\s+bind:\s+create_host_path: false/',
            $compose,
        );
        $this->assertStringContainsString('"${HUB_STORAGE_PATH:?HUB_STORAGE_PATH must be the existing writable Hub storage directory}:/var/www/html/storage"', $compose);
        $this->assertFileExists(dirname(__DIR__, 2).'/docs/ops/storage-on-mbfd-storage.md');
    }

    public function test_an_unmounted_storage_array_never_starts_the_hub_and_unset_changes_nothing(): void
    {
        $compose = $this->read('compose.prod.image.yaml');
        $check = $this->read('docker/production/check-storage-mount');
        $entrypoint = $this->read('docker/production/start-production-container');
        $dockerfile = $this->read('docker/production/Dockerfile');
        $deploy = $this->read('.github/workflows/deploy.yml');
        $runbook = $this->read('docs/ops/storage-on-mbfd-storage.md');

        // Required only when storage/app comes from HUB_STORAGE_APP_PATH (":+" is empty when it is unset).
        $this->assertStringContainsString('HUB_STORAGE_APP_SENTINEL_REQUIRED: "${HUB_STORAGE_APP_PATH:+1}"', $compose);
        $this->assertStringContainsString('STORAGE_SENTINEL=/var/www/html/storage/app/.mbfd-storage-sentinel', $check);
        $this->assertMatchesRegularExpression('/if \[ "\$\{HUB_STORAGE_APP_SENTINEL_REQUIRED:-\}" = \'1\' \] && \[ ! -f "\$STORAGE_SENTINEL" \]; then\s+echo .*>&2\s+exit 1\s+fi/', $check);
        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $check);
        $this->assertStringContainsString('set -euo pipefail', $check);

        // Checked by the entrypoint before anything starts, and by deploy before the running release is replaced.
        $this->assertMatchesRegularExpression('/\/usr\/local\/bin\/check-storage-mount\s+exec \/usr\/bin\/supervisord/', $entrypoint);
        $this->assertStringContainsString('COPY docker/production/check-storage-mount /usr/local/bin/check-storage-mount', $dockerfile);
        $this->assertMatchesRegularExpression('/chmod 0755 [^;]*\/usr\/local\/bin\/check-storage-mount/', $dockerfile);
        $inventory = strpos($deploy, 'identity:provision-universal-accounts');
        $guard = strpos($deploy, '/usr/local/bin/check-storage-mount');
        $activation = strpos($deploy, 'up -d --pull never --no-build --no-deps --force-recreate laravel.test');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($activation);
        $this->assertLessThan($inventory, $guard);
        $this->assertLessThan($activation, $guard);

        // The runbook creates the sentinel after the last delete-sync, on the mounted array.
        $create = strpos($runbook, 'sudo install -o 1000 -g 1000 -m 0644 /dev/null "$DST/.mbfd-storage-sentinel"');
        $lastDeleteSync = strrpos(substr($runbook, 0, (int) strpos($runbook, '## 4. Switch the mount')), 'rsync -aHAX --numeric-ids --delete');
        $this->assertNotFalse($create);
        $this->assertNotFalse($lastDeleteSync);
        $this->assertGreaterThan($lastDeleteSync, $create);
        $this->assertLessThan(strpos($runbook, 'echo "HUB_STORAGE_APP_PATH=$DST"'), $create);
    }
}
