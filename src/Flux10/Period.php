<?php

namespace Einvoicing\Flux10;

use DateTime;

class Period
{
    /**
     * Period start date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $startDate = null;

    /**
     * Period end date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $endDate = null;

    /**
     * Get start date.
     */
    public function getStartDate(): DateTime|string|null
    {
        return $this->startDate;
    }

    /**
     * Set start date.
     *
     * @param DateTime|string|null $startDate
     */
    public function setStartDate(DateTime|string|null $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    /**
     * Get end date.
     */
    public function getEndDate(): DateTime|string|null
    {
        return $this->endDate;
    }

    /**
     * Set end date.
     *
     * @param DateTime|string|null $endDate
     */
    public function setEndDate(DateTime|string|null $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }
}
