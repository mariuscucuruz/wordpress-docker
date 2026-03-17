<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces\PackageTypes;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;

interface IsSource
{
    public function redirectToAuth(?string $email = null);

    public function downloadFile(File $file, ?string $rendition = null): ?FileDTO;
    
    public function getTokens(array $tokens = []): TokenDTO;
    
    public function getUser(): ?UserDTO;
}
