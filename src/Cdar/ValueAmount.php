<?php
namespace Einvoicing\Cdar;

class ValueAmount
{
    private ?float $amount = null;
    private ?string $currencyId = null;

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(?float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrencyId(): ?string
    {
        return $this->currencyId;
    }

    public function setCurrencyId(?string $currencyId): self
    {
        $this->currencyId = $currencyId;
        return $this;
    }
}
