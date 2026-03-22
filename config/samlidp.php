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
    | Certificate & Key Paths
    |--------------------------------------------------------------------------
    | Generated via: php artisan samlidp:cert
    */
    'cert' => storage_path('samlidp/cert.pem'),
    'key' => storage_path('samlidp/key.pem'),

    /*
    |--------------------------------------------------------------------------
    | Service Providers
    |--------------------------------------------------------------------------
    | Each SP that can authenticate via this IdP.
    | The key is the SP Entity ID; the value contains the ACS URL and logout URL.
    */
    'sp' => [
        // Snipe-IT at inventory.mbfdhub.com
        env('SNIPEIT_SAML_ENTITY_ID', 'https://inventory.mbfdhub.com') => [
            'destination' => env('SNIPEIT_SAML_ACS_URL', 'https://inventory.mbfdhub.com/saml/acs'),
            'logout' => env('SNIPEIT_SAML_SLS_URL', 'https://inventory.mbfdhub.com/saml/sls'),
            'query_params' => false,
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

];
