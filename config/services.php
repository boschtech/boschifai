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

    // Outgoing email (MAIL_MAILER=ses). Leave the key and secret empty on AWS: the SDK then uses
    // the ECS task role (or instance profile), so no long-lived key sits in the environment. Set
    // them only where there is no role, e.g. local development. SES_REGION is where the sending
    // identity (domain or address) is verified — it can differ from the rest of the AWS setup.
    'ses' => [
        'key' => env('SES_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('SES_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
        'region' => env('SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
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

];
