<?php

namespace Einvoicing\Flux10\Enums;

use function in_array;

/**
 * Invoice types accepted in Flux 10, a subset of UNTDID 1001 — TT-21, G1.01.
 *
 * Kept as a list rather than an enum: the type is already carried as an int by
 * `Einvoicing\Invoice` and the standard defines many more values, none of which may be
 * used here.
 */
final class InvoiceTypeCode
{
    /** Commercial, self-billed, factored and self-billed factored invoices */
    public const SIMPLE = [380, 389, 393, 501];

    /** Deposit invoices */
    public const DEPOSIT = [386, 500];

    /** Corrective invoices */
    public const CORRECTIVE = [384, 471, 472, 473];

    /** Credit notes */
    public const CREDIT_NOTE = [261, 381, 396, 502, 503];

    private function __construct()
    {
    }

    /**
     * @return int[]
     */
    public static function allowed(): array
    {
        return [...self::SIMPLE, ...self::DEPOSIT, ...self::CORRECTIVE, ...self::CREDIT_NOTE];
    }

    public static function isAllowed(int|string|null $type): bool
    {
        return $type !== null && in_array((int) $type, self::allowed(), true);
    }

    /**
     * A corrective invoice references exactly one earlier invoice — G1.32.
     */
    public static function isCorrective(int|string|null $type): bool
    {
        return $type !== null && in_array((int) $type, self::CORRECTIVE, true);
    }

    /**
     * A credit note references at least one earlier invoice, in the header or per line — G1.32.
     */
    public static function isCreditNote(int|string|null $type): bool
    {
        return $type !== null && in_array((int) $type, self::CREDIT_NOTE, true);
    }

    /**
     * Deposit-related types, incompatible with a "final after deposit" framework — G1.60.
     */
    public static function isDepositRelated(int|string|null $type): bool
    {
        return $type !== null && in_array((int) $type, [...self::DEPOSIT, 503], true);
    }
}
