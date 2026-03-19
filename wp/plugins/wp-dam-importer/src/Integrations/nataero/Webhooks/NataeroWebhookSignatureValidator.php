<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookConfig;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Exceptions\InvalidWebhookSignature;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;

class NataeroWebhookSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $signature = $request->header($config->signatureHeaderName);

        if (empty($config->signingSecret)) {
            throw InvalidWebhookSignature::make();
        }

        $raw = $request->getContent();
        $computedSignature = hash_hmac('sha256', $raw, $config->signingSecret);

        return hash_equals($computedSignature, (string) $signature);
    }
}
