<?php

namespace Einvoicing\Flux10;

class TaxBreakdown
{
    /**
     * VAT rate.
     * @var float|string|null
     */
    public $rate = null;

    /**
     * Taxable amount.
     * @var float|string|null
     */
    public $taxableAmount = null;

    /**
     * VAT amount.
     * @var float|string|null
     */
    public $taxAmount = null;
}
