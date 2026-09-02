<?php

namespace Einvoicing\Flux10;

use DateTime;
use Einvoicing\Flux10\Enums\TransactionCategoryCode;
use OutOfBoundsException;
use function array_splice;
use function count;

class Transaction
{
    /**
     * Transaction date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $date = null;

    /**
     * Transactions currency code.
     * @var string|null
     */
    protected $currencyCode = null;

    /**
     * Transaction category (TT-81, G1.68).
     * @var TransactionCategoryCode|null
     */
    protected ?TransactionCategoryCode $categoryCode = null;

    /**
     * Amount without VAT.
     * @var float|string|null
     */
    protected float|string|null $taxExclusiveAmount = null;

    /**
     * VAT amount, in the transactions currency.
     * @var float|string|null
     */
    protected float|string|null $taxAmount = null;

    /**
     * Total VAT amount converted to euros (TT-83).
     *
     * Required when the transactions currency is not EUR (G6.23). Conversion is a
     * business decision and is never performed by the library.
     *
     * @var float|string|null
     */
    protected float|string|null $vatAmountEur = null;

    /**
     * VAT breakdown lines.
     * @var TaxBreakdown[]
     */
    protected $taxBreakdown = [];

    /**
     * Transaction count (optional).
     * @var int|null
     */
    protected $transactionCount = null;

    /**
     * VAT due date type code (optional).
     * @var string|null
     */
    protected $taxDueDateTypeCode = null;

    /**
     * Get transaction date.
     */
    public function getDate(): DateTime|string|null
    {
        return $this->date;
    }

    /**
     * Set transaction date.
     *
     * @param DateTime|string|null $date
     */
    public function setDate(DateTime|string|null $date): self
    {
        $this->date = $date;
        return $this;
    }

    /**
     * Get transaction currency code.
     */
    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    /**
     * Set transaction currency code.
     */
    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    /**
     * Get transaction category (TT-81).
     */
    public function getCategoryCode(): ?TransactionCategoryCode
    {
        return $this->categoryCode;
    }

    /**
     * Set transaction category (TT-81, G1.68).
     *
     * @param TransactionCategoryCode|string|null $categoryCode `TLB1`, `TPS1`, `TNT1` or `TMA1`
     */
    public function setCategoryCode(TransactionCategoryCode|string|null $categoryCode): self
    {
        $this->categoryCode = is_string($categoryCode)
            ? TransactionCategoryCode::from($categoryCode)
            : $categoryCode;
        return $this;
    }

    /**
     * Get amount without VAT.
     */
    public function getTaxExclusiveAmount(): float|string|null
    {
        return $this->taxExclusiveAmount;
    }

    /**
     * Set amount without VAT.
     *
     * @param float|string|null $taxExclusiveAmount
     */
    public function setTaxExclusiveAmount(float|string|null $taxExclusiveAmount): self
    {
        $this->taxExclusiveAmount = $taxExclusiveAmount;
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
     * Get total VAT amount in euros (TT-83).
     */
    public function getVatAmountEur(): float|string|null
    {
        return $this->vatAmountEur;
    }

    /**
     * Set total VAT amount in euros (TT-83, G6.23).
     *
     * Required when the transactions currency is not EUR.
     *
     * @param float|string|null $vatAmountEur
     */
    public function setVatAmountEur(float|string|null $vatAmountEur): self
    {
        $this->vatAmountEur = $vatAmountEur;
        return $this;
    }

    /**
     * Get VAT breakdown.
     *
     * @return TaxBreakdown[]
     */
    public function getTaxBreakdown(): array
    {
        return $this->taxBreakdown;
    }

    /**
     * Add VAT breakdown item.
     */
    public function addTaxBreakdownItem(TaxBreakdown $item): self
    {
        $this->taxBreakdown[] = $item;
        return $this;
    }

    /**
     * Remove VAT breakdown item.
     *
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeTaxBreakdownItem(int $index): self
    {
        if ($index < 0 || $index >= count($this->taxBreakdown)) {
            throw new OutOfBoundsException('Could not find taxBreakdown item by index');
        }

        array_splice($this->taxBreakdown, $index, 1);
        return $this;
    }

    /**
     * Clear VAT breakdown.
     */
    public function clearTaxBreakdown(): self
    {
        $this->taxBreakdown = [];
        return $this;
    }

    /**
     * Get transaction count.
     */
    public function getTransactionCount(): ?int
    {
        return $this->transactionCount;
    }

    /**
     * Set transaction count.
     */
    public function setTransactionCount(?int $transactionCount): self
    {
        $this->transactionCount = $transactionCount;
        return $this;
    }

    /**
     * Get VAT due date type code.
     */
    public function getTaxDueDateTypeCode(): ?string
    {
        return $this->taxDueDateTypeCode;
    }

    /**
     * Set VAT due date type code.
     */
    public function setTaxDueDateTypeCode(?string $taxDueDateTypeCode): self
    {
        $this->taxDueDateTypeCode = $taxDueDateTypeCode;
        return $this;
    }
}
