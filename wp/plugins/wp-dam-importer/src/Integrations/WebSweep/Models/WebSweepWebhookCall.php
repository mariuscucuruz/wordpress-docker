<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Models;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookConfig;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepEventType;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepRunStatus;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs\PollApifyDatasetJob;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Exceptions\InvalidWebhookPayload;
use Spatie\WebhookClient\Models\WebhookCall as SpatieWebhookCall;

class WebSweepWebhookCall extends SpatieWebhookCall
{
    protected $table = 'webhook_calls';

    /**
     * @throws InvalidWebhookPayload
     */
    public static function storeWebhook(WebhookConfig $config, Request $request): SpatieWebhookCall
    {
        $payload = $request->all();
        $headers = self::headersToStore($config, $request);
        $webhook = parent::storeWebhook($config, $request);
        $apifyRun = WebSweepService::findApifyRun($payload);
        $datasetId = (string) data_get($payload, 'datasetId');
        $queueId = (string) data_get($payload, 'queueId');

        // all callbacks must have an eventType
        $eventType = (string) data_get($payload, 'eventType');
        $validEvent = WebSweepEventType::tryFrom($eventType);

        $eventStatus = (string) (data_get($payload, 'resource.status') // lifecycle updates
            ?? data_get($payload, 'status')); // crawler updates
        $validStatus = WebSweepRunStatus::tryFrom($eventStatus);

        if (empty($validEvent)) {
            throw InvalidWebhookPayload::make("Unrecognized Event Type: {$eventType}");
        }

        if (empty($validStatus)) {
            throw InvalidWebhookPayload::make("Unrecognized status: {$eventStatus}");
        }

        if (! $apifyRun) {
            // For actor lifecycle events we don't control, don't error if no run is found; just acknowledge.
            if (in_array($eventType, WebSweepService::$observedEvents ?? [], true)) {
                logger()->info('WebSweep actor event received but no matching Run found; ignoring.', compact('eventType', 'payload', 'headers'));

                return $webhook; // 200 OK default response
            }

            logger()->error('WebSweep webhook cannot find Run.', compact('eventType', 'payload', 'headers'));

            throw InvalidWebhookPayload::make('Cannot find Run');
        }

        if ($validEvent === WebSweepEventType::STATUS_UPDATE) {
            if ($apifyRun->last_check_at?->gte(now()->subSeconds(15))) {
                // process status-update every 15 seconds, at most
                return $webhook;
            }

            if (empty($datasetId)) {
                throw InvalidWebhookPayload::make('WebSweep webhook payload missing datasetId');
            }
        }

        if (filled($datasetId) || filled($queueId)) {
            $apifyRun->update(array_filter([
                'dataset_id'       => $datasetId,
                'request_queue_id' => $queueId,
            ]));
        }

        dispatch(new PollApifyDatasetJob($apifyRun->id, WebSweepRunStatus::runningCases()->contains($validStatus)))
            ->afterResponse();

        logger()?->debug('WebSweep call processed successfully.', $apifyRun->toArray());

        return $webhook;
    }
}
