<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class CampaignData extends BaseDTO
{
    /**
     * [
     * "is_new_structure" => true
     * "is_advanced_dedicated_campaign" => false
     * "campaign_type" => "REGULAR_CAMPAIGN"
     * "objective" => "LANDING_PAGE"
     * "campaign_automation_type" => "MANUAL"
     * "roas_bid" => 0.0
     * "campaign_name" => "Reach20250826103630"
     * "is_search_campaign" => false
     * "campaign_id" => "1841510185405537"
     * "budget" => 50.0
     * "modify_time" => "2025-08-26 16:37:38"
     * "create_time" => "2025-08-26 09:49:21"
     * "budget_mode" => "BUDGET_MODE_TOTAL"
     * "deep_bid_type" => null
     * "advertiser_id" => "7541340722989907969"
     * "rta_id" => null
     * "rta_bid_enabled" => false
     * "operation_status" => "DISABLE"
     * "objective_type" => "REACH"
     * "app_promotion_type" => "UNSET"
     * "sales_destination" => null
     * "secondary_status" => "CAMPAIGN_STATUS_DISABLE"
     * "disable_skan_campaign" => null
     * "is_smart_performance_campaign" => false
     * "virtual_objective_type" => null
     * "budget_optimize_on" => true
     * "rta_product_selection_enabled" => false
     * ]
     */
    public ?string $id = null;

    public ?string $advertiserId = null;

    public ?string $name = null;

    public ?string $status = null;

    public ?array $meta = [];
}
