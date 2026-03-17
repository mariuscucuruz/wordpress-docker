<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'LinkedIn',
    'description'  => 'A professional networking platform.',
    'logo'         => 'linkedin.svg',
    'instructions' => 'docs/readme.md',
    'type'         => PackageTypes::SOURCE->value,
    'active'       => env('LINKEDIN_ACTIVE', true),

    'api_version'  => '202510',
    'api_url'      => 'https://api.linkedin.com',
    'auth_url'     => 'https://www.linkedin.com/oauth/v2',
    'redirect_uri' => url(
        path: env('LINKEDIN_REDIRECT', '/linkedin-redirect'),
        secure: true),

    'client_id'     => env('LINKEDIN_CLIENT_ID'),
    'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    'client_scopes' => env('LINKEDIN_CLIENT_SCOPES')
        ? explode(',', env('LINKEDIN_CLIENT_SCOPES'))
        /**
         * The permissions required for rest/images access are:
         * rw_ads, w_member_social, w_organization_social, and w_power_creators.
         * However, w_member_social permission are write-only and tokens with only w_member_social permissions would be unable to perform a GET call for rest/images.
         * https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access?view=li-lms-2025-10#what-permissions-are-available
         * */
        : [
            'openid',
            'profile',
            'email',
            'r_basicprofile',
            'r_liteprofile',
            // 'r_full_profile' // closed permission, and we're not accepting access requests currently
            // 'r_compliance',  // required to retrieve posts, and activity for compliance monitoring and archiving. This is a private permission and access is granted to select developers. Not yet available to the app

            /**
             * 'r_member_social' is a closed permission, and we're not accepting access requests at this time due to resource constraints.
             * only 'w_member_social' permissions are unable to GET rest/images
             * https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/social-metadata-api?view=li-lms-2025-02&tabs=http#permissions
             */
            // 'r_member_social',

            /**
             * 'r_organization_social' is restricted to orgs where auth'd member has role: ADMINISTRATOR, or DIRECT_SPONSORED_CONTENT_POSTER
             * https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/social-metadata-api?view=li-lms-2025-02&tabs=http#permissions
             */
            'r_organization_social',    // required for admin pages
            'r_organization_admin',     // required for admin pages

            'r_events',                 // Needs "Events API" product
            'r_ads',                    // Needs "Marketing Developer Platform" product
            'r_ads_reporting',          // Needs "Marketing Developer Platform" product
            'rw_ads',
            // 'w_power_creators',
            'r_1st_connections_size',
            'r_marketing_leadgen_automation',
            'r_ads_leadgen_automation',
        ],

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('LINKEDIN_SETTINGS_REQUIRED', true),

    'settings' => [
        'LINKEDIN_CLIENT_ID' => [
            'name'        => 'LINKEDIN_CLIENT_ID',
            'description' => 'Enter your LinkedIn Client ID without any spaces',
            'placeholder' => 'Linkedin Client ID',
            'rules'       => 'required|min:5',
            'type'        => 'text',
        ],
        'LINKEDIN_CLIENT_SECRET' => [
            'name'        => 'LINKEDIN_CLIENT_SECRET',
            'description' => 'Enter your LinkedIn Client Secret without any spaces',
            'placeholder' => 'Linkedin Client Secret',
            'type'        => 'text',
            'rules'       => 'required|min:5',
        ],
    ],
];
