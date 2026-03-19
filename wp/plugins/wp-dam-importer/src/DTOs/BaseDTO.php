<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

use Throwable;
use BackedEnum;
use ArrayAccess;
use ArrayIterator;
use ReflectionClass;
use IteratorAggregate;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use ReflectionIntersectionType;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use MariusCucuruz\DAMImporter\Attributes\MapArrayItemsTo;
use MariusCucuruz\DAMImporter\Attributes\ArrayItemKeyName;

abstract class BaseDTO implements Arrayable, ArrayAccess, IteratorAggregate, Jsonable
{
    public const array PRIMITIVE_TYPES = ['string', 'int', 'float', 'bool', 'array', 'null'];

    public static function fromArray(array $data): static
    {
        $instance = new static;

        foreach ($data as $key => $value) {
            $camelKey = Str::camel($key);

            if (! property_exists($instance, $camelKey)) {
                continue;
            }

            $className = $instance->getObjectPropertyClass($camelKey);
            $mapArrayTo = $instance->getPropertyMapArrayTo($camelKey);

            // Skip primitive types to prevent "Class 'string' not found" errors
            if ($className && in_array($className, self::PRIMITIVE_TYPES)) {
                $className = null;
            }

            if (str_ends_with($key, '_at') || str_ends_with($key, 'At') || str_contains($key, 'date')) {
                $value = $value ? Carbon::parse($value) : null;
            } elseif (is_numeric($value) && preg_match('~^1\d{9}$~', (string) $value) === 1 && ! str_contains(strtolower($key), 'byte')) {
                $value = Carbon::createFromTimestamp($value);
            } elseif (is_string($value) && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[-+]\d{2}:\d{2}$~', $value) === 1 && ! str_contains(strtolower($key), 'byte')) {
                $value = Carbon::parse($value);
            } elseif (is_string($value) && preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.?\d{1,6}?Z?~', $value) === 1 && ! str_contains(strtolower($key), 'byte')) {
                $value = Carbon::parse($value);
            } elseif ($instance->canPropertyBeBackedEnum($camelKey, $value)) {
                try {
                    $class = $instance->getPropertyType($camelKey)?->getName();

                    if ($class && ! in_array($class, self::PRIMITIVE_TYPES) && class_exists($class)) {
                        $value = $class::from($value);
                    }
                } catch (Throwable) {
                    // Skip enum conversion if reflection fails
                }
            } elseif ($instance->canPropertyBeCustomObject($camelKey, $value)) {
                $value = $instance->makePropertyInstance($camelKey, $value);
            } elseif (is_array($value) && $className && ! in_array($className, self::PRIMITIVE_TYPES) && class_exists($className)) {
                $value = $className::fromArray($value);
            } elseif (is_array($value) && $mapArrayTo) {
                $value = array_map(fn ($item) => $mapArrayTo::fromArray($item), $value);
            }

            $instance->$camelKey = $value;
        }

        return $instance;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options, 50);
    }

    public function toArray(): array
    {
        $result = [];

        $properties = (new ReflectionClass($this))->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach (get_object_vars($this) as $key => $typeName) {
            $value = $typeName;
            $arrKey = $this->getPropertyArrayKeyName($key) ?? $key;

            $result[$arrKey] = match (true) {
                $value instanceof self, $value instanceof Arrayable => $value->toArray(),
                $value instanceof BackedEnum => $value->value,
                $value instanceof Carbon     => $value->toIso8601ZuluString(),
                default                      => $value,
            };
        }

        return $result;
    }

    public function offsetExists($offset): bool
    {
        return property_exists($this, $offset);
    }

    public function offsetGet($offset): mixed
    {
        return $this->$offset;
    }

    public function offsetSet($offset, $value): void
    {
        $this->$offset = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->$offset);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->toArray());
    }

    protected function getObjectPropertyClass(string $key): ?string
    {
        $reflectionProperty = new ReflectionProperty($this, $key);

        if (method_exists($reflectionProperty->getType(), 'getName')) {
            return $reflectionProperty->getType()?->getName();
        }

        return null;
    }

    protected function canPropertyBeBackedEnum($key, $value)
    {
        try {
            $propertyType = $this->getPropertyType(Str::camel($key));
            $typeName = $propertyType?->getName();

            return $typeName
                && ! in_array($typeName, self::PRIMITIVE_TYPES)
                && class_exists($typeName)
                && collect(class_implements($typeName))->contains(BackedEnum::class)
                && is_scalar($value);
        } catch (Throwable) {
            return false;
        }
    }

    protected function canPropertyBeCustomObject($key, $value)
    {
        try {
            $propertyType = $this->getPropertyType(Str::camel($key));

            return $propertyType
                && class_exists($propertyType->getName())
                && collect(class_implements($propertyType->getName()))->contains(BaseDTO::class)
                && is_array($value);
        } catch (Throwable) {
            return false;
        }
    }

    protected function getPropertyType(string $key): ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType|null
    {
        $type = (new ReflectionProperty($this, $key))->getType();

        if (! $type || ! method_exists($type, 'getTypes')) {
            $typeName = $type?->getName();

            if ($typeName && in_array($typeName, self::PRIMITIVE_TYPES)) {
                return null;
            }

            return $type ?? null;
        }

        return collect($type->getTypes())
            ->filter(fn ($type) => class_exists($type->getName()))
            ->filter(fn ($type) => ! in_array($type->getName(), self::PRIMITIVE_TYPES))
            ->filter(fn ($type) => str_contains($type->getName(), 'MariusCucuruz\DAMImporter\Integrations\\') || str_contains($type->getName(), 'MariusCucuruz\DAMImporter\\'))
            ->first();
    }

    protected function makePropertyInstance(string $key, array $data): ?self
    {
        /** @var static $type */
        $type = $this->getPropertyType($key)?->getName();

        return $type ? $type::fromArray($data) : null;
    }

    /**
     * This determines the array key name for the property, and if the property has an ArrayItemKeyName attribute, it will use that.
     * Otherwise, it will convert the property name to snake case.
     *
     * @throws \ReflectionException
     */
    protected function getPropertyArrayKeyName(string $key): string
    {
        $reflectionProperty = new ReflectionProperty($this, $key);
        $arrKeyAttribute = $reflectionProperty->getAttributes(ArrayItemKeyName::class);

        if ($arrKeyAttribute && count($arrKeyAttribute) > 0) {
            return $arrKeyAttribute[0]->getArguments()[0];
        }

        return Str::snake($key);
    }

    protected function getPropertyMapArrayTo(string $key): ?string
    {
        $reflectionProperty = new ReflectionProperty($this, $key);
        $arrKeyAttribute = $reflectionProperty->getAttributes(MapArrayItemsTo::class);

        if ($arrKeyAttribute && count($arrKeyAttribute) > 0) {
            return $arrKeyAttribute[0]->getArguments()[0];
        }

        return null;
    }
}
