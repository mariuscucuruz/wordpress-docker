<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use RuntimeException;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Integrations\Nataero\DTO\PayloadDTO;
use GuzzleHttp\Promise\PromiseInterface;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\DTO\OperationParams;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroResultProcessor;

class Nataero
{
    use Loggable;

    private ?string $callbackUrl;

    public function getNataeroFileResults(NataeroTask $task, NataeroFunctionType $nataeroFunctionType): bool
    {
        $url = config('nataero.query_base_url')
            . '/services/' . strtolower($nataeroFunctionType->value) . '/task/'
            . $task->remote_nataero_task_id
            . '/';

        $response = $this->requestResult($url);

        if ($response->notFound()) {
            $task->update(['status' => NataeroTaskStatus::PROCESSING->value]);

            return true;
        }

        if (! $response->ok()) {
            $task->update(['status' => NataeroTaskStatus::FAILED->value]);
            $body = Str::limit($response->body(), 1000);
            $this->log(
                "Failed to get Nataero result for Task {$task->id} (File {$task->file_id}): " . $response->status() . ' - ' . $body,
                'error'
            );

            return false;
        }

        $body = $response->json();

        $status = match (data_get($body, 'status')) {
            'SUCCESS' | 'success' => NataeroTaskStatus::SUCCEEDED->value,
            'FAILURE' | 'failed'  => NataeroTaskStatus::FAILED->value,
            default               => NataeroTaskStatus::PROCESSING->value,
        };

        if ($status === NataeroTaskStatus::SUCCEEDED->value && $results = data_get($body, 'result')) {
            if (is_array($results)) {
                $decodedResults = $results;
            } elseif (json_validate($results)) {
                $decodedResults = json_decode($results, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $decodedResults = [$results];
            }

            match ($nataeroFunctionType) {
                NataeroFunctionType::MEDIAINFO => NataeroResultProcessor::processMediainfoResults($decodedResults, $task),
                NataeroFunctionType::EXIF      => NataeroResultProcessor::processMediaExifResults($decodedResults, $task),
                NataeroFunctionType::CONVERT   => NataeroResultProcessor::processConvertResults($decodedResults, $task),
                NataeroFunctionType::SNEAKPEEK => NataeroResultProcessor::processMediaSneakpeekResults($decodedResults, $task),
                NataeroFunctionType::HYPER1    => NataeroResultProcessor::processHyper1Results($decodedResults, $task),
                default                        => throw new RuntimeException("Unsupported file operation: {$nataeroFunctionType->value}"),
            };

            return true;
        }

        if ($status === NataeroTaskStatus::FAILED->value) {
            $exceptionMessage = 'Nataero task failed: ' . (data_get($body, 'exception_message') ?: data_get($body, 'result'));

            $task->update([
                'status'    => NataeroTaskStatus::FAILED->value,
                'exception' => $exceptionMessage,
            ]);

            $task->file->markFailure(
                FileOperationName::tryFrom(strtolower($nataeroFunctionType->value)),
                $exceptionMessage,
            );

            return false;
        }

        return true;
    }

    private function requestResult(string $url): PromiseInterface|Response
    {
        return Http::timeout(config('queue.timeout'))->withHeaders([
            'Authorization' => 'Token ' . config('nataero.token'),
        ])->get($url);
    }

    public function processOperation(File $file, OperationParams $operationParams): bool
    {
        if (! $file->download_url) {
            $this->log("File has no download URL: {$file->id}", 'error');

            return false;
        }

        $status = NataeroTaskStatus::PROCESSING->value;

        try {
            match ($functionType = $operationParams->nataeroFunctionType) {
                NataeroFunctionType::MEDIAINFO => $this->sendRequest($file, PayloadDTO::mediainfoPayload($file), $functionType),
                NataeroFunctionType::EXIF      => $this->sendRequest($file, PayloadDTO::mediaExifPayload($file), $functionType),
                NataeroFunctionType::CONVERT   => $this->sendRequest($file, PayloadDTO::convertPayload($file, 8, $operationParams->type), $functionType),
                NataeroFunctionType::SNEAKPEEK => $this->sendRequest($file, PayloadDTO::sneakpeekPayload($file), $functionType),
                NataeroFunctionType::HYPER1    => $this->sendRequest($file, PayloadDTO::hyper1Payload($file), $functionType),
                default                        => throw new RuntimeException("Unsupported file operation: {$functionType->value}"),
            };
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $status = NataeroTaskStatus::FAILED->value;
            $file->markFailure(
                FileOperationName::tryFrom(strtolower($functionType->value)),
                'Exception occurred while processing Nataero operation: ' . $e->getMessage(),
            );
        }

        $file->nataeroTasks()
            ->where('function_type', $functionType->value)
            ->update(['status' => $status]);

        return $status === NataeroTaskStatus::PROCESSING->value;
    }

    public function sendRequest(File $file, PayloadDTO $payload, NataeroFunctionType $functionType): bool
    {
        $response = Http::timeout(config('queue.timeout'))
            ->withHeaders([
                'Authorization'            => 'Token ' . config('nataero.token'),
                'Accept'                   => 'application/json',
                'X-Webhook-Signing-Secret' => $payload->webhook_signing_secret,
            ])
            ->post($payload->serviceUrl(), $payload->toArray());

        $body = $response->json();

        if (! $response->successful()) {
            $body = Str::limit($response->body(), 1000);
            $this->log(
                "Unable to send {$functionType->value} request to Nataero for file ID: {$file->id}. "
                . "Code: {$response->status()}. Body: {$body}",
                'error'
            );

            $file->markFailure(
                FileOperationName::tryFrom(strtolower($functionType->value)),
                "{$response->status()} - Failed to enqueue {$functionType->value} task to Nataero",
                $response->body()
            );

            throw new RuntimeException("Failed to send request to Nataero: {$response->status()} - {$body}");
        }

        $file->nataeroTasks()
            ->where('function_type', $functionType->value)
            ->update([
                'status'                 => NataeroTaskStatus::PROCESSING->value,
                'remote_nataero_task_id' => data_get($body, 'task_id'),
            ]);

        $file->markProcessing(
            FileOperationName::tryFrom(strtolower($functionType->value)),
            "{$functionType->value} request successfully enqueued to Nataero",
            ['remote_task_id' => data_get($body, 'task_id')]
        );

        $this->log(
            "{$functionType->value} request successfully enqueued to Nataero for file ID: {$file->id}. "
            . "Code: {$response->status()}. Body: {$response->body()}",
        );

        return true;
    }
}
