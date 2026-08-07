<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

/**
 * Role of the declarant, from UNCL 3035 — TT-15, G7.52.
 */
enum IssuerRoleCode: string
{
    case SELLER = 'SE';
    case BUYER = 'BY';

    public function label(): string
    {
        return match ($this) {
            self::SELLER => 'Seller',
            self::BUYER => 'Buyer',
        };
    }
}
