<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

/**
 * Transaction categories for aggregated B2C reporting — TT-81, G1.68.
 */
enum TransactionCategoryCode: string
{
    case GOODS = 'TLB1';
    case SERVICES = 'TPS1';
    case NOT_SUBJECT_TO_VAT = 'TNT1';
    case MARGIN_SCHEME = 'TMA1';

    public function label(): string
    {
        return match ($this) {
            self::GOODS => 'Supplies of goods subject to VAT',
            self::SERVICES => 'Supplies of services subject to VAT',
            self::NOT_SUBJECT_TO_VAT => 'Supplies not subject to VAT in France',
            self::MARGIN_SCHEME => 'Operations under a VAT margin scheme',
        };
    }
}
