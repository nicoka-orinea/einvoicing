<?php

namespace Einvoicing\Flux10;

class AmountByRate
{
    /**
     * VAT rate.
     * @var float|string|null
     */
    public $rate = null;

    /**
     * Amount for the rate.
     * @var float|string|null
     */
    public $amount = null;
}
