<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

/**
 * Invoicing framework (cadre de facturation) — TT-28, G1.02.
 *
 * Has no EN 16931 equivalent: presets fill the business process with their own
 * specification URN, so this must be supplied explicitly when reporting an invoice.
 */
enum BusinessProcessCode: string
{
    case GOODS = 'B1';
    case SERVICES = 'S1';
    case MIXED = 'M1';
    case GOODS_ALREADY_PAID = 'B2';
    case SERVICES_ALREADY_PAID = 'S2';
    case MIXED_ALREADY_PAID = 'M2';
    case GOODS_FINAL_AFTER_DEPOSIT = 'B4';
    case SERVICES_FINAL_AFTER_DEPOSIT = 'S4';
    case MIXED_FINAL_AFTER_DEPOSIT = 'M4';
    case SERVICES_SUBCONTRACTOR = 'S5';
    case SERVICES_COCONTRACTOR = 'S6';
    case GOODS_ALREADY_REPORTED = 'B7';
    case SERVICES_ALREADY_REPORTED = 'S7';

    /**
     * Frameworks for a final invoice issued after a deposit, which cannot carry a deposit
     * invoice type — G1.60.
     */
    public function isFinalAfterDeposit(): bool
    {
        return match ($this) {
            self::GOODS_FINAL_AFTER_DEPOSIT,
            self::SERVICES_FINAL_AFTER_DEPOSIT,
            self::MIXED_FINAL_AFTER_DEPOSIT => true,
            default => false,
        };
    }

    /**
     * Transaction categories this framework maps to when converting a Flux 9 to a Flux
     * 10.3 — Annexe 6, sheet "E-REPORTING - Correspondance".
     *
     * A mixed framework maps to both, which is why such an invoice has to be split by
     * line to be reported.
     *
     * @return TransactionCategoryCode[]
     */
    public function transactionCategories(): array
    {
        return match ($this) {
            self::GOODS, self::GOODS_ALREADY_PAID,
            self::GOODS_FINAL_AFTER_DEPOSIT, self::GOODS_ALREADY_REPORTED => [TransactionCategoryCode::GOODS],

            self::MIXED, self::MIXED_ALREADY_PAID,
            self::MIXED_FINAL_AFTER_DEPOSIT => [TransactionCategoryCode::GOODS, TransactionCategoryCode::SERVICES],

            default => [TransactionCategoryCode::SERVICES],
        };
    }
}
