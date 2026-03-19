<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use DateTime;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class AdData extends BaseDTO
{
    /**
     * [
     * "app_name" => null
     * "create_time" => "2025-08-26 09:49:21"
     * "landing_page_url" => "https://medialake.ai?utm_source=tiktok&utm_medium=paid&utm_id=__CAMPAIGN_ID__&utm_campaign=__CAMPAIGN_NAME__"
     * "creative_authorized" => true
     * "impression_tracking_url" => null
     * "adgroup_name" => "Ad group 20250826103649"
     * "call_to_action_id" => "7542828902343805959"
     * "secondary_status" => "AD_STATUS_CAMPAIGN_DISABLE"
     * "video_id" => "v10033g50000d2mo5b7og65pric65cl0"
     * "campaign_automation_type" => "MANUAL"
     * "image_ids" => array:1 [
     *    0 => "tos-alisg-p-0051c001-sg/osIbQABbNBDrAXDouFdefZcTUKzDsEzgQFYWBn"
     * ]
     * "ad_text" => "Get a global view on your business assets with Medialake"
     * "campaign_id" => "1841510185405537"
     * "is_aco" => false
     * "click_tracking_url" => null
     * "deeplink_type" => "NORMAL"
     * "branded_content_disabled" => false
     * "profile_image_url" => ""
     * "ad_id" => "1841509939914850"
     * "operation_status" => "ENABLE"
     * "page_id" => null
     * "viewability_postbid_partner" => "UNSET"
     * "music_id" => null
     * "avatar_icon_web_uri" => ""
     * "playable_url" => ""
     * "identity_type" => "TT_USER"
     * "tiktok_item_id" => "7542832777163672834"
     * "utm_params" => array:4 [
     *    0 => array:2 [
     *         "key" => "utm_source"
     *         "value" => "tiktok"
     *    ]
     *    1 => array:2 [
     *         "key" => "utm_medium"
     *         "value" => "paid"
     *    ]
     *    2 => array:2 [
     *         "key" => "utm_id"
     *         "value" => "__CAMPAIGN_ID__"
     *    ]
     *    3 => array:2 [
     *         "key" => "utm_campaign"
     *         "value" => "__CAMPAIGN_NAME__"
     *    ]
     * ]
     * "landing_page_urls" => null
     * "ad_name" => "ML WebCrawler Animation Final.mp4_2025-08-26 10:47:33"
     * "carousel_image_labels" => null
     * "adgroup_id" => "1841510208658514"
     * "vast_moat_enabled" => false
     * "card_id" => null
     * "dark_post_status" => "OFF"
     * "viewability_vast_url" => null
     * "ad_texts" => null
     * "creative_type" => null
     * "deeplink" => ""
     * "display_name" => null
     * "modify_time" => "2025-08-26 10:34:14"
     * "advertiser_id" => "7541340722989907969"
     * "is_new_structure" => true
     * "ad_format" => "SINGLE_VIDEO"
     * "brand_safety_postbid_partner" => "UNSET"
     * "brand_safety_vast_url" => null
     * "campaign_name" => "Reach20250826103630"
     * "identity_id" => "33453cee-9e3e-50b3-b750-ca22de271c5b"
     * "optimization_event" => null
     * ]
     */
    public string $id;

    public string $advertiserId;

    public string $adGroupId;

    public string $campaignId;

    public ?string $identityId = null;

    public ?string $name = null;

    public ?string $status = null;

    public ?string $adFormat = null;

    public ?string $adText = null;

    public ?array $adTexts = null;

    public ?string $landingPageUrls = null;

    public ?string $videoId = null;

    public ?array $imageIds = [];

    public ?string $profileImageUrl = null;

    public ?DateTime $startDate = null;

    public ?DateTime $endDate = null;

    public ?DateTime $updatedDate = null;

    public ?DateTime $createdDate = null;

    public ?array $meta = [];
}
