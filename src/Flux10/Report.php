<?php

namespace Einvoicing\Flux10;

use DateTimeInterface;
use Einvoicing\Flux10\Enums\TransmissionTypeCode;
use Einvoicing\Flux10\Traits\ReportValidationTrait;
use OutOfBoundsException;
use function array_splice;
use function count;

class Report
{
    use ReportValidationTrait;

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
     * Transmission type (TT-4, G8.01).
     * @var TransmissionTypeCode
     */
    protected TransmissionTypeCode $transmissionType = TransmissionTypeCode::INITIAL;

    /**
     * Transmission creation timestamp (TT-3).
     *
     * Left null, the writer stamps the export time. Set it explicitly to replay a
     * transmission or to satisfy G7.43, which requires this timestamp to fall after the
     * end of the declared period.
     *
     * @var DateTimeInterface|string|null
     */
    protected DateTimeInterface|string|null $issueDateTime = null;

    /**
     * Emitting accredited platform (TG-3).
     * @var Sender|null
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

    /**
     * Get report identifier.
     */
    public function getReportId(): ?string
    {
        return $this->reportId;
    }

    /**
     * Set report identifier.
     */
    public function setReportId(?string $reportId): self
    {
        $this->reportId = $reportId;
        return $this;
    }

    /**
     * Get report name.
     */
    public function getReportName(): ?string
    {
        return $this->reportName;
    }

    /**
     * Set report name.
     */
    public function setReportName(?string $reportName): self
    {
        $this->reportName = $reportName;
        return $this;
    }

    /**
     * Get transmission type (TT-4).
     */
    public function getTransmissionType(): TransmissionTypeCode
    {
        return $this->transmissionType;
    }

    /**
     * Set transmission type (TT-4, G8.01).
     *
     * @param TransmissionTypeCode|string $transmissionType `IN` or `RE` when a string
     */
    public function setTransmissionType(TransmissionTypeCode|string $transmissionType): self
    {
        $this->transmissionType = is_string($transmissionType)
            ? TransmissionTypeCode::from($transmissionType)
            : $transmissionType;
        return $this;
    }

    /**
     * Get transmission creation timestamp (TT-3).
     */
    public function getIssueDateTime(): DateTimeInterface|string|null
    {
        return $this->issueDateTime;
    }

    /**
     * Set transmission creation timestamp (TT-3).
     *
     * @param DateTimeInterface|string|null $issueDateTime `AAAAMMJJHHMMSS` when a string
     */
    public function setIssueDateTime(DateTimeInterface|string|null $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    /**
     * Get emitting accredited platform (TG-3).
     */
    public function getSender(): ?Sender
    {
        return $this->sender;
    }

    /**
     * Set emitting accredited platform (TG-3).
     */
    public function setSender(?Sender $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    /**
     * Get issuer party.
     */
    public function getIssuer(): ?Issuer
    {
        return $this->issuer;
    }

    /**
     * Set issuer party.
     */
    public function setIssuer(?Issuer $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * Get reporting period.
     */
    public function getPeriod(): ?Period
    {
        return $this->period;
    }

    /**
     * Set reporting period.
     */
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

    /**
     * Add a flux invoice entry.
     */
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

    /**
     * Clear all flux invoice entries.
     */
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

    /**
     * Add an invoice payment entry.
     */
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

    /**
     * Clear all invoice payment entries.
     */
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

    /**
     * Add a transaction entry.
     */
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

    /**
     * Clear all transaction entries.
     */
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

    /**
     * Add a transaction payment entry.
     */
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

    /**
     * Clear all transaction payment entries.
     */
    public function clearTransactionPayments(): self
    {
        $this->transactionPayments = [];
        return $this;
    }
}
