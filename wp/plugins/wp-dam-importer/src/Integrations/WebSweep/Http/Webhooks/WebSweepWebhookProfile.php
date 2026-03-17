<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Http\Webhooks;

use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Support\Arr;
use MariusCucuruz\DAMImporter\Enums\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Contracts\Queue\ShouldQueue;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;

class WebSweepWebhookProfile implements ShouldQueue, WebhookProfile
{
    public function shouldProcess(Request $request): bool
    {
        $requestPayload = $request->json()->all();

        $eventType = data_get($requestPayload, 'eventType');
        $startUrl = data_get($requestPayload, 'startUrl');
        $serviceId = data_get($requestPayload, 'serviceId');
        $datasetId = data_get($requestPayload, 'datasetId');

        if (empty($eventType)) {
            logger()->error('WebSweep request without event type received.', $request->toArray());

            return false;
        }

        if ($eventType !== ActivityEvent::STATUS_UPDATE->value && ! in_array($eventType, WebSweepService::$observedEvents)) {
            logger()->error("WebSweep request received invalid event type: {$eventType}.", $request->toArray());

            return false;
        }

        if ($eventType === ActivityEvent::STATUS_UPDATE->value
            && Arr::first([$serviceId, $startUrl, $datasetId], fn (string $required) => empty($required))
        ) {
            logger()?->error('WebSweep status-update missing required fields.', $request->toArray());

            return false;
        }

        if (filled($serviceId) && empty(Service::find($serviceId))) {
            logger()?->error('WebSweep callback for invalid service.', $request->toArray());

            return false;
        }

        return true;
    }
}
