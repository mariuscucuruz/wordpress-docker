<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface CanPaginate
{
    public function paginate(array $request = []): void;

    public function dispatch(array $files, string $importGroupName): void;
}
