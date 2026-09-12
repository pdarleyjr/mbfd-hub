<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CanonicalHostRedirect;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class CanonicalHostRedirectTest extends TestCase
{
    public function test_daily_apex_requests_redirect_to_the_configured_canonical_origin_with_path_and_query(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->middlewareResponse(
            Request::create('https://mbfdhub.com/daily/stations?foo=bar'),
        );

        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame(
            'https://www.mbfdhub.com/daily/stations?foo=bar',
            $response->headers->get('Location'),
        );
    }

    public function test_daily_requests_on_the_canonical_host_are_not_canonical_redirected(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->middlewareResponse(
            Request::create('https://www.mbfdhub.com/daily/stations?foo=bar'),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_localhost_remains_usable_for_daily_feature_tests(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->middlewareResponse(
            Request::create('http://localhost/daily/stations?foo=bar'),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_daily_route_redirects_apex_before_authentication(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->httpKernelResponse(
            Request::create('https://mbfdhub.com/daily/stations?foo=bar'),
        );

        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame(
            'https://www.mbfdhub.com/daily/stations?foo=bar',
            $response->headers->get('Location'),
        );
    }

    public function test_daily_route_on_the_canonical_host_reaches_the_authentication_boundary(): void
    {
        config(['app.url' => 'https://www.mbfdhub.com']);

        $response = $this->httpKernelResponse(
            Request::create('https://www.mbfdhub.com/daily/stations'),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'https://www.mbfdhub.com/login',
            $response->headers->get('Location'),
        );
    }

    private function middlewareResponse(Request $request): Response
    {
        return app(CanonicalHostRedirect::class)->handle(
            $request,
            static fn (Request $request): Response => response('next'),
        );
    }

    private function httpKernelResponse(Request $request): Response
    {
        return app(HttpKernel::class)->handle($request);
    }
}
