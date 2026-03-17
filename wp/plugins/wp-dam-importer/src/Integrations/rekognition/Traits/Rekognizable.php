<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use Illuminate\Support\Arr;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Age;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Face;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Text;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Label;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Gender;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Emotion;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\Transcribe;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\CustomDetection;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\RekognitionTask;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\SegmentDetection;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\LandmarkDetection;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\CelebrityDetection;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\ModerationDetection;

trait Rekognizable
{
    public function rekognitionTasks(): HasMany
    {
        return $this->hasMany(RekognitionTask::class, 'file_id');
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

    public function celebrityDetections(): HasMany
    {
        return $this->hasMany(CelebrityDetection::class);
    }

    public function segmentDetections(): HasMany
    {
        return $this->hasMany(SegmentDetection::class);
    }

    public function moderationDetections(): HasMany
    {
        return $this->hasMany(ModerationDetection::class);
    }

    public function landmarkDetections(): HasMany
    {
        return $this->hasMany(LandmarkDetection::class);
    }

    public function customDetections(): HasMany
    {
        return $this->hasMany(CustomDetection::class);
    }

    public function deleteAi(string $aiType, string $name, string|int|null $timestamp): bool
    {
        $relationship = RekognitionTask::getAiTypeRelationship($aiType) ?? $aiType;

        abort_unless($relationship, 400, 'Invalid AI type');

        $this->load($relationship);

        if (RekognitionTask::usingNewAiTables($aiType)) {
            $query = $this->{$relationship}()->where('name', $name);
            $addTimestamp = collect(['emotion', 'moderation'])
                ->contains(fn ($value) => str_contains($aiType, $value));

            if ($addTimestamp && filled($timestamp)) {
                $query->where('time', $timestamp);
            }

            return (bool) $query->delete();
        }

        $ai = $this->{$relationship}()->first();
        abort_unless($ai, 400, 'Invalid AI type');

        $filteredContent = Arr::where($ai->items, function ($value) use ($timestamp, $name) {
            if (empty($timestamp)) {
                return $value['name'] !== $name;
            }

            return ! ($value['name'] === $name && $value['time'] === $timestamp);
        });

        $items = $ai->items ? array_values($filteredContent) : [];

        return $ai->update(['items' => empty($items) ? null : $items]);
    }
}
