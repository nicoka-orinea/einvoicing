<?php

namespace Einvoicing\Flux10;

use OutOfBoundsException;
use function array_splice;
use function count;

class Report
{
    /**
     * Report identifier.
     * @var string|null
     */
    protected $reportId = null;

    /**
     * Report name.
     * @var string|null
     */
    protected $reportName = null;

    /**
     * Transmission type (IN, RE).
     * @var string
     */
    protected $transmissionType = 'IN';

    /**
     * Sender party.
     * @var Party|null
     */
    protected $sender = null;

    /**
     * Issuer party with role.
     * @var Issuer|null
     */
    protected $issuer = null;

    /**
     * Reporting period.
     * @var Period|null
     */
    protected $period = null;

    /**
     * Flux 10.1 invoices.
     * @var Invoice[]
     */
    protected $invoices = [];

    /**
     * Flux 10.2 invoice payments.
     * @var InvoicePayment[]
     */
    protected $invoicePayments = [];

    /**
     * Flux 10.3 transactions.
     * @var Transaction[]
     */
    protected $transactions = [];

    /**
     * Flux 10.4 transaction payments.
     * @var TransactionPayment[]
     */
    protected $transactionPayments = [];

    public function getReportId(): ?string
    {
        return $this->reportId;
    }

    public function setReportId(?string $reportId): self
    {
        $this->reportId = $reportId;
        return $this;
    }

    public function getReportName(): ?string
    {
        return $this->reportName;
    }

    public function setReportName(?string $reportName): self
    {
        $this->reportName = $reportName;
        return $this;
    }

    public function getTransmissionType(): string
    {
        return $this->transmissionType;
    }

    public function setTransmissionType(string $transmissionType): self
    {
        $this->transmissionType = $transmissionType;
        return $this;
    }

    public function getSender(): ?Party
    {
        return $this->sender;
    }

    public function setSender(?Party $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    public function getIssuer(): ?Issuer
    {
        return $this->issuer;
    }

    public function setIssuer(?Issuer $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    public function getPeriod(): ?Period
    {
        return $this->period;
    }

    public function setPeriod(?Period $period): self
    {
        $this->period = $period;
        return $this;
    }

    /**
     * @return Invoice[]
     */
    public function getInvoices(): array
    {
        return $this->invoices;
    }

    public function addInvoice(Invoice $invoice): self
    {
        $this->invoices[] = $invoice;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeInvoice(int $index): self
    {
        if ($index < 0 || $index >= count($this->invoices)) {
            throw new OutOfBoundsException('Could not find invoice by index');
        }
        array_splice($this->invoices, $index, 1);
        return $this;
    }

    public function clearInvoices(): self
    {
        $this->invoices = [];
        return $this;
    }

    /**
     * @return InvoicePayment[]
     */
    public function getInvoicePayments(): array
    {
        return $this->invoicePayments;
    }

    public function addInvoicePayment(InvoicePayment $payment): self
    {
        $this->invoicePayments[] = $payment;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeInvoicePayment(int $index): self
    {
        if ($index < 0 || $index >= count($this->invoicePayments)) {
            throw new OutOfBoundsException('Could not find invoicePayment by index');
        }
        array_splice($this->invoicePayments, $index, 1);
        return $this;
    }

    public function clearInvoicePayments(): self
    {
        $this->invoicePayments = [];
        return $this;
    }

    /**
     * @return Transaction[]
     */
    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): self
    {
        $this->transactions[] = $transaction;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeTransaction(int $index): self
    {
        if ($index < 0 || $index >= count($this->transactions)) {
            throw new OutOfBoundsException('Could not find transaction by index');
        }
        array_splice($this->transactions, $index, 1);
        return $this;
    }

    public function clearTransactions(): self
    {
        $this->transactions = [];
        return $this;
    }

    /**
     * @return TransactionPayment[]
     */
    public function getTransactionPayments(): array
    {
        return $this->transactionPayments;
    }

    public function addTransactionPayment(TransactionPayment $payment): self
    {
        $this->transactionPayments[] = $payment;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeTransactionPayment(int $index): self
    {
        if ($index < 0 || $index >= count($this->transactionPayments)) {
            throw new OutOfBoundsException('Could not find transactionPayment by index');
        }
        array_splice($this->transactionPayments, $index, 1);
        return $this;
    }

    public function clearTransactionPayments(): self
    {
        $this->transactionPayments = [];
        return $this;
    }
}
