<?php

namespace Einvoicing\Flux10;

use DateTime;

class TransactionPayment
{
    /**
     * Payment date.
     * @var DateTime|string|null
     */
    public $paymentDate = null;

    /**
     * Amounts grouped by VAT rate.
     * @var AmountByRate[]
     */
    public $amountsByRate = [];
}
