<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Traits;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepCrawlItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait HasStandardMethods
{
    public function getUser(?string $email = null): ?UserDTO
    {
        $email ??= auth()->user()->email;

        if (empty($email)) {
            return null;
        }

        try {
            $user = User::with('teams')
                ->where('email', $email)
                ->firstOrFail();

            return new UserDTO([
                'username' => $user->username,
                'email'    => $user->email,
                'name'     => $user->name,
                'user_id'  => $user->id,
                'team_id'  => $user->currentTeam->id,
                'photo'    => $user->getOriginal('profile_photo_path'),
            ]);
        } catch (ModelNotFoundException) {
            $this->log("Cannot find user {$email}", 'warn');
        } catch (Exception $e) {
            $this->log("Error fetching user {$email}: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function recordDoesntExist(Service $service, File $file, mixed $attributes): bool
    {
        if (! data_get($attributes, 'remote_service_file_id')) {
            return true;
        }

        return $file
            ->where('service_id', $service->id)
            ->where('remote_service_file_id', data_get($attributes, 'remote_service_file_id'))
            ->where('service_name', 'websweep')
            ->doesntExist();
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO([
            'api_key' => config('websweep.api_token'),
            'expires' => null,
            'created' => now(),
        ]);
    }

    /**
     * @throws ConnectionException
     */
    public function getThumbnailPath(mixed $file = null, $source = null): string
    {
        $id = data_get($file, 'id', (string) Str::uuid());

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            Str::slug($id) . '.jpg'
        );

        $crawlItemId = data_get($file, 'remote_service_file_id');

        if ($crawlItemId) {
            $crawlItem = WebSweepCrawlItem::query()->find($crawlItemId);

            $source = $crawlItem?->url;
        }

        if (! $source) {
            return '';
        }

        $this->storage->put($thumbnailPath, $this->http()->get($source)->body());

        return $thumbnailPath;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        if (empty($file)) {
            return new FileDTO;
        }

        return new FileDTO([
            'id'                     => data_get($file, 'id'),
            'remote_service_file_id' => data_get($file, 'id'),
            'user_id'                => data_get($attr, 'user_id') ?? $this->service->user->id,
            'team_id'                => data_get($attr, 'team_id') ?? $this->service->team->id,
            'service_id'             => data_get($attr, 'service_id') ?? $this->service->id,
            'service_name'           => data_get($attr, 'service_name') ?? $this->service->customName ?? $this->service->name,
            'import_group'           => data_get($attr, 'import_group'),
            'size'                   => data_get($file, 'size'),
            'name'                   => data_get($file, 'file_id'),
            'extension'              => data_get($file, 'file_extension'),
            'slug'                   => Str::slug(data_get($file, 'file_name') ?? data_get($file, 'name')),
            'mime_type'              => $this->getMimeTypeOrExtension(data_get($file, 'file_extension')),
            'type'                   => $this->getFileTypeFromExtension(data_get($file, 'file_extension')) ?? data_get($file, 'type'),
            'created_time'           => Carbon::parse(data_get($file, 'created_at', 'now')),
            'modified_time'          => Carbon::parse(data_get($file, 'updated_at', 'now')),
        ]);
    }

    public function getMetadataAttributes(?array $properties = null): array
    {
        return [
            'group'       => $this->service->customName ?? $this->service->name,
            'caption'     => data_get($properties, 'text'),
            'media_url'   => data_get($properties, 'url'),
            'source_link' => data_get($properties, 'from_url'),
            'media_type'  => data_get($properties, 'type'),
        ];
    }

    public function uniqueFileId($attribute, $key = 'remote_service_file_id'): mixed
    {
        return data_get($attribute, $key);
    }
}
