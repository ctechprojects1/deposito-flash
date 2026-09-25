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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integração ERP Linx Microvix (WebAPI)
    |--------------------------------------------------------------------------
    */
    'microvix' => [
        'url'       => env('MICROVIX_SAIDA_URL'),
        'user'      => env('MICROVIX_SAIDA_USER'),
        'pass'      => env('MICROVIX_SAIDA_PASS'),
        'chave'     => env('MICROVIX_SAIDA_CHAVE'),
        'cnpj'      => env('MICROVIX_CNPJ'),
        'id_portal' => env('MICROVIX_ID_PORTAL'),
        'timeout'   => (int) env('MICROVIX_API_TIMEOUT', 15),
    ],

    /*
    | Leitura de notas/pedidos em PDF (Claude). Mesma chave usada no PRODID.
    */
    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

];
