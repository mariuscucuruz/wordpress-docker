<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions\InvalidAcrCloudWebhookPayload;

class AcrWebhookProcessProfile implements WebhookProfile
{
    /**
     * @throws InvalidAcrCloudWebhookPayload
     */
    public function shouldProcess(Request $request): bool
    {
        $validator = Validator::make($request->all(), [
            'cid'           => 'required|string',
            'file_id'       => 'required|string', // This is actually the File Operation remote_task_id
            'name'          => 'required|string',
            'results'       => 'sometimes|array',
            'results.music' => 'sometimes|array',
            'state'         => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new InvalidAcrCloudWebhookPayload($validator->errors()->first());
        }

        return true;
    }
}
