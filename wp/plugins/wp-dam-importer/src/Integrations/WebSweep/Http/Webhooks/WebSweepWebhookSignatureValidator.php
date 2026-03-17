<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Http\Webhooks;

use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookConfig;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Exceptions\InvalidWebhookSignature;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;

class WebSweepWebhookSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // 1) Determine the event type first
        $eventType = (string) $request->json('eventType');

        // 2) Read possible credentials from query or headers
        $queryToken = (string) $request->query('token', '');

        $headerCredential = $this->getHeaderCredential($request, $config);

        // 3) If neither query token nor header credentials are provided
        if (empty($queryToken) && empty($headerCredential)) {
            // For ACTOR.RUN.* events, allow unsigned webhooks (per our security model and Apify docs)
            if (in_array($eventType, WebSweepService::$observedEvents, true)) {
                logger()->info("WebSweep actor event {$eventType} accepted without credentials.", $request->toArray());

                return true;
            }

            // For other event types (e.g., STATUS_UPDATE), require credentials per docs (token in URL or headers template)
            logger()->error("WebSweep webhook {$eventType}  missing credentials and was rejected.", $request->toArray());

            return false;
        }

        $secret = (string) $config->signingSecret;

        // 4) We have some credential. A signing secret must be configured
        if (empty($secret)) {
            logger()->error('WebSweep webhook received credentials but signing secret is not configured.');

            throw InvalidWebhookSignature::make();
        }

        // 5) Accept exact token match via query (?token=SECRET) or Authorization: Bearer SECRET (or raw SECRET)
        if (filled($queryToken) && hash_equals($secret, $queryToken)) {
            return true;
        }

        // Authorization header forms
        if (filled($headerCredential)) {
            if (filled($request->bearerToken()) && hash_equals($secret, $request->bearerToken())) {
                return true;
            }

            // Raw SECRET header (e.g., Authorization: SECRET or X-Signature: SECRET)
            if (hash_equals($secret, $headerCredential)) {
                return true;
            }

            // Normalize JSON payload (unescaped slashes) before HMAC
            $reqPayload = json_encode(json_decode($request->getContent(), true), JSON_UNESCAPED_SLASHES);

            // 6) Backward compatibility: attempt HMAC verification
            $computedSignature = hash_hmac('sha256', (string) $reqPayload, $secret);

            if (hash_equals($computedSignature, $headerCredential)) {
                return true;
            }
        }

        // 7) No credential matched
        if (in_array($eventType, WebSweepService::$observedEvents, true)) {
            // Be lenient for actor lifecycle events
            logger()->info("WebSweep actor event {$eventType} accepted after credential mismatch (lenient mode).");

            return true;
        }

        logger()->error("WebSweep webhook credential verification failed for {$eventType}.", $request->toArray());

        return false;
    }

    private function getHeaderCredential(Request $request, WebhookConfig $config): ?string
    {
        // Accept multiple common header names (backward compatibility)
        $candidateHeaders = array_filter(array_unique([
            // Non-documented, but kept for backward compatibility if configured as a custom header in Apify
            'X-Apify-Signature-256',
            // Configured header name (backward compatible)
            $config->signatureHeaderName,
            // Historical fallback
            'Authorization',
        ]));

        foreach ($candidateHeaders as $header) {
            $value = $request->header($header);

            if (filled($value)) {
                if (str_contains($value, '=')) {
                    // Support potential prefixes like "sha256=" in the header value
                    return trim((string) str($value)->afterLast('='));
                }

                return $value;
            }
        }

        return $request->bearerToken();
    }
}
