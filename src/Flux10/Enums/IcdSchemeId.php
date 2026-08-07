<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

use function in_array;
use function strlen;
use function strtoupper;

/**
 * ISO 6523 (ICD) identifier schemes accepted for the Seller and the Buyer — TT-33-1,
 * TT-37, G2.19.
 *
 * The scheme dictates what the identifier holds, which is why it cannot be copied from
 * an EN 16931 party: `0002` is a SIREN, `0223` an intra-community VAT number, `0227` a
 * country code followed by the first 16 characters of the company name.
 */
enum IcdSchemeId: string
{
    case SIREN = '0002';
    case EU_OUTSIDE_FRANCE = '0223';
    case OUTSIDE_EU = '0227';
    case RIDET = '0228';
    case TAHITI = '0229';

    /**
     * Member states other than France, whose operators are identified by their
     * intra-community VAT number.
     */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'GR', 'HR',
        'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Resolve the scheme expected for a country — G2.19.
     *
     * New Caledonia and French Polynesia have their own registers (RIDET, TAHITI); the
     * rest of the French territory uses the SIREN.
     */
    public static function fromCountry(?string $countryCode): self
    {
        $country = strtoupper((string) $countryCode);

        return match (true) {
            $country === 'FR' => self::SIREN,
            $country === 'NC' => self::RIDET,
            $country === 'PF' => self::TAHITI,
            in_array($country, self::EU_COUNTRIES, true) => self::EU_OUTSIDE_FRANCE,
            default => self::OUTSIDE_EU,
        };
    }

    /**
     * Allowed identifier length, as [min, max] — G2.19.
     *
     * @return array{0:int,1:int}
     */
    public function expectedLength(): array
    {
        return match ($this) {
            self::SIREN => [9, 9],
            self::TAHITI => [9, 9],
            self::RIDET => [9, 10],
            self::EU_OUTSIDE_FRANCE, self::OUTSIDE_EU => [1, 18],
        };
    }

    /**
     * Whether an identifier is plausible for this scheme — G2.19.
     */
    public function accepts(?string $identifier): bool
    {
        if ($identifier === null || $identifier === '') {
            return false;
        }

        [$min, $max] = $this->expectedLength();
        $length = strlen($identifier);
        if ($length < $min || $length > $max) {
            return false;
        }

        // SIREN and TAHITI identifiers are numeric registers
        if ($this === self::SIREN || $this === self::TAHITI) {
            return preg_match('/^\d+$/', $identifier) === 1;
        }

        return true;
    }

    /**
     * Whether the scheme implies a VAT identifier must be supplied — G2.33.
     */
    public function requiresVatIdentifier(): bool
    {
        return $this === self::SIREN || $this === self::EU_OUTSIDE_FRANCE;
    }

    public function label(): string
    {
        return match ($this) {
            self::SIREN => 'SIREN',
            self::EU_OUTSIDE_FRANCE => 'EU outside France',
            self::OUTSIDE_EU => 'Outside EU',
            self::RIDET => 'RIDET',
            self::TAHITI => 'TAHITI',
        };
    }
}
