<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Carbon\Carbon;
use Clickonmedia\Exif\Exif;
use Laravel\Scout\Searchable;
use MariusCucuruz\DAMImporter\Models\Scopes\FileScope;
use MariusCucuruz\DAMImporter\Enums\FileVisibilityStatus;
use Illuminate\Database\Eloquent\Builder;

trait SearchableFile
{
    use Searchable;

    public function searchIndexShouldBeUpdated(): bool
    {
        $searchableArrayKeys = array_keys($this->generateSearchableArray());

        $attributeKeys = [];
        $relationKeys = [];

        foreach ($searchableArrayKeys as $key) {
            if ($this->isRelation($key)) {
                $relationKeys[] = $key;
            } elseif ($this->isRelation($rel = str($key)->singular()->toString() . 'Detections')) {
                $relationKeys[] = $rel;
            } else {
                $attributeKeys[] = $key;
            }
        }

        if ($this->wasChanged($attributeKeys)) {
            return true;
        }

        foreach ($relationKeys as $relation) {
            if (! $this->relationLoaded($relation)) {
                $this->loadMissing([$relation]);
            }

            $related = $this->getRelation($relation);

            if ($this->isRelationDirty($related)) {
                return true;
            }
        }

        return false;
    }

    public function toSearchableArray(): array
    {
        $data = $this->generateSearchableArray();
        $reducedData = $this->removeUnnecessarySearchData($data);
        $reducedData = $this->reduceLabelsSearchableData($reducedData);

        return $this->getReducedSearchableArray($reducedData, 9000); // 10KB is the Algolia limit
    }

    public function generateSearchableArray(): array
    {
        $this->loadMissing([
            'user',
            'service',
            'labels',
            'texts',
            'transcribes',
            'moderationDetections',
            'celebrityDetections',
            'exifMetadata',
            'customDetections',
            'sneakpeeks',
            'acrCloudMusicTracks',
        ]);

        $serviceMetadataArray = (array) ($this->service?->getMetaRequest('metadata') ?? []);
        $match = collect($serviceMetadataArray)->firstWhere('folder_id', $this->remote_page_identifier);

        $folderIdNamePair = (filled($this->remote_page_identifier) && filled(data_get($match, 'folder_name')))
            ? "{$this->remote_page_identifier}||" . data_get($match, 'folder_name')
            : null;

        return [
            'id'                                 => $this->id,
            'user_id'                            => $this->user_id,
            'team_id'                            => $this->team_id,
            'parent_id'                          => $this->parent_id,
            'service_id'                         => $this->service_id,
            'original_thumbnail'                 => $this->originalThumbnail,
            'md5'                                => $this->md5,
            'sha256'                             => $this->sha256,
            'state'                              => $this->state,
            'name'                               => $this->name,
            'slug'                               => $this->slug,
            'mime_type'                          => $this->mime_type,
            'type'                               => $this->type,
            'extension'                          => $this->extension,
            'resolution'                         => $this->resolution,
            'size'                               => $this->size,
            'duration'                           => $this->duration,
            'fps'                                => $this->fps,
            'is_duplicate'                       => $this->is_duplicate,
            'is_master'                          => (bool) $this->is_master,
            'created_at'                         => $this->created_at->timestamp,
            'created_time'                       => Carbon::parse($this->created_time)->timestamp,
            'updated_at'                         => $this->updated_at->timestamp,
            'remote_service_file_id'             => $this->remote_service_file_id,
            'remote_page_identifier_folder_name' => $folderIdNamePair,
            'operation_states'                   => $this->operationStates,
            'user'                               => [
                'id'                     => $this->user?->id,
                'name'                   => $this->user?->name,
                'email'                  => $this->user?->email,
                'username'               => $this->user?->username,
                'original_profile_photo' => $this->user?->originalProfilePhotoUrl,
            ],
            'service' => [
                'id'    => $this->service?->id,
                'name'  => $this->service?->name,
                'email' => $this->service?->email,
            ],

            'sneakpeeks' => $this->sneakpeeks->map(function ($sneakpeek) {
                return array_merge(
                    $sneakpeek->toArray(),
                    [
                        'remote_path' => $sneakpeek->originalRemotePath,
                    ]
                );
            }),

            'acr_cloud_music_tracks' => $this->acrCloudMusicTracks->map(function ($track) {
                return [
                    'title'   => $track->title,
                    'artists' => $track->artists,
                    'album'   => $track->album,
                    'label'   => $track->label,
                    'isrc'    => $track->isrc,
                ];
            }),

            'labels'      => $this->getUniqueNames($this->labels()),
            'texts'       => $this->getUniqueNames($this->texts()),
            'transcribes' => $this->getUniqueNames($this->transcribes()),
            'moderations' => $this->getUniqueNames($this->moderationDetections()),
            'celebrities' => $this->getUniqueNames($this->celebrityDetections()),

            'exifs' => Exif::searchableData($this),

            'customDetections' => $this->getUniqueNames($this->customDetections()),
            'albums'           => $this->albums->map(function ($album) {
                return [
                    'id'       => $album->id,
                    'name'     => $album->name,
                    'is_liked' => $album->is_liked,
                ];
            })->toArray(),
        ];
    }

    /**
     * Removes words from the dataset that exist in the dataset as part of a phrase;
     * for example, if the dataset contains the word "hello" and the phrase "hello world",
     * the word "hello" will be removed from the dataset.
     */
    protected function reduceLabelsSearchableData(array $data): array
    {
        $phrases = [];
        $singleWords = [];

        // Separate phrases and single words
        foreach ($data['labels'] as $word) {
            if (str_word_count($word) > 1) {
                $phrases[] = $word;
            } else {
                $singleWords[] = $word;
            }
        }

        // Split phrases into individual words and remove them from the singleWords array
        foreach ($phrases as $phrase) {
            $phraseWords = explode(' ', $phrase);

            foreach ($phraseWords as $word) {
                $index = array_search($word, $singleWords);

                if ($index !== false) {
                    unset($singleWords[$index]);
                }
            }
        }

        $data['labels'] = array_values([...$phrases, ...$singleWords]);

        return $data;
    }

    /**
     * Removes unnecessary data from the dataset. The items removed are unlikely to
     * be searched directly, such as filesize or thumbnail URL; duplicate items, such as
     * user_id (existing in user.id) are also removed.
     */
    protected function removeUnnecessarySearchData(array $dataset): array
    {
        return array_filter(
            $dataset,
            fn ($key) => ! in_array(
                $key,
                [
                    'user_id',
                    'service_id',
                    'thumbnail',
                    'thumbnail_url',
                    'md5',
                    'remote_service_file_id',
                    'slug',
                ]
            ),
            ARRAY_FILTER_USE_KEY
        );
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->withoutGlobalScope(FileScope::class)
            ->whereNot('visibility', FileVisibilityStatus::ARCHIVED);
    }
}
