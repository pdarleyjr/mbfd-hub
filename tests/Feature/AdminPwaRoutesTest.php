<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AdminPwaRoutesTest extends TestCase
{
    public function test_service_worker_is_served_from_within_its_admin_scope(): void
    {
        $response = $this->get('/admin/service-worker.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $this->assertStringContainsString(
            "addEventListener('install'",
            (string) file_get_contents(public_path('admin-pwa/service-worker.js')),
        );
    }
}
