<?php

namespace Einvoicing\Flux10;

use DateTime;

class Transaction
{
    /**
     * Transaction date.
     * @var DateTime|string|null
     */
    public $date = null;

    /**
     * Category code (TLB1, TPS1, TNT1, TMA1).
     * @var string|null
     */
    public $categoryCode = null;

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

    /**
     * Transaction count (optional).
     * @var int|null
     */
    public $transactionCount = null;

    /**
     * VAT due date type code (optional).
     * @var string|null
     */
    public $taxDueDateTypeCode = null;
}
