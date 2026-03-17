<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Meta;
use MariusCucuruz\DAMImporter\Enums\MetaType;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Metable
{
    public function metas(): MorphMany
    {
        return $this->morphMany(Meta::class, 'metable');
    }

    public function getDefaultMeta(): array
    {
        return collect(config('manager.meta', []))
            ->map(fn (mixed $value, string $key) => [$key => $this->getMetaValue($key)])
            ->collapse()
            ->toArray();
    }

    public function saveMeta(string $key, mixed $value): void
    {
        $redactedValue = redact_sensitive_info_from_payload($value);

        $this->metas()->updateOrCreate(
            compact('key'),
            [
                'value' => is_array($redactedValue)
                    ? array_filter($redactedValue, fn (mixed $val) => $val)
                    : $redactedValue,
            ]);
    }

    public function getMeta(string $key): ?Meta
    {
        return Meta::query()
            ->where('key', $key)
            ->whereMorphedTo('metable', $this)
            ->latest('id')
            ->first();
    }

    public function getMetaValue(string $key): string|array|null
    {
        return $this->getMeta($key)?->getAttribute('value');
    }

    public function getMetaExtra(?string $key = null): string|array|null
    {
        $metaExtra = $this->getMeta(MetaType::extra->value)?->getAttribute('value');

        return data_get($metaExtra, $key);
    }

    public function setMetaExtra(mixed $metaValue = null): self
    {
        if (filled($metaValue)) {
            $this->saveMeta(MetaType::extra->value, $metaValue);
        }

        return $this;
    }

    public function getMetaRequest(?string $key = null): string|array|null
    {
        $metaExtra = $this->getMeta(MetaType::request->value)?->getAttribute('value');

        return data_get($metaExtra, $key);
    }

    public function setMetaRequest(mixed $metaValue = null): self
    {
        if (filled($metaValue)) {
            $this->saveMeta(MetaType::request->value, $metaValue);
        }

        return $this;
    }

    public function doesMetaContain(string $key): bool
    {
        return $this->metas?->contains('key', $key);
    }
}
