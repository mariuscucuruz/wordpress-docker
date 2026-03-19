<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks;

use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

final readonly class WebhookContext
{
    public function __construct(
        public string $taskId,
        public string $fileId,
        public NataeroTaskStatus $status,
        public array $results,
        public NataeroTask $task,
        public NataeroFunctionType $op,
        public ?array $payload = null,
    ) {}
}
