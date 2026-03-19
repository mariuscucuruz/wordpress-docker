<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Brandfolder\Schema;

final class BrandfolderSchema
{
    public const array ATTACHMENT_FIELDS = [
        'key',
        'filename',
        'extension',
        'mimetype',
        'size',
        'width',
        'height',
        'url',
        'cdn_url',
        'thumbnail_url',
        'updated_at',
        'created_at',
        'tag_names',
        'metadata',
        'custom_fields',
    ];

    public const array ASSET_FIELDS = [
        'name',
        'type',
        'updated_at',
        'created_at',
        'attachment_count',
        'availability',
        'content_automation_editor_link',
        'custom_fields',
        'description',
        'html',
        'printui_editor_link',
        'prioritized_custom_fields',
        'storyteq_editor_link',
        'tag_names',
        'thumbnail_url',
        'view_only',
    ];
}
