<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Board URL
    |--------------------------------------------------------------------------
    |
    | Origin serving the merchant-facing board. This is the frontend SPA, not
    | the API, and is what the embed snippet shown to admins points at.
    |
    */

    'url' => env('BOARD_URL', env('FRONTEND_URL', 'http://localhost:8080')),

    /*
    |--------------------------------------------------------------------------
    | App Token Lifetime
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) a token signed by a Shopify app stays valid. The
    | token is exchanged for a board session the moment the board loads, so
    | this window only needs to cover page render plus clock skew.
    |
    */

    'token_ttl' => env('BOARD_TOKEN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Board Session Lifetime
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) a merchant can keep voting and submitting after
    | the handshake, before the embedding app has to mint a fresh token.
    |
    */

    'session_ttl' => env('BOARD_SESSION_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Clock Skew Allowance
    |--------------------------------------------------------------------------
    |
    | Seconds of tolerance for a token issued by a server whose clock runs
    | slightly ahead of ours.
    |
    */

    'clock_skew' => env('BOARD_CLOCK_SKEW', 60),

    /*
    |--------------------------------------------------------------------------
    | Auto-create Installations
    |--------------------------------------------------------------------------
    |
    | When a store opens the board but has no installation record, should one
    | be created? Off by default so install counts stay webhook-authoritative;
    | the shop domain is always recorded on the request either way.
    |
    */

    'auto_create_installations' => env('BOARD_AUTO_CREATE_INSTALLATIONS', false),

];
