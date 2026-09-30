<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * The SES tenant the CRM's own mail is sent under when Amazon SES is the
     * active provider. One per environment, so local and production never share
     * reputation or sending status. Credentials live on the Integrations page.
     */
    'ses_tenant' => [
        // The readable stem. The tenant actually used is this plus the suffix
        // below -- see SesTenantManager::tenantName().
        'name' => env('SES_TENANT_NAME') ?: 'shopify-crm-' . env('APP_ENV', 'production'),
        'explicit' => filled(env('SES_TENANT_NAME')),

        // An unguessable tail, so the tenant name is not simply "shopify-crm-live".
        //
        // With a configuration set whose suppression scope is TENANT, SES
        // rejects any message that does not name a valid tenant -- so a name
        // nobody can guess is one more thing an attacker holding only SMTP
        // credentials would still need. Defence in depth, not a primary
        // control: the credentials themselves are.
        //
        // Derived from APP_KEY rather than random, because the name must be
        // STABLE. A value that changed per boot would orphan the previous
        // tenant every restart -- still billing, invisible to this app. APP_KEY
        // also differs per environment, so local and production diverge for
        // free. Override with SES_TENANT_SUFFIX to pin one explicitly.
        'suffix' => env('SES_TENANT_SUFFIX') ?: substr(hash('sha256', 'ses-tenant|' . env('APP_KEY', '')), 0, 10),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:8080'),

];
