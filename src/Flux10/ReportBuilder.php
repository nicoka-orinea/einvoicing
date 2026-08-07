<?php

namespace Einvoicing\Flux10;

use DateTimeInterface;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\Enums\BusinessProcessCode;
use Einvoicing\Flux10\Enums\TransmissionTypeCode;
use function is_string;

/**
 * Assembles a Flux 10 report, requiring what the specification requires.
 *
 * A transmission carries data no invoice knows about: the accredited platform emitting
 * it, the declarant, the declared period, and a transmission identifier unique per
 * period and declarant (G8.05). Inferring any of it from the documents being reported
 * produces a plausible but rejected file, so this builder asks for it up front.
 *
 * ```php
 * $report = (new ReportBuilder())
 *     ->setSender((new Sender())->setMatricule('PA01')->setName('My Platform'))
 *     ->setIssuer((new Issuer())->setSiren('123456789')->setName('My Company')->setRoleCode('SE'))
 *     ->setTransmissionId('REPORT-2026-01')
 *     ->setPeriod(new DateTime('2026-01-01'), new DateTime('2026-01-31'))
 *     ->addInvoices($invoices, BusinessProcessCode::SERVICES)
 *     ->build();
 * ```
 */
class ReportBuilder
{
    private ?Sender $sender = null;
    private ?Issuer $issuer = null;
    private ?string $transmissionId = null;
    private ?string $transmissionName = null;
    private TransmissionTypeCode $transmissionType = TransmissionTypeCode::INITIAL;
    private DateTimeInterface|string|null $issueDateTime = null;
    private ?Period $period = null;

    /** @var Invoice[] */
    private array $invoices = [];

    /** @var Transaction[] */
    private array $transactions = [];

    /** @var InvoicePayment[] */
    private array $invoicePayments = [];

    /** @var TransactionPayment[] */
    private array $transactionPayments = [];

    /**
     * Set the accredited platform emitting the transmission — TG-3, G6.22.
     */
    public function setSender(Sender $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    /**
     * Set the declarant — TG-5, G6.26.
     */
    public function setIssuer(Issuer $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * Set the transmission identifier — TT-1.
     *
     * Must be unique per period and per declarant: the PPF runs a blocking duplicate
     * check on it (G8.05). This is not the invoice number.
     */
    public function setTransmissionId(string $transmissionId): self
    {
        $this->transmissionId = $transmissionId;
        return $this;
    }

    /**
     * Set the optional flow name — TT-2.
     */
    public function setTransmissionName(?string $transmissionName): self
    {
        $this->transmissionName = $transmissionName;
        return $this;
    }

    /**
     * Set the transmission type — TT-4, G8.01.
     *
     * @param TransmissionTypeCode|string $transmissionType `IN` or `RE`
     */
    public function setTransmissionType(TransmissionTypeCode|string $transmissionType): self
    {
        $this->transmissionType = is_string($transmissionType)
            ? TransmissionTypeCode::from($transmissionType)
            : $transmissionType;
        return $this;
    }

    /**
     * Set the transmission timestamp — TT-3.
     *
     * Defaults to export time. G7.43 requires it to fall after the end of the period.
     *
     * @param DateTimeInterface|string|null $issueDateTime
     */
    public function setIssueDateTime(DateTimeInterface|string|null $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    /**
     * Set the declared period — TG-7/TG-33.
     *
     * The end must be strictly after the start (G6.25), which is why it cannot be
     * derived from a single day of invoices.
     *
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     */
    public function setPeriod(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        $this->period = (new Period())->setStartDate($start)->setEndDate($end);
        return $this;
    }

    /**
     * Add an invoice to report — TG-8, sub-flux 10.1.
     */
    public function addInvoice(Invoice $invoice): self
    {
        $this->invoices[] = $invoice;
        return $this;
    }

    /**
     * Add several invoices, optionally stamping the invoicing framework on each.
     *
     * @param Invoice[]                       $invoices
     * @param BusinessProcessCode|string|null $businessProcess applied when the invoice has none
     */
    public function addInvoices(array $invoices, BusinessProcessCode|string|null $businessProcess = null): self
    {
        foreach ($invoices as $invoice) {
            if ($businessProcess !== null && $invoice->getBusinessProcessId() === null) {
                $invoice->setBusinessProcessId($businessProcess);
            }
            $this->addInvoice($invoice);
        }
        return $this;
    }

    /**
     * Add aggregated transactions — TG-31, sub-flux 10.3.
     */
    public function addTransaction(Transaction $transaction): self
    {
        $this->transactions[] = $transaction;
        return $this;
    }

    /**
     * Add a payment collected against an invoice — TG-34, sub-flux 10.2.
     */
    public function addInvoicePayment(InvoicePayment $payment): self
    {
        $this->invoicePayments[] = $payment;
        return $this;
    }

    /**
     * Add a payment collected against aggregated transactions — TG-37, sub-flux 10.4.
     */
    public function addTransactionPayment(TransactionPayment $payment): self
    {
        $this->transactionPayments[] = $payment;
        return $this;
    }

    /**
     * Assemble and validate the report.
     *
     * @throws ValidationException if a required element is missing or a rule fails
     */
    public function build(): Report
    {
        if ($this->sender === null) {
            throw new ValidationException(
                'A Flux 10 transmission must name the accredited platform emitting it: call setSender()',
                'G6.22'
            );
        }
        if ($this->issuer === null) {
            throw new ValidationException(
                'A Flux 10 transmission must name its declarant: call setIssuer()',
                'G6.26'
            );
        }
        if ($this->transmissionId === null) {
            throw new ValidationException(
                'A Flux 10 transmission must carry an identifier, unique per period and declarant: ' .
                'call setTransmissionId()',
                'G8.05'
            );
        }
        if ($this->period === null) {
            throw new ValidationException(
                'A Flux 10 transmission must declare the period it covers: call setPeriod()',
                'G6.25'
            );
        }

        $report = (new Report())
            ->setSender($this->sender)
            ->setIssuer($this->issuer)
            ->setReportId($this->transmissionId)
            ->setReportName($this->transmissionName)
            ->setTransmissionType($this->transmissionType)
            ->setIssueDateTime($this->issueDateTime)
            ->setPeriod($this->period);

        foreach ($this->invoices as $invoice) {
            $report->addInvoice($invoice);
        }
        foreach ($this->transactions as $transaction) {
            $report->addTransaction($transaction);
        }
        foreach ($this->invoicePayments as $payment) {
            $report->addInvoicePayment($payment);
        }
        foreach ($this->transactionPayments as $payment) {
            $report->addTransactionPayment($payment);
        }

        $report->validate();

        return $report;
    }
}
