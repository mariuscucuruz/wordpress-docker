<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum MetaType: string
{
    case request = 'request';

    case extra = 'extra';

    case select = 'select';

    case backfill_remote_page_id_dispatched = 'backfill_remote_page_id_dispatched';
}
