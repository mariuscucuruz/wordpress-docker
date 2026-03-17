<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use DB;
use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Arr;
use MariusCucuruz\DAMImporter\Models\Scopes\FileScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Database\Factories\RekognitionTaskFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs\ProcessCelebritiesJob;

class RekognitionTask extends Model
{
    use HasFactory,
        HasUuids,
        SoftDeletes;

    public static function numberOfOpenJobs(): int
    {
        return (int) Cache::remember('rekognition-open-job-count', now()->addSeconds(5), function () {
            return self::where('analyzed', false)
                ->where('created_at', '>', now()->subWeek()) // Rekognition tasks older than a week are considered stale
                ->whereNotNull('job_id')
                ->whereNot('job_type', RekognitionTypes::TRANSCRIBES)
                ->where(function (Builder $query) {
                    $query
                        ->whereIn('job_status', [RekognitionJobStatus::IN_PROGRESS, RekognitionJobStatus::PENDING])
                        ->orWhereNull('job_status');
                })
                ->count();
        });
    }

    public static function getAiTypeRelationship(string $aiType): ?string
    {
        return collect([
            'moderation' => 'moderationDetections',
            'landmark'   => 'landmarkDetections',
            'celebrity'  => 'celebrityDetections',
            'text'       => 'texts',
            'label'      => 'labels',
            'emotion'    => 'emotions',
            'transcribe' => 'transcribes',
            'face'       => 'faces',
            'age'        => 'ages',
            'gender'     => 'genders',
            'custom'     => 'customDetections',
        ])->first(fn ($value, $key) => str_contains($aiType, $key));
    }

    public static function usingNewAiTables(string $aiType): bool
    {
        return collect(['moderation', 'landmark', 'celebrity', 'custom'])
            ->contains(fn ($value) => str_contains($aiType, $value));
    }

