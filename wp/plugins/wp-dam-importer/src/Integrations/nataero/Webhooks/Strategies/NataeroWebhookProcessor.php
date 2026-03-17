<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use Spatie\WebhookClient\Models\WebhookCall;
use MariusCucuruz\DAMImporter\Exceptions\InvalidNataeroWebhookPayload;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\WebhookContext;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Contracts\NataeroOperationStrategy;

final class NataeroWebhookProcessor
{
    use Loggable;

    public function parse(WebhookCall $call, NataeroOperationStrategy $strategy): WebhookContext
    {
        $taskId = (string) data_get($call->payload, 'task_id');
        $fileId = (string) data_get($call->payload, 'remote_file_id');
        $status = NataeroTaskStatus::resolveFromString((string) data_get($call->payload, 'status', ''));

        $task = NataeroTask::with('file')
            ->where('remote_nataero_task_id', $taskId)
            ->first();

        if (! $task) {
            throw InvalidNataeroWebhookPayload::make('Nataero Task not found for Webhook payload.');
        }

        $payloadData = data_get($call->payload, $strategy->payloadKey());
        $results = is_array($payloadData) ? $payloadData : [$payloadData];

        if ($results === []) {
            throw InvalidNataeroWebhookPayload::make();
        }

        return new WebhookContext(
            taskId: $taskId,
            fileId: $fileId,
            status: $status,
            results: $results,
            task: $task,
            op: $strategy->fileOperation(),
            payload: $call->payload
        );
    }

    public function fail(WebhookContext $ctx): void
    {
        $exceptionMessage = data_get($ctx->payload, 'exception_message') ?? 'Unknown error occurred during Nataero processing.';

        $ctx->task->update([
            'status'    => NataeroTaskStatus::FAILED->value,
            'exception' => $exceptionMessage,
        ]);

        /** @var File $file */
        $file = $ctx->task->file;

        $file->markFailure(
            FileOperationName::tryFrom(strtolower($ctx->op->value)),
            "Nataero {$ctx->op->value} failed",
            $exceptionMessage,
            $ctx->results
        );

        $this->log("Nataero {$ctx->op->value} failed for File ID: {$file->id}", 'error', context: [
            'task_id' => $ctx->taskId,
            'status'  => $ctx->status->value,
        ]);
    }
}
