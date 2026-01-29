<?php
namespace Einvoicing\Cdar\Enums;

/**
 * CDAR referenced document type codes (UNTDID 1001).
 */
enum DocumentTypeCode: int
{
    case REQUEST_FOR_PAYMENT = 71;
    case DEBIT_NOTE_RELATED_TO_GOODS_OR_SERVICES = 80;
    case CREDIT_NOTE_RELATED_TO_GOODS_OR_SERVICES = 81;
    case METERED_SERVICES_INVOICE = 82;
    case CREDIT_NOTE_RELATED_TO_FINANCIAL_ADJUSTMENTS = 83;
    case DEBIT_NOTE_RELATED_TO_FINANCIAL_ADJUSTMENTS = 84;
    case TAX_NOTIFICATION = 102;
    case FINAL_PAYMENT_REQUEST_BASED_ON_COMPLETION_OF_WORK = 218;
    case PAYMENT_REQUEST_FOR_COMPLETED_UNITS = 219;
    case COMMERCIAL_INVOICE_WHICH_INCLUDES_A_PACKING_LIST = 331;
    case COMMERCIAL_INVOICE = 380;
    case CREDIT_NOTE = 381;
    case COMMISSION_NOTE = 382;
    case DEBIT_NOTE = 383;
    case PREPAYMENT_INVOICE = 386;
    case TAX_INVOICE = 388;
    case FACTORED_INVOICE = 393;
    case CONSIGNMENT_INVOICE = 395;
    case FACTORED_CREDIT_NOTE = 396;
    case FORWARDERS_CREDIT_NOTE = 532;
    case FORWARDERS_INVOICE_DISCREPANCY_REPORT = 553;
    case INSURERS_INVOICE = 575;
    case FORWARDERS_INVOICE = 623;
    case FREIGHT_INVOICE = 780;
    case CLAIM_NOTIFICATION = 817;
    case CONSULAR_INVOICE = 870;
    case PARTIAL_CONSTRUCTION_INVOICE = 875;
    case PARTIAL_FINAL_CONSTRUCTION_INVOICE = 876;
    case FINAL_CONSTRUCTION_INVOICE = 877;

    /**
     * Get an English label for UI/logs.
     * Business meaning: human-readable document type.
     */
    public function label(): string
    {
        return match ($this) {
            self::REQUEST_FOR_PAYMENT => 'Request for payment',
            self::DEBIT_NOTE_RELATED_TO_GOODS_OR_SERVICES => 'Debit note related to goods or services',
            self::CREDIT_NOTE_RELATED_TO_GOODS_OR_SERVICES => 'Credit note related to goods or services',
            self::METERED_SERVICES_INVOICE => 'Metered services invoice',
            self::CREDIT_NOTE_RELATED_TO_FINANCIAL_ADJUSTMENTS => 'Credit note related to financial adjustments',
            self::DEBIT_NOTE_RELATED_TO_FINANCIAL_ADJUSTMENTS => 'Debit note related to financial adjustments',
            self::TAX_NOTIFICATION => 'Tax notification',
            self::FINAL_PAYMENT_REQUEST_BASED_ON_COMPLETION_OF_WORK => 'Final payment request based on completion of work',
            self::PAYMENT_REQUEST_FOR_COMPLETED_UNITS => 'Payment request for completed units',
            self::COMMERCIAL_INVOICE_WHICH_INCLUDES_A_PACKING_LIST => 'Commercial invoice which includes a packing list',
            self::COMMERCIAL_INVOICE => 'Commercial invoice',
            self::CREDIT_NOTE => 'Credit note',
            self::COMMISSION_NOTE => 'Commission note',
            self::DEBIT_NOTE => 'Debit note',
            self::PREPAYMENT_INVOICE => 'Prepayment invoice',
            self::TAX_INVOICE => 'Tax invoice',
            self::FACTORED_INVOICE => 'Factored invoice',
            self::CONSIGNMENT_INVOICE => 'Consignment invoice',
            self::FACTORED_CREDIT_NOTE => 'Factored credit note',
            self::FORWARDERS_CREDIT_NOTE => "Forwarders credit note",
            self::FORWARDERS_INVOICE_DISCREPANCY_REPORT => 'Forwarders invoice discrepancy report',
            self::INSURERS_INVOICE => "Insurers invoice",
            self::FORWARDERS_INVOICE => "Forwarders invoice",
            self::FREIGHT_INVOICE => 'Freight invoice',
            self::CLAIM_NOTIFICATION => 'Claim notification',
            self::CONSULAR_INVOICE => 'Consular invoice',
            self::PARTIAL_CONSTRUCTION_INVOICE => 'Partial construction invoice',
            self::PARTIAL_FINAL_CONSTRUCTION_INVOICE => 'Partial final construction invoice',
            self::FINAL_CONSTRUCTION_INVOICE => 'Final construction invoice',
        };
    }
}
