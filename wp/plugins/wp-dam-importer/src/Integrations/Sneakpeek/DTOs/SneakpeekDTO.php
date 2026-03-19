<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\DTOs;

class SneakpeekDTO
{
    public mixed $fps;

    public string $type;

    public mixed $width;

    public mixed $tmp_path;

    public mixed $timestamp;

    public mixed $start_time;

    public int|float $height;

    public string $object_url;

    public string|int $end_time;

    public int $sneakpeekable_id;

    public string|array $remote_path;

    public string $sneakpeekable_type;

    public function __construct(array $thumbnail)
    {
        $this->type = 'sprite';
        $this->sneakpeekable_id = 1;
        $this->fps = $thumbnail['fps'];
        $this->sneakpeekable_type = 'File';
        $this->width = $thumbnail['width'];
        $this->tmp_path = $thumbnail['path'];
        $this->timestamp = $thumbnail['timestamp'];
        $this->start_time = $thumbnail['timestamp'];
        $this->object_url = $thumbnail['object_url'];
        $this->remote_path = $thumbnail['remote_path'];
        $this->end_time = $this->endTime($thumbnail['timestamp'], $thumbnail['fps']);
        $this->height = $this->height($thumbnail['original_resolution'], $thumbnail['width']);
    }

    public function toArray(): array
    {
        return (array) $this;
    }

    private function height(string $original_resolution, float $scale): int
    {
        $matches = [];
        $found = preg_match('/(\d+)\s*x\s*(\d+)/', $original_resolution, $matches);

        if ($found !== 1) {
            return 0;
        }

        $original_width = (float) $matches[1];
        $original_height = (float) $matches[2];

        if ($original_width <= 0) {
            return 0;
        }

        return (int) round($original_height / $original_width * $scale);
    }

    private function endTime(int|float $timestamp, string|int $fps): int
    {
        $fpsValue = $fps;

        if (is_string($fpsValue)) {
            $fpsParts = explode('/', $fpsValue);
            $fpsValue = trim((string) end($fpsParts));
        }

        $fpsNumber = is_numeric($fpsValue) ? (float) $fpsValue : 0.0;

        return (int) round((float) $timestamp + $fpsNumber - 1);
    }
}
