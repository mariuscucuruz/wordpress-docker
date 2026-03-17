<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Jobs;

use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroResultProcessor;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies\MediainfoStrategy;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies\NataeroWebhookProcessor;

class ProcessNataeroMediainfoWebhookJob extends ProcessWebhookJob
{
    public function __construct(public WebhookCall $webhookCall)
    {
        parent::__construct($webhookCall);
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(
        NataeroWebhookProcessor $processor,
        MediainfoStrategy $strategy,
    ): void {
        $ctx = $processor->parse($this->webhookCall, $strategy);

        if ($ctx->status !== NataeroTaskStatus::SUCCEEDED) {
            $processor->fail($ctx);

            return;
        }
        NataeroResultProcessor::processMediainfoResults($ctx->results, $ctx->task);
    }
}
