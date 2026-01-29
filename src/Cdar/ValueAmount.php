<?php
namespace Einvoicing\Cdar;

/**
 * CDAR amount with optional currency.
 */
class ValueAmount
{
    private ?float $amount = null;
    private ?string $currencyId = null;

    /**
     * Get the amount.
     * Business meaning: monetary value.
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    /**
     * Set the amount.
     * Business meaning: monetary value.
     */
    public function setAmount(?float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * Get the currency identifier.
     * Business meaning: ISO currency code for the amount.
     */
    public function getCurrencyId(): ?string
    {
        return $this->currencyId;
    }

    /**
     * Set the currency identifier.
     * Business meaning: ISO currency code for the amount.
     */
    public function setCurrencyId(?string $currencyId): self
    {
        $this->currencyId = $currencyId;
        return $this;
    }
}
