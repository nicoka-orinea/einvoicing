<?php

namespace Einvoicing\Flux10;

use DateTimeInterface;

class Period
{
    /**
     * Period start date.
     * @var DateTimeInterface|string|null
     */
    protected DateTimeInterface|string|null $startDate = null;

    /**
     * Period end date.
     * @var DateTimeInterface|string|null
     */
    protected DateTimeInterface|string|null $endDate = null;

    /**
     * Get start date.
     */
    public function getStartDate(): DateTimeInterface|string|null
    {
        return $this->startDate;
    }

    /**
     * Set start date.
     *
     * @param DateTimeInterface|string|null $startDate
     */
    public function setStartDate(DateTimeInterface|string|null $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    /**
     * Get end date.
     */
    public function getEndDate(): DateTimeInterface|string|null
    {
        return $this->endDate;
    }

    /**
     * Set end date.
     *
     * @param DateTimeInterface|string|null $endDate
     */
    public function setEndDate(DateTimeInterface|string|null $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }
}