    protected static function newFactory(): Factory
    {
        return RekognitionTaskFactory::new();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class)->withoutGlobalScope(FileScope::class);
    }

    public function labels(): HasOne
    {
        return $this->hasOne(Label::class);
    }

    public function faces(): HasOne
    {
        return $this->hasOne(Face::class);
    }

    public function texts(): HasOne
    {
        return $this->hasOne(Text::class);
    }

    public function segmentsDetections(): HasOne
    {
        return $this->hasOne(SegmentDetection::class);
    }

    public function transcribes(): HasOne
    {
        return $this->hasOne(Transcribe::class);
    }

    public function ages(): HasOne
    {
        return $this->hasOne(Age::class);
    }

    public function genders(): HasOne
    {
        return $this->hasOne(Gender::class);
    }

    public function emotions(): HasOne
    {
        return $this->hasOne(Emotion::class);
    }

    public function landmarkDetections(): HasMany
    {
        return $this->hasMany(LandmarkDetection::class);
    }

    public function moderationDetections(): HasMany
    {
        return $this->hasMany(ModerationDetection::class);
    }

    public function celebrityDetections(): HasMany
    {
        return $this->hasMany(CelebrityDetection::class);
    }

    public function customDetections(): HasMany
    {
        return $this->hasMany(CustomDetection::class);
    }

    public function replicateLabels(File $similarFile): void
    {
        $similarFile->labels?->replicate()->fill([
            'file_id' => $this->file->id,
        ])->save();
        $similarFile->landmarkDetections?->each(function ($similar) {
            $similar->replicate()->fill([
                'file_id' => $this->file->id,
            ])->save();
        });
    }

    public function saveLabels($labels): bool
    {
        if (empty($labels)) {
            logger('Labels are empty.');

            return false;
        }

        [$landmarksData, $labelsData] = collect($labels)
            ->map(function ($label) {
                $isVideo = array_key_exists('Timestamp', $label);

                $labelData = $isVideo && array_key_exists('Label', $label) ? $label['Label'] : $label;

                $isLandmark = collect($labelData['Parents'] ?? [])->contains('Name', 'Landmark')
                    || collect($labelData['Categories'] ?? [])->contains('Name', 'Popular Landmarks');

                $confidence = $label['Label']['Confidence'] ?? $label['Confidence'] ?? 100;

                $boundingBox = collect($labelData['Instances'] ?? [])
                    ->map(fn ($instance) => $instance['BoundingBox'])
                    ->toArray();

                return [
                    'name'        => $labelData['Name'],
                    'confidence'  => $confidence,
                    'time'        => $isVideo ? $label['Timestamp'] : 0,
                    'isLandmark'  => $isLandmark,
                    'boundingBox' => $boundingBox,
                ];
            })
            ->filter(fn ($label) => $label['confidence'] >= config('rekognition.min_confidence'))
            ->partition(fn ($label) => $label['isLandmark']);

        $landmarksData = $landmarksData
            ->map(fn ($landmark) => Arr::except($landmark, 'isLandmark'))
            ->values()
            ->toArray();

        $labelsData = $labelsData
            ->map(fn ($label) => Arr::except($label, 'isLandmark'))
            ->values()
            ->toArray();

        $this->saveAsJsonToDatabase($labelsData, 'labels');
        $this->saveToDatabase($landmarksData, 'landmarks');

        return true;
    }

    public function replicateCelebrities(File $similarFile): void
    {
        $similarFile->celebrityDetections?->each(function ($similar) {
            $similar->replicate()->fill([
                'file_id' => $this->file->id,
            ])->save();
        });
    }

    public function saveCelebrities($result): bool
    {
        if (empty($result)) {
            logger('Celebrities are empty.');

            return false;
        }

        $celebritiesData = $result['CelebrityFaces'] ?? $result;

        $celebrities = collect($celebritiesData)
            ->flatMap(function ($item) {
                $time = $item['Timestamp'] ?? 0;

                if (! isset($item['Celebrity']) && ! isset($item['Name'])) {
                    return [];
                }

                $celebrityItems = isset($item['Name'])
                    ? [array_merge($item['Face'] ?? [], $item)]
                    : (isset($item['Celebrity']['Name'])
                        ? [$item['Celebrity']]
                        : $item['Celebrity']
                    );

                return collect($celebrityItems)->map(fn ($celebrity) => [
                    'time'        => $time,
                    'name'        => $celebrity['Name'] ?? '',
                    'confidence'  => $celebrity['Confidence'] ?? $item['MatchConfidence'] ?? null,
                    'urls'        => $celebrity['Urls'] ?? [],
                    'knownGender' => $celebrity['KnownGender']['Type'] ?? '',
                    'boundingBox' => $celebrity['Face']['BoundingBox'] ?? $celebrity['BoundingBox'] ?? $item['Face']['BoundingBox'] ?? [],
                ]);
            })
            ->filter(fn ($celebrity) => $celebrity['confidence'] >= config('rekognition.celebrity_min_confidence'))
            ->all();

        return $this->saveToDatabase($celebrities, 'celebrities');
    }

    public function replicateFaces(File $similarFile): void
    {
        $categories = ['genders', 'ages', 'emotions', 'faces'];

        foreach ($categories as $category) {
            $similarFile->{$category}?->replicate()->fill([
                'file_id' => $this->file->id,
            ])->save();
        }
    }

    public function saveFaces($faces): bool
    {
        if (empty($faces)) {
            logger('Faces are empty.');

            return false;
        }

        $processed = [
            'genders'  => collect(),
            'ages'     => collect(),
            'emotions' => collect(),
            'faces'    => collect(),
        ];

        collect($faces)
            ->each(function ($faceData) use (&$processed) {
                $face = $faceData['Face'] ?? $faceData;
                $timestamp = $faceData['Timestamp'] ?? 0;
                $boundingBox = $face['BoundingBox'] ?? [];

                if (isset($face['Gender']['Value']) && $face['Gender']['Confidence'] >= config('rekognition.min_confidence')) {
                    $processed['genders']->push([
                        'name'        => $face['Gender']['Value'],
                        'time'        => $timestamp,
                        'confidence'  => $face['Gender']['Confidence'],
                        'boundingBox' => $boundingBox,
                    ]);
                }

                if (isset($face['AgeRange'])) {
                    $processed['ages']->push([
                        'min'         => $face['AgeRange']['Low'],
                        'max'         => $face['AgeRange']['High'],
                        'time'        => $timestamp,
                        'boundingBox' => $boundingBox,
                    ]);
                }

                collect($face['Emotions'] ?? [])
                    ->each(function ($emotion) use (&$processed, &$timestamp, &$boundingBox) {
                        if ($emotion['Confidence'] >= config('rekognition.min_confidence')) {
                            $processed['emotions']->push([
                                'name'        => ucfirst(strtolower($emotion['Type'])),
                                'time'        => $timestamp,
                                'confidence'  => $emotion['Confidence'],
                                'boundingBox' => $boundingBox,
                            ]);
                        }
                    });

                collect($face)
                    ->except(['Gender', 'AgeRange', 'Emotions', 'Pose', 'Quality', 'Landmarks'])
                    ->each(function ($value, $key) use (&$timestamp, &$processed) {
                        if (
                            isset($value['Confidence']) &&
                            $value['Confidence'] >= config('rekognition.min_confidence')
                        ) {
                            if (! isset($value['Value'])) {
                                return;
                            }

                            $faceData = [
                                'name'       => $key,
                                'time'       => $timestamp,
                                'confidence' => $value['Confidence'],
                            ];

                            if (! empty($value['BoundingBox'])) {
                                $faceData['boundingBox'] = $value['BoundingBox'];
                            }

                            $processed['faces']->push($faceData);
                        }
                    });
            });

        collect($processed)
            ->each(fn ($items, $category) => $this->saveAsJsonToDatabase($items, $category));

        return true;
    }

    public function replicateTexts(File $similarFile): void
    {
        $similarFile->texts?->replicate()->fill([
            'file_id' => $this->file->id,
        ])->save();
    }

    public function saveTexts($texts): bool
    {
        if (empty($texts)) {
            logger('Texts are empty.');

            return false;
        }

        $processedTexts = collect($texts)
            ->map(function ($text) {
                $detectedText = null;
                $confidence = null;
                $boundingBox = null;

                if (isset($text['TextDetection'])) {
                    $textDetection = $text['TextDetection'];
                    $detectedText = $textDetection['DetectedText'];
                    $confidence = $textDetection['Confidence'] ?? 100;
                    $boundingBox = $textDetection['Geometry']['BoundingBox'] ?? [];
                }

                if (isset($text['DetectedText'])) {
                    $detectedText = $text['DetectedText'];
                    $confidence = $text['Confidence'] ?? 100;
                    $boundingBox = $text['Geometry']['BoundingBox'] ?? [];
                }

                if (empty($detectedText) || $confidence < config('rekognition.min_confidence')) {
                    return null;
                }

                return [
                    'name'        => $detectedText,
                    'time'        => $text['Timestamp'] ?? 0,
                    'confidence'  => $confidence,
                    'boundingBox' => $boundingBox,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $this->saveAsJsonToDatabase($processedTexts, 'texts');
    }

    public function replicateSegments(File $similarFile): void
    {
        $similarFile->segmentDetections?->each(function ($similar) {
            $similar->replicate()->fill([
                'file_id' => $this->file->id,
            ])->save();
        });
    }

    public function saveSegments($segments): bool
    {
        if (empty($segments)) {
            return false;
        }

        try {
            $segmentData = array_map(function ($segment) {
                return [
                    'id'                  => str()->uuid(),
                    'file_id'             => $this->file->id,
                    'mime_type'           => $this->file->mime_type,
                    'confidence'          => data_get($segment, 'confidence', 100),
                    'service_type'        => 'AWS',
                    'service_name'        => config('rekognition.name'),
                    'model_version'       => config('rekognition.version'),
                    'type'                => data_get($segment, 'Type'),
                    'duration_time'       => data_get($segment, 'DurationSMPTE'),
                    'start_time_millis'   => (int) data_get($segment, 'StartTimestampMillis'),
                    'end_time_millis'     => (int) data_get($segment, 'EndTimestampMillis'),
                    'start_time'          => data_get($segment, 'StartTimecodeSMPTE'),
                    'end_time'            => data_get($segment, 'EndTimecodeSMPTE'),
                    'start_frame'         => data_get($segment, 'StartFrameNumber'),
                    'end_frame'           => data_get($segment, 'EndFrameNumber'),
                    'rekognition_task_id' => $this->id,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];
            }, $segments);

            DB::table('segment_detections')->insert($segmentData);
        } catch (Exception $e) {
            logger()->error($e->getMessage());

            return false;
        }

        return true;
    }

    public function replicateModerations(File $similarFile): void
    {
        $similarFile->moderationDetections?->each(function ($similar) {
            $similar->replicate()->fill([
                'file_id' => $this->file->id,
            ])->save();
        });
    }

    public function saveModerations($moderations): bool
    {
        if (! count($moderations)) {
            logger('Moderation is empty.');

            return false;
        }

        $moderations = collect($moderations)
            ->map(function (array $moderation) {
                if (array_key_exists('ModerationLabel', $moderation)) {
                    $moderation['Name'] = $moderation['ModerationLabel']['Name'];
                    $moderation['Confidence'] = $moderation['ModerationLabel']['Confidence'] ?? 100;
                    $moderation['ParentName'] = $moderation['ModerationLabel']['ParentName'] ?? '';
                    $moderation['TaxonomyLevel'] = $moderation['ModerationLabel']['TaxonomyLevel'] ?? '';
                }

                return $moderation;
            })
            ->map(fn (array $moderation) => [
                'name'       => $moderation['Name'],
                'time'       => $moderation['Timestamp'] ?? 0,
                'confidence' => $moderation['Confidence'] ?? 100,
                'instances'  => [
                    'parentName'    => $moderation['ParentName'] ?? '',
                    'taxonomyLevel' => $moderation['TaxonomyLevel'] ?? '',
                ],
            ])
            ->filter(fn (array $moderation) => ($moderation['confidence']) >=
                config('rekognition.moderation_min_confidence'))
            ->toArray();

        return $this->saveToDatabase($moderations, 'moderations');
    }

    public function replicateTranscribes(File $similarFile): void
    {
        $similarFile->transcribes?->replicate()->fill([
            'file_id' => $this->file->id,
        ])->save();
    }

    public function saveTranscribes($transcribes): bool
    {
        $items = collect($transcribes['results']['items'])
            ->map(function ($transcribeProperties) {
                $startTime = isset($transcribeProperties['start_time'])
                    ? (float) $transcribeProperties['start_time'] * 1000
                    : 0;

                $endTime = isset($transcribeProperties['end_time'])
                    ? (float) $transcribeProperties['end_time'] * 1000
                    : 0;

                $confidence = (float) ($transcribeProperties['alternatives'][0]['confidence'] ?? 1) * 100;

                return [
                    'name'       => $transcribeProperties['alternatives'][0]['content'],
                    'time'       => (int) $startTime,
                    'confidence' => $confidence,
                    'instances'  => [
                        'type'          => data_get($transcribeProperties, 'type'),
                        'language_code' => data_get($transcribeProperties, 'language_code'),
                        'start_time'    => $startTime,
                        'end_time'      => $endTime,
                    ],
                ];
            })
            ->filter(fn (array $transcribeProperties) => $transcribeProperties['confidence'] >=
                config('rekognition.min_confidence'))
            ->values()
            ->toArray();

        // Extract `language_code` from the first item, fallback to null if not found
        $languageCode = collect($transcribes['results']['items'])
            ->pluck('language_code')
            ->filter()
            ->first();

        $data = [
            'file_id'       => $this->file->id,
            'items'         => $items ?? [],
            'language_code' => $languageCode ?: null,
        ];

        return $this->transcribes()->create($data)->wasRecentlyCreated;
    }

    protected function casts(): array
    {
        return [
            'analyzed'   => 'boolean',
            'job_status' => RekognitionJobStatus::class,
            'job_type'   => RekognitionTypes::class,
        ];
    }

    private function saveToDatabase($items, $relation): bool
    {
        if (empty($items)) {
            logger(str($relation)->title()->toString() . ' are empty.');

            return false;
        }

        if ($relation === 'celebrities') {
            dispatch(new ProcessCelebritiesJob($this->file, $items));

            return true;
        }

        foreach ($items as $item) {
            $data = [
                'file_id'          => $this->file->id,
                'mime_type'        => $this->file->mime_type,
                'confidence'       => $item['confidence'] ?? 100,
                'time'             => $item['time'] ?? 0,
                'instances'        => $item['instances'] ?? null,
                'bounding_box'     => $item['boundingBox'] ?? null,
                'image_properties' => $item['imageProperties'] ?? null,
                'service_type'     => 'AWS',
                'service_name'     => config('rekognition.name'),
                'model_version'    => config('rekognition.version'),
            ];

            if ($relation === 'ages') {
                $data['min'] = $item['min'] ?? null;
                $data['max'] = $item['max'] ?? null;
            } else {
                $data['name'] = $item['name'];
            }

            $relationship = str($relation)->singular()->toString() . 'Detections';

            $this->{$relationship}()->create($data);
        }

        return true;
    }

    private function saveAsJsonToDatabase($items, $relation, $languageCode = null): bool
    {
        if (empty($items)) {
            logger(str($relation)->title()->toString() . ' are empty.');

            return false;
        }

        $data = [
            'file_id' => $this->file->id,
            'items'   => $items,
        ];

        if ($relation === RekognitionTypes::TRANSCRIBES->value && $languageCode) {
            $data['language_code'] = $languageCode;
        }

        return $this->{$relation}()->create($data)->wasRecentlyCreated;
    }
}
