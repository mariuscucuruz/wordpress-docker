<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models;

class BaseModel implements ModelInterface
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getDataProperty($property)
    {
        return $this->data[$property] ?? null;
    }

    public function __get($property)
    {
        return $this->getData()[$property] ?? null;
    }

    public function __set($property, $value)
    {
        $this->data[$property] = $value;
    }
}
