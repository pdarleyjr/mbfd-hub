<?php

/**
 * SAML IdP Configuration for MBFD Hub
 *
 * MBFD Hub acts as a SAML Identity Provider (IdP) so that
 * Snipe-IT (and potentially other service providers) can
 * authenticate users via SAML SSO without re-entering credentials.
 *
 * Package: codegreencreative/laravel-samlidp
 * Install: composer require codegreencreative/laravel-samlidp
 *
 * After installing, run:
 *   php artisan vendor:publish --tag=samlidp_config
 *   php artisan samlidp:cert   (generates self-signed cert)
 */

return [

    /*
    |--------------------------------------------------------------------------
    | SAML IdP Issuer (Entity ID)
    |--------------------------------------------------------------------------
    */
    'issuer' => env('SAML_IDP_ISSUER', 'https://www.mbfdhub.com'),

    /*
    |--------------------------------------------------------------------------
    | Login URI
    |--------------------------------------------------------------------------
    | The URI for the SSO endpoint. The metadata SingleSignOnService Location
    | will point here. Must handle SAMLRequest query parameter.
    */
    'login_uri' => 'saml/sso',

    /*
    |--------------------------------------------------------------------------
    | Issuer URI (metadata endpoint path)
    |--------------------------------------------------------------------------
    */
    'issuer_uri' => 'saml/metadata',

    /*
    |--------------------------------------------------------------------------
    | Certificate & Key Paths
    |--------------------------------------------------------------------------
    | Generated via: php artisan samlidp:cert
    */
    'cert' => 'file://' . storage_path('samlidp/cert.pem'),
    'key' => 'file://' . storage_path('samlidp/key.pem'),

    /*
    |--------------------------------------------------------------------------
    | Encryption & Signing
    |--------------------------------------------------------------------------
    */
    'encrypt_assertion' => false,
    'messages_signed' => true,
    'digest_algorithm' => \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,

    /*
    |--------------------------------------------------------------------------
    | Service Providers
    |--------------------------------------------------------------------------
    | Each SP that can authenticate via this IdP.
    | The key is the base64-encoded ACS URL; the value contains the ACS URL and logout URL.
    */
    'sp' => [
        // Snipe-IT at inventory.mbfdhub.com
        // Key must be base64_encode of the ACS URL
        base64_encode(env('SNIPEIT_SAML_ACS_URL', 'https://inventory.mbfdhub.com/saml/acs')) => [
            'destination' => env('SNIPEIT_SAML_ACS_URL', 'https://inventory.mbfdhub.com/saml/acs'),
            'logout' => env('SNIPEIT_SAML_SLS_URL', 'https://inventory.mbfdhub.com/saml/sls'),
            'query_params' => false,
            'encrypt_assertion' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Name ID Format
    |--------------------------------------------------------------------------
    */
    'name_id_format' => 'emailAddress',

    /*
    |--------------------------------------------------------------------------
    | Attribute Mapping
    |--------------------------------------------------------------------------
    | Maps Laravel User attributes to SAML assertion attributes.
    */
    'attributes' => [
        'email' => 'email',
        'name' => 'name',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Perform Single Logout (SLO)
    |--------------------------------------------------------------------------
    */
    'perform_single_logout' => true,

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    | List of auth guards the SAML IdP will listen to for Login/Logout events.
    */
    'guards' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Event Listeners
    |--------------------------------------------------------------------------
    */
    'events' => [
        'CodeGreenCreative\SamlIdp\Events\Assertion' => [],
        'Illuminate\Auth\Events\Logout' => ['CodeGreenCreative\SamlIdp\Listeners\SamlLogout'],
        'Illuminate\Auth\Events\Authenticated' => ['CodeGreenCreative\SamlIdp\Listeners\SamlAuthenticated'],
        'Illuminate\Auth\Events\Login' => ['CodeGreenCreative\SamlIdp\Listeners\SamlLogin'],
    ],
];
