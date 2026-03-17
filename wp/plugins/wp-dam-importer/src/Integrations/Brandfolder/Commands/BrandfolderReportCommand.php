<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Brandfolder\Commands;

use Throwable;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Brandfolder\Brandfolder;
use MariusCucuruz\DAMImporter\SourceIntegration;

class BrandfolderReportCommand extends Command
{
    use Loggable;

    protected $signature = 'brandfolder:report'
        . ' { --serviceId= : The ID of the service to use the API key from }';

    protected $description = 'Breakdown, by file type, of all assets in a given Brandfolder DAM.';

    public null|Brandfolder|SourceIntegration $brandfolder = null;

    /** counter good / bad attachments by their extensions */
    public array $files = [
        'good' => [],
        'bad'  => [],
    ];

    public int $countAllAttachments = 0;

    public int $countAssetsSupported = 0;

    public int $countAssetsNotSupported = 0;

    public function handle(): int
    {
        $this->startLog();

        $service = Service::query()->findOrFail($this->option('serviceId'));

        $serviceName = $service->custom_name ?? "{$service->name} ({$service->remote_service_id})";

        /** @var Brandfolder|SourceIntegration $brandfolder */
        $this->brandfolder = $service?->getPackage();

        if (empty($this->brandfolder)) {
            $this->error("Could not load Brandfolder integration for {$serviceName}.");

            return self::FAILURE;
        }

        $this->warn("Breakdown {$serviceName}....");

        $countAllAssets = 0;

        foreach ($this->brandfolder->getFolders() as $pageInfo) {
            $folderId = (string) data_get($pageInfo, 'id');
            $accName = (string) data_get($pageInfo, 'attributes.name');
            $accPrivacy = (string) data_get($pageInfo, 'attributes.privacy');
            $assetCount = (int) data_get($pageInfo, 'attributes.asset_count');
            $countAllAssets += $assetCount;

            $this->info("[{$serviceName}] {$accName} ({$accPrivacy}) {$assetCount} assets:");

            $attachmentsInFolder = 0;
            $countFolderAttachmentsGood = 0;
            $countFolderAttachmentsBad = 0;

            foreach ($this->brandfolder->getAssetsInFolder($folderId) as $brandfolderAsset) {
                $sectionName = str_pad($accName, 22, ' ');
                $brandfolderId = str_pad((string) data_get($brandfolderAsset, 'id'), 25, ' ', STR_PAD_RIGHT);
                $assetType = (string) data_get($brandfolderAsset, 'type');
                $attachments = (array) data_get($brandfolderAsset, 'relationships.attachments');
                $attachmentsInFolder += count($attachments);

                // $this->info(">> [{$sectionName}:{$brandfolderId}] {$assetType} has " . count($attachments) . ' attachments:');

                foreach ($attachments as $attId => $attachment) {
                    $attachId = (string) data_get($attachment, 'id') ?: $attId;
                    data_set($attachment, 'id', $attachId, false);

                    $ext = data_get($attachment, 'extension');

                    $this->countAttachment($ext);

                    if ($this->brandfolder->isExtensionSupported($ext)) {
                        $countFolderAttachmentsGood++;
                    } else {
                        $countFolderAttachmentsBad++;
                    }
                }
            }

            if ($attachmentsInFolder !== $assetCount) {
                $this->info(">> [{$accName}:{$folderId}] Counted {$attachmentsInFolder} assets from {$assetCount} assets.");
            }

            $this->info(" - Supported: {$countFolderAttachmentsGood}.");
            $this->info(" - Invalid: {$countFolderAttachmentsBad}.");
            $this->newLine();
        }

        $this->newLine();
        $this->line('> Aggregated');
        $this->info(" - Assets reported: {$countAllAssets}");
        $this->info(" - Attachments: {$this->countAllAttachments}");
        $this->info(" - Supported: {$this->countAssetsSupported}.");
        $this->info(" - Invalid: {$this->countAssetsNotSupported}.");

        $this->newLine();

        $this->info("> Good ones: {$this->countAssetsSupported}");
        $this->table(array_keys($this->files['good']), [$this->files['good']]);

        $this->info("> Bad ones: {$this->countAssetsNotSupported}");
        $this->table(array_keys($this->files['bad']), [$this->files['bad']]);

        $this->concludedLog(" [Total attachments: {$this->countAllAttachments} ] ({$this->countAssetsSupported} / {$this->countAssetsNotSupported}) ");

        $this->endLog();

        return self::SUCCESS;
    }

    public function failed(Throwable $exception = null): void
    {
        $this->log("Brandfolder report command failed: {$exception->getMessage()}", 'warning', null, $exception->getTrace());

        $this->warn("Command failed: {$exception->getMessage()}.");
    }

    private function countAttachment(?string $extension = null): void
    {
        if (empty($extension)) {
            return;
        }

        $this->countAllAttachments++;

        if ($this->brandfolder->isExtensionSupported($extension)) {
            $this->countAssetsSupported++;

            if (! array_key_exists($extension, $this->files['good'])) {
                $this->files['good'][$extension] = 0;
            }

            $this->files['good'][$extension]++;
        } else {
            $this->countAssetsNotSupported++;

            if (! array_key_exists($extension, $this->files['bad'])) {
                $this->files['bad'][$extension] = 0;
            }

            $this->files['bad'][$extension]++;
        }
    }
}
