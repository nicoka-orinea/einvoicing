<?php

namespace Einvoicing\Flux10;

use DateTime;
use OutOfBoundsException;
use function array_splice;
use function count;

class TransactionPayment
{
    /**
     * Payment date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $paymentDate = null;

    /**
     * Payment currency code (optional).
     * @var string|null
     */
    protected $currencyCode = null;

    /**
     * Amounts grouped by VAT rate.
     * @var AmountByRate[]
     */
    protected $amountsByRate = [];

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

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    /**
     * Get amounts grouped by rate.
     *
     * @return AmountByRate[]
     */
    public function getAmountsByRate(): array
    {
        return $this->amountsByRate;
    }

    /**
     * Add an amount grouped by rate.
     */
    public function addAmountByRate(AmountByRate $amountByRate): self
    {
        $this->amountsByRate[] = $amountByRate;
        return $this;
    }

    /**
     * Remove an amount grouped by rate.
     *
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
     * Clear all amounts grouped by rate.
     */
    public function clearAmountsByRate(): self
    {
        $this->amountsByRate = [];
        return $this;
    }
}
