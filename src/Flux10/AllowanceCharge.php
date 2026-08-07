<?php

namespace Einvoicing\Flux10;

use Einvoicing\Flux10\Enums\VatCategoryCode;
use function is_string;

/**
 * Allowance or charge — TG-20/TG-21 on the invoice, TG-26/TG-27 on a line.
 *
 * The two are the same element distinguished by `@ChargeIndicator`: false for an
 * allowance, true for a charge. The VAT category and rate only exist at header level.
 */
class AllowanceCharge
{
    /**
     * True for a charge, false for an allowance (XSD attribute `ChargeIndicator`).
     * @var bool
     */
    protected bool $charge = false;

    /**
     * Amount excluding VAT (TT-45/TT-48 on the invoice, TT-67/TT-68 on a line).
     * @var float|string|null
     */
    protected float|string|null $amount = null;

    /**
     * VAT category of the allowance or charge (TT-46/TT-49, G2.31).
     * @var VatCategoryCode|null
     */
    protected ?VatCategoryCode $taxCategoryCode = null;

    /**
     * VAT rate of the allowance or charge (TT-47/TT-50, G1.24).
     * @var float|string|null
     */
    protected float|string|null $taxPercent = null;

    /**
     * @param float|string|null $amount
     */
    public function __construct(float|string|null $amount = null, bool $charge = false)
    {
        $this->amount = $amount;
        $this->charge = $charge;
    }

    /**
     * Whether this is a charge rather than an allowance.
     */
    public function isCharge(): bool
    {
        return $this->charge;
    }

    /**
     * Mark this as a charge rather than an allowance.
     */
    public function setCharge(bool $charge): self
    {
        $this->charge = $charge;
        return $this;
    }

    /**
     * Get the amount excluding VAT (TT-45/TT-48, TT-67/TT-68).
     */
    public function getAmount(): float|string|null
    {
        return $this->amount;
    }

    /**
     * @param float|string|null $amount
     */
    public function setAmount(float|string|null $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * Get the VAT category (TT-46/TT-49).
     */
    public function getTaxCategoryCode(): ?VatCategoryCode
    {
        return $this->taxCategoryCode;
    }

    /**
     * @param VatCategoryCode|string|null $taxCategoryCode
     */
    public function setTaxCategoryCode(VatCategoryCode|string|null $taxCategoryCode): self
    {
        $this->taxCategoryCode = is_string($taxCategoryCode)
            ? VatCategoryCode::from($taxCategoryCode)
            : $taxCategoryCode;
        return $this;
    }

    /**
     * Get the VAT rate (TT-47/TT-50).
     */
    public function getTaxPercent(): float|string|null
    {
        return $this->taxPercent;
    }

    /**
     * @param float|string|null $taxPercent
     */
    public function setTaxPercent(float|string|null $taxPercent): self
    {
        $this->taxPercent = $taxPercent;
        return $this;
    }
}
