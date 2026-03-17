<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Jobs;

use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroResultProcessor;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies\Hyper1Strategy;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies\NataeroWebhookProcessor;

class ProcessNataeroHyper1WebhookJob extends ProcessWebhookJob
{
    public function __construct(public WebhookCall $webhookCall)
    {
        parent::__construct($webhookCall);
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(
        NataeroWebhookProcessor $processor,
        Hyper1Strategy $strategy,
    ): void {
        $ctx = $processor->parse($this->webhookCall, $strategy);

        if ($ctx->status !== NataeroTaskStatus::SUCCEEDED) {
            $processor->fail($ctx);

            return;
        }

        NataeroResultProcessor::processHyper1Results($ctx->results, $ctx->task);
    }
}
