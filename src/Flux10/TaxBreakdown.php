<?php

namespace Einvoicing\Flux10;

use Einvoicing\Flux10\Enums\VatCategoryCode;
use function is_string;

/**
 * VAT breakdown line — TG-23 for an invoice, TG-32 for aggregated transactions.
 */
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
     * VAT category (TT-56, G2.31).
     *
     * Distinguishes a standard supply from a reverse charge, an intra-community supply
     * or an export — all of which sit at a zero rate and would otherwise be
     * indistinguishable.
     *
     * @var VatCategoryCode|null
     */
    protected ?VatCategoryCode $categoryCode = null;

    /**
     * Plain-text VAT exemption reason (TT-58).
     * @var string|null
     */
    protected ?string $exemptionReason = null;

    /**
     * Coded VAT exemption reason (TT-59), from the EN 16931 code list.
     * @var string|null
     */
    protected ?string $exemptionReasonCode = null;

    /**
     * Get VAT rate.
     */
    public function getRate(): float|string|null
    {
        return $this->rate;
    }

    /**
     * Set VAT rate (G1.24).
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

    /**
     * Get VAT category (TT-56).
     */
    public function getCategoryCode(): ?VatCategoryCode
    {
        return $this->categoryCode;
    }

    /**
     * Set VAT category (TT-56, G2.31).
     *
     * @param VatCategoryCode|string|null $categoryCode `S`, `E`, `AE`, `K`, `G`, `O` or `Z`
     */
    public function setCategoryCode(VatCategoryCode|string|null $categoryCode): self
    {
        $this->categoryCode = is_string($categoryCode)
            ? VatCategoryCode::from($categoryCode)
            : $categoryCode;
        return $this;
    }

    /**
     * Get VAT exemption reason (TT-58).
     */
    public function getExemptionReason(): ?string
    {
        return $this->exemptionReason;
    }

    /**
     * Set VAT exemption reason (TT-58).
     *
     * Required, along with its code, when the category is `E` (G1.40).
     */
    public function setExemptionReason(?string $exemptionReason): self
    {
        $this->exemptionReason = $exemptionReason;
        return $this;
    }

    /**
     * Get coded VAT exemption reason (TT-59).
     */
    public function getExemptionReasonCode(): ?string
    {
        return $this->exemptionReasonCode;
    }

    /**
     * Set coded VAT exemption reason (TT-59).
     *
     * Required, along with the plain-text reason, when the category is `E` (G1.40).
     */
    public function setExemptionReasonCode(?string $exemptionReasonCode): self
    {
        $this->exemptionReasonCode = $exemptionReasonCode;
        return $this;
    }
}
