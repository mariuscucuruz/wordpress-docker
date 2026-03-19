<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Models\Region;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Models\ImportGroup;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\ImportGroupStatus;
use MariusCucuruz\DAMImporter\Integrations\Uploader\Uploader;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Notifications\AssetUploadedNotification;
use MariusCucuruz\DAMImporter\Traits\FileInformation;
use MariusCucuruz\DAMImporter\Traits\CleanupTemporaryFiles;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Commands\NataeroDispatchConversions;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Commands\EnqueueMediaConvertCommand;

class UploadProcessFile implements ShouldQueue
{
    use CleanupTemporaryFiles, Dispatchable, FileInformation, InteractsWithQueue, Loggable, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(public User $user, public array $attributes)
    {
        $this->onQueue(QueueRouter::route('download'));
    }

    public function handle(): void
    {
        $this->startLog();

        try {
            $service = $this->createService();
            $file = $this->createFile($service, $this->attributes);

            if (! $file) {
                throw new Exception('File creation failed');
            }

            if (isset($this->attributes['albumId'])) {
                $this->attachFileToAlbum($file, $this->attributes['albumId']);
            }

            if (isset($this->attributes['licenseId'])) {
                $file->licenses()->attach($this->attributes['licenseId']);
            }

            if ($file->shouldSendToImageMagick() && ! $file->hasDuplicates()) {
                Artisan::call(NataeroDispatchConversions::SIGNATURE, ['--fileID' => $file->id]);
            }

            if ($file->shouldSendToMediaConvert() && ! $file->hasDuplicates()) {
                Artisan::call(EnqueueMediaConvertCommand::SIGNATURE, ['--id' => $file->id]);
            }

            $this->user?->notify(new AssetUploadedNotification($this->user, $file));
        } catch (Exception $e) {
            $this->log($e->getTraceAsString(), 'error');
        } finally {
            $this->endLog();
        }
    }

    private function createService(): Service
    {
        $this->log('Creating service');

        return Service::firstOrCreate([
            'interface_type' => Uploader::class,
            'name'           => config('uploader.name'),
            'user_id'        => $this->user->id,
            'team_id'        => $this->user->currentTeam?->id,
            'region_id'      => Region::where('code', '000')->first()?->id,
        ], [
            'photo'  => config('uploader.owner_photo'),
            'email'  => $this->user->email,
            'status' => IntegrationStatus::ACTIVE,
        ]);
    }

    private function createFile(Service $service, array $attributes): ?File
    {
        $extension = $attributes['extension'];
        $mimeType = config('manager.extensions_and_mime_types')[$extension] ?? $attributes['type'];

        try {
            $this->log('Creating import group');
            $importGroup = ImportGroup::create([
                'service_id'          => $service->id,
                'user_id'             => $this->user->id,
                'team_id'             => $this->user->currentTeam?->id,
                'job_id'              => null,
                'service_type'        => $service->interface_type,
                'status'              => ImportGroupStatus::INITIATED,
                'number_of_files'     => 1,
                'number_of_new_files' => 1,
                'started'             => now(),
            ]);

            $this->log('Creating file');

            $file = File::create([
                'user_id'      => $this->user->id,
                'service_id'   => $service->id,
                'service_name' => $service->name,
                'team_id'      => $this->user->currentTeam?->id,
                'name'         => $attributes['name'], // mutator will auto adjust name
                'slug'         => $attributes['name'], // mutator will auto adjust slug
                'mime_type'    => $mimeType,
                'type'         => $this->getFileTypeFromExtension($attributes['extension']) ?? explode('/', $mimeType)[0],
                'download_url' => $attributes['fileS3key'],
                'extension'    => $extension,
                'size'         => $attributes['size'],
                'duration'     => $attributes['duration'] ?? null,
                'import_group' => $importGroup->id,
            ]);

            $file->determineDownloadOutcome();
            [$md5, $sha256] = $file->generateHashes();
            $file->update(compact('md5', 'sha256'));

            $importGroup->update([
                'status'  => ImportGroupStatus::COMPLETED,
                'updated' => now(),
            ]);

            return $file->refresh();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    private function attachFileToAlbum(File $file, string $albumId): void
    {
        $this->log('Attaching file to album');

        $file->albums()?->attach($albumId);
    }
}
