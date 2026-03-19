<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasOperationStates
{
    public function operationStates(): HasMany
    {
        return $this->hasMany(FileOperationState::class);
    }

    public function getOperationStatus(FileOperationName $operation): ?FileOperationStatus
    {
        return $this->operationStates()
            ->where('operation_name', $operation)
            ->latest()
            ->value('status');
    }

    public function hasOperation(FileOperationName $operation): bool
    {
        return $this->operationStates()
            ->where('operation_name', $operation)
            ->exists();
    }

    public function hasOperationState(FileOperationName $operation, FileOperationStatus $status): bool
    {
        return $this->operationStates()
            ->where('operation_name', $operation)
            ->where('status', $status)
            ->exists();
    }

    public function currentOperationStatus(FileOperationName $operation): ?FileOperationStatus
    {
        return $this->operationStates()
            ->where('operation_name', $operation)
            ->latest()
            ->value('status');
    }

    public function isOperationInitiated(FileOperationName $name): bool
    {
        return $this->hasOperationState($name, FileOperationStatus::INITIALIZED);
    }

    public function isOperationProcessing(FileOperationName $name): bool
    {
        return $this->hasOperationState($name, FileOperationStatus::PROCESSING);
    }

    public function hasSuccessfulOperation(FileOperationName $name): bool
    {
        return $this->hasOperationState($name, FileOperationStatus::SUCCESS);
    }

    public function hasFailedOperation(FileOperationName $name): bool
    {
        return $this->hasOperationState($name, FileOperationStatus::FAILED);
    }

    public function markOperation(
        FileOperationName $operation,
        FileOperationStatus $status,
        ?string $message = null,
        array $data = [],
        ?string $exception = null,
    ): FileOperationState {
        $state = $this->operationStates()->firstOrNew(['operation_name' => $operation]);
        $state->media_type = $data['media_type'] ?? $this->type;

        return $state->updateStatus($status, $message, $exception, $data);
    }

    public function markInitialized(
        FileOperationName $operation,
        ?string $message = null,
        array $data = []
    ): FileOperationState {
        return $this->markOperation(
            $operation,
            FileOperationStatus::INITIALIZED,
            $message,
            $data
        );
    }

    public function markProcessing(
        FileOperationName $operation,
        ?string $message = null,
        array $data = []
    ): FileOperationState {
        return $this->markOperation(
            $operation,
            FileOperationStatus::PROCESSING,
            $message,
            $data
        );
    }

    public function markSuccess(
        FileOperationName $operation,
        ?string $message = null,
        array $data = []
    ): FileOperationState {
        return $this->markOperation(
            $operation,
            FileOperationStatus::SUCCESS,
            $message,
            $data
        );
    }

    public function markFailure(
        FileOperationName $operation,
        ?string $message = null,
        ?string $exception = null,
        array $data = [],
    ): FileOperationState {
        return $this->markOperation(
            $operation,
            FileOperationStatus::FAILED,
            $message,
            $data,
            $exception,
        );
    }
}
