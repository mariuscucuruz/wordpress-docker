<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use DateTime;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class AdGroupData extends BaseDTO
{
    /**
     * [
     * "category_exclusion_ids" => []
     * "creative_material_mode" => "CUSTOM"
     * "frequency" => 3
     * "delivery_mode" => null
     * "app_id" => null
     * "bid_price" => 2.75
     * "placement_type" => "PLACEMENT_TYPE_NORMAL"
     * "category_id" => "0"
     * "brand_safety_type" => "STANDARD_INVENTORY"
     * "household_income" => []
     * "deep_cpa_bid" => 0.0
     * "scheduled_budget" => 0.0
     * "spending_power" => "ALL"
     * "device_model_ids" => []
     * "tiktok_subplacements" => array:4 [
     *      0 => "IN_FEED"
     *      1 => "SEARCH_FEED"
     *      2 => null
     *      3 => null
     * ]
     * "is_smart_performance_campaign" => false
     * "vbo_window" => null
     * "conversion_bid_price" => 0.0
     * "contextual_tag_ids" => []
     * "location_ids" => array:1 [
     *      0 => "2635167"
     * ]
     * "secondary_status" => "ADGROUP_STATUS_CAMPAIGN_DISABLE"
     * "campaign_id" => "1841510185405537"
     * "comment_disabled" => false
     * "deep_funnel_event_source" => null
     * "frequency_schedule" => 7
     * "gender" => "GENDER_UNLIMITED"
     * "search_result_enabled" => false
     * "app_type" => null
     * "excluded_audience_ids" => []
     * "operating_systems" => []
     * "interest_category_ids" => array:4 [
     *      0 => "24112"
     *      1 => "24111"
     *      2 => "24116"
     *      3 => "24113"
     * ]
     * "schedule_start_time" => "2025-08-26 10:36:49"
     * "app_config" => null
     * "rf_estimated_cpr" => null
     * "isp_ids" => []
     * "age_groups" => array:4 [
     *      0 => "AGE_25_34"
     *      1 => "AGE_35_44"
     *      2 => "AGE_45_54"
     *      3 => "AGE_55_100"
     * ]
     * "deep_funnel_optimization_status" => null
     * "excluded_custom_actions" => []
     * "languages" => []
     * "placements" => array:1 [
     *      0 => "PLACEMENT_TIKTOK"
     * ]
     * "next_day_retention" => null
     * "optimization_goal" => "REACH"
     * "operation_status" => "ENABLE"
     * "conversion_window" => null
     * "device_price_ranges" => []
     * "pacing" => "PACING_MODE_SMOOTH"
     * "brand_safety_partner" => null
     * "pixel_id" => null
     * "bid_display_mode" => "CPMV"
     * "schedule_type" => "SCHEDULE_START_END"
     * "rf_estimated_frequency" => null
     * "budget_mode" => "BUDGET_MODE_INFINITE"
     * "inventory_filter_enabled" => false
     * "campaign_automation_type" => "MANUAL"
     * "share_disabled" => false
     * "is_new_structure" => true
     * "dayparting" => "11111...1111"
     * "audience_ids" => []
     * "campaign_name" => "Reach20250826103630"
     * "optimization_event" => null
     * "deep_funnel_event_source_id" => null
     * "bid_type" => "BID_TYPE_CUSTOM"
     * "billing_event" => "CPM"
     * "deep_bid_type" => null
     * "adgroup_id" => "1841510208658514"
     * "vertical_sensitivity_id" => "0"
     * "ios14_quota_type" => "UNOCCUPIED"
     * "modify_time" => "2025-08-26 09:55:50"
     * "rf_purchased_type" => null
     * "included_custom_actions" => []
     * "app_download_url" => null
     * "feed_type" => null
     * "is_hfss" => false
     * "automated_keywords_enabled" => null
     * "interest_keyword_ids" => []
     * "smart_interest_behavior_enabled" => null
     * "purchased_reach" => null
     * "budget" => 0.0
     * "create_time" => "2025-08-26 09:49:21"
     * "keywords" => null
     * "deep_funnel_optimization_event" => null
     * "smart_audience_enabled" => null
     * "schedule_end_time" => "2025-09-09 10:36:49"
     * "schedule_infos" => null
     * "adgroup_app_profile_page_state" => null
     * "statistic_type" => null
     * "secondary_optimization_event" => null
     * "promotion_type" => "WEBSITE_OR_DISPLAY"
     * "advertiser_id" => "7541340722989907969"
     * "purchased_impression" => null
     * "video_download_disabled" => false
     * "actions" => []
     * "adgroup_name" => "Ad group 20250826103649"
     * "network_types" => []
     * "skip_learning_phase" => false
     * ]
     **/
    public ?string $id;

    public string $advertiserId;

    public string $campaignId;

    public ?string $status = null;

    public ?string $name = null;

    public ?string $keywords = null;

    public ?array $ageGroups = [];

    public ?array $placements = [];

    public ?string $budgetMode = null;

    public ?DateTime $startDate = null;

    public ?DateTime $endDate = null;

    public ?DateTime $createdDate = null;

    public ?array $meta = [];
}
