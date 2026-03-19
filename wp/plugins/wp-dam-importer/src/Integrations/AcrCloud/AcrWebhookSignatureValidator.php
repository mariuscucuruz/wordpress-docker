<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookConfig;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions\InvalidAcrCloudWebhookSignature;

class AcrWebhookSignatureValidator implements SignatureValidator
{
    /**
     * Validates an incoming ACR Cloud webhook using URL-based token authentication.
     *
     * ACRCloud does not natively support HMAC webhook signing. Instead, we use URL-based
     * token authentication: when registering the callback URL with ACRCloud, we append
     * a secret token as a query parameter (e.g., `?token=xxx`). When ACRCloud sends
     * webhooks back, that token is included in the URL.
     *
     * This method:
     *  1. Rejects any request containing an `Authorization` header (for safety).
     *  2. Extracts the token from the URL query parameter.
     *  3. Compares it against the configured signing secret using timing-safe comparison.
     *  4. Throws `InvalidAcrCloudWebhookSignature` if the token is missing or invalid.
     *
     * @throws InvalidAcrCloudWebhookSignature
     */
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // Reject any Authorization header outright
        if (filled($request->header('Authorization'))) {
            throw new InvalidAcrCloudWebhookSignature;
        }

        $expectedToken = (string) $config->signingSecret;

        // If no signing secret is configured, reject all requests
        if (empty($expectedToken)) {
            throw new InvalidAcrCloudWebhookSignature;
        }

        $providedToken = (string) $request->query('token', '');

        if (empty($providedToken)) {
            throw new InvalidAcrCloudWebhookSignature;
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            throw new InvalidAcrCloudWebhookSignature;
        }

        return true;
    }
}
