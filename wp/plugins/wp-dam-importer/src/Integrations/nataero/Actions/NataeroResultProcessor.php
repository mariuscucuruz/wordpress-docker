<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Actions;

use Exif;
use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use RuntimeException;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Integrations\Mediainfo\Mediainfo;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Sneakpeek;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1ImageEmbedding;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1VideoEmbedding;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1AverageVideoEmbedding;

class NataeroResultProcessor
{
    public static function processMediaSneakpeekResults(array $results, NataeroTask $task): bool
    {
        /** @var File $file */
        $file = $task->file;

        if (! $file) {
            throw new RuntimeException("No file on task {$task->id}");
        }

        $sneakpeek = app(Sneakpeek::class);

        try {
            $sneakpeek->saveSneakpeekData($results, $file);
            $task->update(['status' => NataeroTaskStatus::SUCCEEDED->value]);

            $file->markSuccess(
                FileOperationName::SNEAKPEEK,
                'Nataero sneakpeek completed successfully',
                ['remote_task_id' => $task->remote_nataero_task_id]
            );

            $file->searchable();

            return true;
        } catch (Exception $e) {
            $task->update(['status' => NataeroTaskStatus::FAILED->value]);

            $file->markFailure(
                FileOperationName::SNEAKPEEK,
                'Nataero sneakpeek failed during result processing',
                $e->getMessage()
            );

            return false;
        }
    }

    public static function processMediaExifResults(array $results, NataeroTask $task): bool
    {
        $file = $task->file;

        if (! $file) {
            throw new RuntimeException("No file on task {$task->id}");
        }

        try {
            Exif::saveExifData($results, $file);
            $task->update(['status' => NataeroTaskStatus::SUCCEEDED->value]);

            $file->markSuccess(
                FileOperationName::EXIF,
                'Nataero exif completed successfully',
                ['remote_task_id' => $task->remote_nataero_task_id]
            );

            return true;
        } catch (Exception $e) {
            $task->update(['status' => NataeroTaskStatus::FAILED->value]);

            $file->markFailure(
                FileOperationName::EXIF,
                'Nataero exif failed during result processing',
                $e->getMessage()
            );

            return false;
        }
    }

    public static function processMediainfoResults(array $results, NataeroTask $task): bool
    {
        $file = $task->file;

        if (! $file) {
            throw new RuntimeException("No file on task {$task->id}");
        }

        try {
            Mediainfo::saveMediainfoOutput($results, $file);
            $task->update(['status' => NataeroTaskStatus::SUCCEEDED->value]);

            $file->markSuccess(
                FileOperationName::MEDIAINFO,
                'Nataero mediainfo completed successfully',
                ['remote_task_id' => $task->remote_nataero_task_id]
            );

            return true;
        } catch (Exception $e) {
            $task->update(['status' => NataeroTaskStatus::FAILED->value]);

            $file->markFailure(
                FileOperationName::MEDIAINFO,
                'Nataero mediainfo failed during result processing',
                $e->getMessage()
            );

            return false;
        }
    }

    public static function processConvertResults(array $results, NataeroTask $task): bool
    {
        $file = $task->file;

        if (! $file) {
            throw new RuntimeException("No file on task {$task->id}");
        }

        try {
            usort($results, function ($a, $b) {
                return (($a['name'] ?? '') === 'pdf') <=> (($b['name'] ?? '') === 'pdf');
            });

            foreach ($results as $output) {
                if (! isset($output['name'])) {
                    continue;
                }

                match ($output['name']) {
                    'view_url'  => $file->update(['view_url' => ltrim($output['storage_key'], '/')]),
                    'thumbnail' => $file->update(['thumbnail' => ltrim($output['storage_key'], '/')]),
                    'pdf'       => self::processPdfResults($file, $output),
                    default     => null,
                };
            }

            $task->update(['status' => NataeroTaskStatus::SUCCEEDED->value]);

            $file->markSuccess(
                FileOperationName::CONVERT,
                'Nataero conversion completed successfully',
                ['remote_task_id' => $task->remote_nataero_task_id]
            );

            $file->searchable();

            return true;
        } catch (Exception $e) {
            $task->update(['status' => NataeroTaskStatus::FAILED->value]);

            $file->markFailure(
                FileOperationName::CONVERT,
                'Nataero conversion failed during result processing',
                $e->getMessage()
            );

            return false;
        }
    }

