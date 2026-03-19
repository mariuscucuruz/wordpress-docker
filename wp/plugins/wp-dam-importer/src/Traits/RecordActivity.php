<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Activity;
use MariusCucuruz\DAMImporter\Enums\ActivityEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait RecordActivity
{
    public static function bootRecordActivity(): void
    {
        foreach (static::getActivityEvents() as $event) {
            static::$event(fn ($model) => $model->recordActivity($event));

            // BUG: `queueable` is not working for some reason:
            //  activity cannot be queued because it is not serializable.
            // static::$event(queueable(fn ($model) => $model->recordActivity($event)));
        }

        static::deleting(fn ($model) => $model->activities()->delete());
    }

    protected static function getActivityEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'record');
    }

    protected function recordActivity($event): void
    {
        if (auth()->guest()) {
            return;
        }

        if (! is_array($event)) {
            $event = [
                'record_event' => $this->getActivityType($event),
                'record_data'  => $this->getActivityData(),
            ];
        }
        $event['user_id'] = auth()->id();

        try {
            $this->activities()->create($event);
        } catch (QueryException $exception) {
            logger()->error($exception->getMessage());
        }
    }

    protected function getActivityType($action): string
    {
        $action = ucfirst($action);
        $entity = class_basename($this);
        $activity = "{$action}{$entity}";

        if (! $event = ActivityEvent::getFromValue($activity)) {
            logger()->error("Could not match {$activity} ({$action}) to ActivityEvent.", $this->toArray());
        }

        return $event?->value ?? $activity;
    }

    protected function getActivityData(): array
    {
        return match (class_basename($this)) {
            'Comment' => $this->load('commentable')->toArray(),
            'Like'    => $this->load('likeable')->toArray(),
            'Service' => [
                'id'             => $this->id,
                'name'           => $this->name,
                'interface_type' => $this->interface_type,
            ],
            'File' => [
                'id'         => $this->id,
                'name'       => $this->name,
                'service_id' => $this->service_name,
                'type'       => $this->type,
            ],
            'Setting' => [
                'id'         => $this->id,
                'group'      => $this->group,
                'name'       => $this->name,
                'title'      => $this->title,
                'type'       => $this->type,
                'service_id' => $this->service_id,
            ],
            default => $this->toArray(),
        };
    }
}
