<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case NZD = 'NZD';
    case JPY = 'JPY';
    case CNY = 'CNY';
    case HKD = 'HKD';
    case SGD = 'SGD';
    case INR = 'INR';
    case CHF = 'CHF';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';
    case ZAR = 'ZAR';
    case AED = 'AED';
    case SAR = 'SAR';
    case BRL = 'BRL';
    case MXN = 'MXN';
    case ARS = 'ARS';
    case CLP = 'CLP';
    case COP = 'COP';
    case THB = 'THB';
    case PHP = 'PHP';
    case VND = 'VND';

    case UNKNOWN = 'UNKNOWN';
}
