<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

// Docs Resources
// Page posts: https://developers.facebook.com/docs/pages-api/posts/
// Page videos: https://developers.facebook.com/docs/graph-api/reference/page/videos/
// Page photos: https://developers.facebook.com/docs/graph-api/reference/page/photos/

// Oauth App Set-up Steps
// Register as facebook developer: https://developers.facebook.com/docs/development/register
// Create OAUTH APP: App --> OTHER, App type --> Business, Associate with business account
// Add Facebook Business Login as product. In product settings add redirect_uri and domain
// In product configurations. Create name --> Select General --> User Access Token -->
// Permissions: business_management, pages_manage_posts, pages_read_engagement, pages_show_list, pages_read_user_content
// cp client_id, client_secret and config_id

// Business Verification Steps
// While creating your Oauth app you may be prompted for business verification
// The process will ask you to confirm business address, contact details and website
// Two documents will need to be provided
// 1. Verify legal business name
// 2. Verify address or phone number

return [

    'name'         => 'facebook',
    'description'  => 'A social media platform where users can upload and share content.',
    'logo'         => 'facebook.png',
    'active'       => env('FACEBOOK_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('FACEBOOK_CLIENT_ID'),
    'client_secret' => env('FACEBOOK_SECRET'),
    'redirect_uri'  => env('FACEBOOK_REDIRECT')
        ? url(path: env('FACEBOOK_REDIRECT'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/facebook-redirect', secure: true),
    'config_id'      => env('FACEBOOK_CONFIG_ID'),
    'query_base_url' => 'https://graph.facebook.com/v18.0',
    'oauth_base_url' => 'https://www.facebook.com',
    'scope'          => 'user_videos, user_photos, business_management, pages_show_list, pages_read_engagement',
    'per_page'       => 100, // maximum
    'video_per_page' => 75,
    'rate_limit'     => env('FACEBOOK_RATE_LIMIT', 200),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('FACEBOOK_SETTINGS_REQUIRED', true),

    'posts_endpoint_accepted_formats' => [
        'photo', 'profile_media', 'cover_photo', 'album',
    ],

    'fields' => [
        'posts'  => 'id,created_time,updated_time,full_picture,permalink_url,attachments,properties',
        'videos' => 'id,created_time,updated_time,permalink_url,properties,from{access_token},source,thumbnails,length',
    ],
    'settings' => [
        'FACEBOOK_CLIENT_ID' => [
            'name'        => 'FACEBOOK_CLIENT_ID',
            'placeholder' => 'Enter your Facebook Client ID here without any space',
            'description' => 'Facebook Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'FACEBOOK_SECRET' => [
            'name'        => 'FACEBOOK_SECRET',
            'placeholder' => 'Enter your Facebook Secret here without any space',
            'description' => 'Facebook Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'FACEBOOK_CONFIG_ID' => [
            'name'        => 'FACEBOOK_CONFIG_ID',
            'placeholder' => 'Enter your Facebook Config here without any space',
            'description' => 'Facebook Config ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
