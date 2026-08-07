<?php

namespace Einvoicing\Flux10;

/**
 * Line price detail — TG-28.
 *
 * G1.55: when the gross price and the discount are both present, the net price must
 * equal their difference, within a cent.
 */
class Price
{
    /**
     * Net item price, after discount (TT-69).
     * @var float|string|null
     */
    protected float|string|null $priceAmount = null;

    /**
     * Discount on the item price (TT-70).
     * @var float|string|null
     */
    protected float|string|null $allowanceChargeAmount = null;

    /**
     * Gross item price, before discount (TT-71).
     * @var float|string|null
     */
    protected float|string|null $allowanceChargeBaseAmount = null;

    /**
     * Get the net item price (TT-69).
     */
    public function getPriceAmount(): float|string|null
    {
        return $this->priceAmount;
    }

    /**
     * @param float|string|null $priceAmount
     */
    public function setPriceAmount(float|string|null $priceAmount): self
    {
        $this->priceAmount = $priceAmount;
        return $this;
    }

    /**
     * Get the discount on the item price (TT-70).
     */
    public function getAllowanceChargeAmount(): float|string|null
    {
        return $this->allowanceChargeAmount;
    }

    /**
     * @param float|string|null $allowanceChargeAmount
     */
    public function setAllowanceChargeAmount(float|string|null $allowanceChargeAmount): self
    {
        $this->allowanceChargeAmount = $allowanceChargeAmount;
        return $this;
    }

    /**
     * Get the gross item price (TT-71).
     */
    public function getAllowanceChargeBaseAmount(): float|string|null
    {
        return $this->allowanceChargeBaseAmount;
    }

    /**
     * @param float|string|null $allowanceChargeBaseAmount
     */
    public function setAllowanceChargeBaseAmount(float|string|null $allowanceChargeBaseAmount): self
    {
        $this->allowanceChargeBaseAmount = $allowanceChargeBaseAmount;
        return $this;
    }

    /**
     * Whether no price element is filled in.
     */
    public function isEmpty(): bool
    {
        return $this->priceAmount === null
            && $this->allowanceChargeAmount === null
            && $this->allowanceChargeBaseAmount === null;
    }
}
