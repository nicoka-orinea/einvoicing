<?php

namespace Einvoicing\Flux10;

use DateTime;

class InvoicePayment
{
    /**
     * Related invoice identifier.
     * @var string|null
     */
    public $invoiceId = null;

    /**
     * Payment date.
     * @var DateTime|string|null
     */
    public $paymentDate = null;

    /**
     * Payment amount.
     * @var float|string|null
     */
    public $amount = null;
}
