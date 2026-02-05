<?php

namespace Einvoicing\Flux10;

class AmountByRate
{
    /**
     * VAT rate.
     * @var float|string|null
     */
    protected float|string|null $rate = null;

    /**
     * Amount for the rate.
     * @var float|string|null
     */
    protected float|string|null $amount = null;

    /**
     * Get VAT rate.
     */
    public function getRate(): float|string|null
    {
        return $this->rate;
    }

    /**
     * Set VAT rate.
     *
     * @param float|string|null $rate
     */
    public function setRate(float|string|null $rate): self
    {
        $this->rate = $rate;
        return $this;
    }

    /**
     * Get amount.
     */
    public function getAmount(): float|string|null
    {
        return $this->amount;
    }

    /**
     * Set amount.
     *
     * @param float|string|null $amount
     */
    public function setAmount(float|string|null $amount): self
    {
        $this->amount = $amount;
        return $this;
    }
}
