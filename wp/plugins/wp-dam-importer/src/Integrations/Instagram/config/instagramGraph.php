<?php

declare(strict_types=1);

return [
    'client_id'     => env('INSTAGRAM_GRAPH_CLIENT_ID'),
    'client_secret' => env('INSTAGRAM_GRAPH_SECRET'),
    'redirect_uri'  => env('INSTAGRAM_GRAPH_REDIRECT')
        ? url(path: env('INSTAGRAM_GRAPH_REDIRECT'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/instagram-redirect', secure: true),

    'config_id' => env('INSTAGRAM_GRAPH_CONFIG_ID'),

    'query_base_url' => 'https://graph.facebook.com/v18.0',
    'oauth_base_url' => 'https://www.facebook.com',

    'fields' => [
        'user'  => 'id,name,picture.width(720).height(720).as(picture)',
        'media' => 'caption,comments_count,id,like_count,media_type,media_url,permalink,thumbnail_url,timestamp,children{media_type,media_url,thumbnail_url}',
    ],

    'per_page' => 100,
];
