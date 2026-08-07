<?php

namespace Einvoicing\Flux10\Enums;

use function abs;
use function array_map;

/**
 * VAT rates accepted by the PPF — TT-57, TT-86, TT-93, TT-97, G1.24.
 *
 * Expressed as a percentage, not a coefficient. The control is on the value regardless
 * of formatting, so 20, 20.0 and 20.00 are equivalent — hence the float comparison
 * rather than a string-backed enum.
 */
final class VatRate
{
    /** @var float[] */
    private const ALLOWED = [
        0.0, 0.9, 1.05, 1.75, 2.1, 5.5, 7.0, 8.5, 9.2, 9.6, 10.0, 13.0, 19.6, 20.0, 20.6,
    ];

    /** Tolerance absorbing the float representation of rates such as 5.5 or 19.6 */
    private const EPSILON = 0.0001;

    private function __construct()
    {
    }

    /**
     * Whether a rate belongs to the accepted list — G1.24.
     */
    public static function isAllowed(float|string|null $rate): bool
    {
        if ($rate === null || $rate === '' || !is_numeric($rate)) {
            return false;
        }

        foreach (self::ALLOWED as $allowed) {
            if (abs((float) $rate - $allowed) < self::EPSILON) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return float[]
     */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }

    public static function allowedAsString(): string
    {
        return implode(', ', array_map(static fn(float $r) => (string) $r, self::ALLOWED));
    }
}
