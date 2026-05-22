<?php

namespace Einvoicing\Flux10;

use DateTime;
use OutOfBoundsException;
use function array_splice;
use function count;

class InvoicePayment
{
    /**
     * Related invoice identifier.
     * @var string|null
     */
    protected $invoiceId = null;

    /**
     * Payment date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $paymentDate = null;

    /**
     * Payment amount (legacy shortcut: exported as a single `SubTotals`).
     * @var float|string|null
     */
    protected float|string|null $amount = null;

    /**
     * Related invoice issue date (required by payment.xsd).
     * @var DateTime|string|null
     */
    protected DateTime|string|null $issueDate = null;

    /**
     * Payment currency code (optional).
     * @var string|null
     */
    protected $currencyCode = null;

    /**
     * Amounts grouped by VAT rate (exported as `SubTotals`).
     * @var AmountByRate[]
     */
    protected $amountsByRate = [];

    /**
     * Get related invoice ID.
     */
    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    /**
     * Set related invoice ID.
     */
    public function setInvoiceId(?string $invoiceId): self
    {
        $this->invoiceId = $invoiceId;
        return $this;
    }

    /**
     * Get payment date.
     */
    public function getPaymentDate(): DateTime|string|null
    {
        return $this->paymentDate;
    }

    /**
     * Set payment date.
     *
     * @param DateTime|string|null $paymentDate
     */
    public function setPaymentDate(DateTime|string|null $paymentDate): self
    {
        $this->paymentDate = $paymentDate;
        return $this;
    }

    /**
     * Get related invoice issue date.
     */
    public function getIssueDate(): DateTime|string|null
    {
        return $this->issueDate;
    }

    /**
     * @param DateTime|string|null $issueDate
     */
    public function setIssueDate(DateTime|string|null $issueDate): self
    {
        $this->issueDate = $issueDate;
        return $this;
    }

    /**
     * Get payment currency code.
     */
    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    /**
     * Set payment currency code.
     */
    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    /**
     * Get payment amount.
     */
    public function getAmount(): float|string|null
    {
        return $this->amount;
    }

    /**
     * Set payment amount.
     *
     * @param float|string|null $amount
     */
    public function setAmount(float|string|null $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * @return AmountByRate[]
     */
    public function getAmountsByRate(): array
    {
        return $this->amountsByRate;
    }

    /**
     * Add a subtotal amount grouped by rate.
     */
    public function addAmountByRate(AmountByRate $amountByRate): self
    {
        $this->amountsByRate[] = $amountByRate;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeAmountByRate(int $index): self
    {
        if ($index < 0 || $index >= count($this->amountsByRate)) {
            throw new OutOfBoundsException('Could not find amountByRate by index');
        }

        array_splice($this->amountsByRate, $index, 1);
        return $this;
    }

    /**
     * Clear subtotal amounts grouped by rate.
     */
    public function clearAmountsByRate(): self
    {
        $this->amountsByRate = [];
        return $this;
    }
}
