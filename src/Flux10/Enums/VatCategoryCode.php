<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

/**
 * VAT category codes accepted by the PPF, from UNTDID 5305 — TT-56, G2.31.
 *
 * The codes L (Canary Islands) and M (Ceuta and Melilla) exist in the standard but are
 * not relevant in France and are rejected.
 */
enum VatCategoryCode: string
{
    case STANDARD = 'S';
    case EXEMPT = 'E';
    case REVERSE_CHARGE = 'AE';
    case INTRA_COMMUNITY = 'K';
    case EXPORT = 'G';
    case OUT_OF_SCOPE = 'O';
    case ZERO_RATED = 'Z';

    /**
     * Whether an exemption reason and its code are required — G1.40.
     */
    public function requiresExemptionReason(): bool
    {
        return $this === self::EXEMPT;
    }

    /**
     * Get an English label for UI and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard VAT rate',
            self::EXEMPT => 'Exempt from VAT',
            self::REVERSE_CHARGE => 'VAT reverse charge',
            self::INTRA_COMMUNITY => 'Exempt — intra-community supply',
            self::EXPORT => 'Exempt — export outside the EU',
            self::OUT_OF_SCOPE => 'Outside the scope of VAT',
            self::ZERO_RATED => 'Zero-rated',
        };
    }
}
