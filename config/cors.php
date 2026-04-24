<?php

/*
 | -*-------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.3.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

return [

    /*
     *  |--------------------------------------------------------------------------
     *  | Cross-Origin Resource Sharing (CORS) Configuration
     *  |--------------------------------------------------------------------------
     *  |
     *  | Here you may configure your settings for cross-origin resource sharing
     *  | or "CORS". This determines what cross-origin operations may execute
     *  | in web browsers.
     *  |
     */

    'paths' => [
        'api/*',
'sanctum/csrf-cookie',
    ],

'allowed_methods' => ['*'],

'allowed_origins' => [
    env('FRONTEND_URL', 'http://localhost:3000'),
    env('FRONTEND_URL_ALT', 'http://127.0.0.1:3000'),
],

'allowed_origins_patterns' => [],

'allowed_headers' => ['*'],

'exposed_headers' => [],

'max_age' => 3600,

'supports_credentials' => true,

];
