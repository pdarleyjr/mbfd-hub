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
}
