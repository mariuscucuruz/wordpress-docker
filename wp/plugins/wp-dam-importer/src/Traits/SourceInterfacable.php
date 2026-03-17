<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Interfaces;

trait SourceInterfacable
{
    public function hasFolders(): bool
    {
        return $this instanceof Interfaces\HasFolders;
    }

    public function hasComments(): bool
    {
        return $this instanceof Interfaces\HasComments;
    }

    public function hasRenditions(): bool
    {
        return $this instanceof Interfaces\HasRenditions;
    }

    public function canTraverseDirectoryTree(): bool
    {
        return $this instanceof Interfaces\HasDirectoryTree;
    }

    public function canPaginate(): bool
    {
        return $this instanceof Interfaces\CanPaginate;
    }

    public function hasPerformance(): bool
    {
        return $this instanceof Interfaces\HasPerformance;
    }

    public function hasRemoteServiceId(): bool
    {
        return $this instanceof Interfaces\HasRemoteServiceId;
    }

    public function hasMetadata(): bool
    {
        return $this instanceof Interfaces\HasMetadata;
    }

    public function hasVersions(): bool
    {
        return $this instanceof Interfaces\HasVersions;
    }

    public function isTestable(): bool
    {
        return $this instanceof Interfaces\IsTestable;
    }

    public function hasRateLimit(): bool
    {
        return $this instanceof Interfaces\HasRateLimit;
    }

    public function hasDirectoryTree(): bool
    {
        return $this instanceof Interfaces\HasDirectoryTree;
    }

    public function canRespawn(): bool
    {
        return $this instanceof Interfaces\CanRespawn;
    }

    public function hasDeltaSync(): bool
    {
        return $this instanceof Interfaces\HasDeltaSync;
    }
}
