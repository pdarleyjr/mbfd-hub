<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finding H-04 — database-independent guarantees for private file storage.
 *
 * These assertions need no migrations, so they run on any driver (including the
 * local SQLite default) and complement the DB-backed PrivateFileStorageTest.
 */
class PrivateStorageConfigTest extends TestCase
{
    public function test_private_disk_is_configured_and_not_the_public_disk(): void
    {
        $private = config('filesystems.private');

        $this->assertNotNull($private, 'A private disk must be configured (filesystems.private).');
        $this->assertNotSame('public', $private, 'The private disk must not be the web-reachable public disk.');

        $diskConfig = config("filesystems.disks.{$private}");
        $this->assertIsArray($diskConfig, "The private disk [{$private}] must be defined under filesystems.disks.");

        // The default private disk (local) must not be the public web root and
        // must not advertise public visibility.
        if ($private === 'local') {
            $this->assertSame(
                storage_path('app/private'),
                $diskConfig['root'],
                'The local private disk must point at storage/app/private (not the symlinked public root).'
            );
            $this->assertNotSame('public', $diskConfig['visibility'] ?? null);
        }
    }

    public function test_public_disk_remains_for_genuinely_public_assets(): void
    {
        // We do not remove the public disk — logos and admin-panel image previews
        // still use it. We only move SENSITIVE files off it.
        $this->assertIsArray(config('filesystems.disks.public'));
    }

    public function test_sensitive_download_routes_require_authentication(): void
    {
        $authRoutes = [
            'download-inventory-pdf',
            'workgroup.file.download',
            'workgroup.file.preview',
            'workgroup.shared-upload.download',
        ];

        foreach ($authRoutes as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] should exist.");
            $this->assertContains('auth', $route->gatherMiddleware(), "Route [{$name}] must require auth.");
        }
    }

    public function test_workgroup_file_routes_require_workgroup_access(): void
    {
        foreach (['workgroup.file.download', 'workgroup.file.preview', 'workgroup.shared-upload.download'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] should exist.");
            $this->assertContains('workgroup.access', $route->gatherMiddleware(), "Route [{$name}] must require workgroup access.");
        }
    }
}
