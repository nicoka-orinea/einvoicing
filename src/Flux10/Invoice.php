<?php

namespace Einvoicing\Flux10;

use DateTime;

class Invoice
{
    /**
     * Invoice identifier.
     * @var string|null
     */
    public $invoiceId = null;

    /**
     * Invoice issue date.
     * @var DateTime|string|null
     */
    public $issueDate = null;

    /**
     * Invoice type code.
     * @var string|null
     */
    public $typeCode = null;

    /**
     * Seller SIREN (FR only).
     * @var string|null
     */
    public $sellerId = null;

    /**
     * Seller country code.
     * @var string|null
     */
    public $sellerCountry = null;

    /**
     * Seller VAT ID (non-FR).
     * @var string|null
     */
    public $sellerVatId = null;

    /**
     * Buyer SIREN (FR only).
     * @var string|null
     */
    public $buyerId = null;

    /**
     * Buyer country code.
     * @var string|null
     */
    public $buyerCountry = null;

    /**
     * Buyer VAT ID (non-FR).
     * @var string|null
     */
    public $buyerVatId = null;

    /**
     * Amount without VAT.
     * @var float|string|null
     */
    public $taxExclusiveAmount = null;

    /**
     * VAT amount.
     * @var float|string|null
     */
    public $taxAmount = null;

    /**
     * VAT breakdown lines.
     * @var TaxBreakdown[]
     */
    public $taxBreakdown = [];
}
