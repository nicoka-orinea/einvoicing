<?php

namespace Einvoicing\Flux10;

use DateTime;

class Period
{
    /**
     * Period start date.
     * @var DateTime|string|null
     */
    public $startDate = null;

    /**
     * Period end date.
     * @var DateTime|string|null
     */
    public $endDate = null;
}
