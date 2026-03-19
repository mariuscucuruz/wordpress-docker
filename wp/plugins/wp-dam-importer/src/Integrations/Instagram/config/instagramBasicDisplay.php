<?php

declare(strict_types=1);

return [
    'per_page' => 100,

    'client_id'      => env('INSTAGRAM_CLIENT_ID'),
    'client_secret'  => env('INSTAGRAM_SECRET'),
    'redirect_uri'   => env('INSTAGRAM_REDIRECT') ? url(path: env('INSTAGRAM_REDIRECT'), secure: true) : null,
    'query_base_url' => 'https://graph.instagram.com',
    'oauth_base_url' => 'https://api.instagram.com',
    'scope'          => 'user_profile,user_media',

    'fields' => [
        'user'  => 'id,username',
        'media' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{media_url,thumbnail_url, media_type}',
    ],
];
