<?php

namespace Einvoicing\Flux10;

class TaxBreakdown
{
    /**
     * VAT rate.
     * @var float|string|null
     */
    protected float|string|null $rate = null;

    /**
     * Taxable amount.
     * @var float|string|null
     */
    protected float|string|null $taxableAmount = null;

    /**
     * VAT amount.
     * @var float|string|null
     */
    protected float|string|null $taxAmount = null;

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
     * Get taxable amount.
     */
    public function getTaxableAmount(): float|string|null
    {
        return $this->taxableAmount;
    }

    /**
     * Set taxable amount.
     *
     * @param float|string|null $taxableAmount
     */
    public function setTaxableAmount(float|string|null $taxableAmount): self
    {
        $this->taxableAmount = $taxableAmount;
        return $this;
    }

    /**
     * Get VAT amount.
     */
    public function getTaxAmount(): float|string|null
    {
        return $this->taxAmount;
    }

    /**
     * Set VAT amount.
     *
     * @param float|string|null $taxAmount
     */
    public function setTaxAmount(float|string|null $taxAmount): self
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }
}