    public static function processPdfResults(File $file, array $results): bool
    {
        $outputExtension = data_get(config('manager.meta.document_conversion_extensions'), 'pdf');
        $pages = data_get($results, 'pages', []);

        foreach ($pages as $result) {
            $fileName = ltrim((string) ($result['filename'] ?? $result['name'] ?? ''));
            $storageKey = ltrim((string) ($result['storage_key'] ?? ''), '/');

            if ($fileName === '' || $storageKey === '') {
                continue;
            }

            if (StorageService::exists($storageKey) === false) {
                logger()->error("Storage key not found: {$storageKey} for PDF page in file ID: {$file->id}");

                continue;
            }

            $fileSize = $result['bytes'] ?? StorageService::size($storageKey);
            $md5 = $result['md5'] ?? null;
            $sha256 = $result['sha256'] ?? null;

            $pageNumber = (int) data_get($result, 'page', 0) ?: null;
            $name = $pageNumber ? "{$file->name}-{$pageNumber}" : pathinfo($fileName, PATHINFO_FILENAME);
            $slug = $pageNumber ? "{$file->slug}-{$pageNumber}" : pathinfo($fileName, PATHINFO_FILENAME);

            $file->children()->create([
                'parent_id'    => $file->id,
                'name'         => $name,
                'service_id'   => $file->service_id,
                'team_id'      => $file->team_id,
                'slug'         => $slug,
                'download_url' => $storageKey,
                'extension'    => $outputExtension,
                'type'         => 'image',
                'mime_type'    => "image/{$outputExtension}",
                'size'         => $fileSize,
                'user_id'      => $file->user_id,
                'service_name' => $file->service_name,
                'md5'          => $md5,
                'sha256'       => $sha256,
                'view_url'     => $storageKey,
                'thumbnail'    => $file->originalThumbnail,
            ]);
        }

        $file->markProcessing(
            FileOperationName::CONVERT,
            'Generating child assets (children processing stage)',
            ['stage' => 'children_processing']
        );

        return true;
    }

    public static function processHyper1Results(array $results, NataeroTask $nataeroTask): bool
    {
        $file = $nataeroTask->file;

        if (! $file) {
            logger()->error("File not found for Nataero Task ID: {$nataeroTask->id}");
            $nataeroTask->update(['status' => NataeroTaskStatus::FAILED->value]);

            return false;
        }

        $format = $file->type;
        $extension = $file->extension;

        if (! in_array($format, ['image', 'video', 'audio'], true)) {
            logger()->error("Invalid file format for Nataero Task ID: {$nataeroTask->id}");
            $nataeroTask->update(['status' => NataeroTaskStatus::FAILED->value]);

            return false;
        }

        $baseData = [
            'nataero_task_id' => $nataeroTask->id,
            'file_id'         => $nataeroTask->file_id,
            'service_id'      => $file->service_id,
            'service_name'    => $file->service_name,
            'team_id'         => $file->team_id,
        ];

        $operationState = FileOperationName::tryFrom('hyper1');

        try {
            if ($format === FunctionsType::Image->value && $extension !== 'gif') {
                $embedding = data_get($results, 'data.0.embedding');

                if (! is_array($embedding) || empty($embedding)) {
                    throw new RuntimeException("No image embedding found for Nataero Task ID: {$nataeroTask->id}");
                }

                Hyper1ImageEmbedding::query()->updateOrCreate(
                    ['file_id' => $nataeroTask->file_id],
                    [
                        ...$baseData,
                        'embedding' => $embedding,
                    ]
                );
            }

            if ($format === FunctionsType::Video->value) {
                $frames = data_get($results, 'data', []);

                if (is_iterable($frames)) {
                    Hyper1VideoEmbedding::query()->where('file_id', $nataeroTask->file_id)->delete();

                    foreach ($frames as $frame) {
                        $t1 = data_get($frame, 'scene_start');
                        $t2 = data_get($frame, 'scene_end');
                        $td = is_numeric($t1) && is_numeric($t2) ? (float) $t2 - (float) $t1 : null;

                        $nataeroTask->hyper1VideoEmbedding()->create([
                            ...$baseData,
                            'embedding'            => data_get($frame, 'embedding'),
                            'key_frame'            => data_get($frame, 'frame'),
                            'timestamp_1'          => $t1,
                            'timestamp_2'          => $t2,
                            'timestamp_difference' => $td,
                        ]);
                    }
                }

                $meanEmbedding = is_array(data_get($results, 'mean_embedding')) ? data_get($results, 'mean_embedding') : null;
                $embeddingsCount = data_get($results, 'embeddings_count');

                if ($meanEmbedding && $embeddingsCount) {
                    Hyper1AverageVideoEmbedding::query()->updateOrCreate(
                        ['file_id' => $nataeroTask->file_id],
                        [
                            ...$baseData,
                            'embedding'      => $meanEmbedding,
                            'num_key_frames' => (int) $embeddingsCount,
                        ]
                    );
                }
            }

            $nataeroTask->update(['status' => NataeroTaskStatus::SUCCEEDED->value]);

            if ($operationState) {
                $file->markSuccess(
                    $operationState,
                    'Nataero hyper1 completed successfully',
                    ['remote_task_id' => $nataeroTask->remote_nataero_task_id]
                );
            }

            return true;
        } catch (Throwable $e) {
            $nataeroTask->update([
                'status'    => NataeroTaskStatus::FAILED->value,
                'exception' => $e->getMessage(),
            ]);

            if ($operationState) {
                $file->markFailure(
                    $operationState,
                    'Nataero hyper1 failed during result processing',
                    $e->getMessage()
                );
            }

            logger()->error("Hyper1 processing failed for Task {$nataeroTask->id}: {$e->getMessage()}");

            return false;
        }
    }
}
