<?php

namespace Einvoicing\Flux10;

use DateTime;

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
     * Payment amount.
     * @var float|string|null
     */
    protected float|string|null $amount = null;

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
}
