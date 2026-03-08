<?php

namespace App\Http\Controllers;

use CodeGreenCreative\SamlIdp\Jobs\SamlSso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Custom SAML SSO Controller for MBFD Hub
 *
 * Handles the SAML SSO flow for Filament-based authentication.
 * The codegreencreative/laravel-samlidp package expects a /login route,
 * but MBFD Hub uses Filament with /admin/login. This controller bridges
 * the gap by:
 * 1. Receiving SAMLRequest at /saml/sso
 * 2. If user is authenticated → dispatching SamlSso job immediately
 * 3. If not → redirecting to Filament login with SAMLRequest in session
 */
class SamlSsoController extends Controller
{
    /**
     * Handle incoming SAML SSO request.
     *
     * GET /saml/sso?SAMLRequest=...&RelayState=...
     */
    public function __invoke(Request $request)
    {
        if (! $request->filled('SAMLRequest')) {
            abort(400, 'Missing SAMLRequest parameter');
        }

        // If user is already authenticated, process SAML response immediately
        if (Auth::check()) {
            $response = SamlSso::dispatchSync('web');
            return response($response, 200);
        }

        // Store SAMLRequest and RelayState in session for after login
        session([
            'saml_request' => $request->get('SAMLRequest'),
            'saml_relay_state' => $request->get('RelayState'),
        ]);

        // Redirect to Filament admin login
        return redirect('/admin/login');
    }
}
