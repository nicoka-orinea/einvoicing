<?php

namespace Einvoicing\Flux10;

class Report
{
    /**
     * Report identifier.
     * @var string|null
     */
    public $reportId = null;

    /**
     * Report name.
     * @var string|null
     */
    public $reportName = null;

    /**
     * Transmission type (IN, RE).
     * @var string
     */
    public $transmissionType = 'IN';

    /**
     * Sender party.
     * @var Party|null
     */
    public $sender = null;

    /**
     * Issuer party with role.
     * @var Issuer|null
     */
    public $issuer = null;

    /**
     * Reporting period.
     * @var Period|null
     */
    public $period = null;

    /**
     * Flux 10.1 invoices.
     * @var Invoice[]
     */
    public $invoices = [];

    /**
     * Flux 10.2 invoice payments.
     * @var InvoicePayment[]
     */
    public $invoicePayments = [];

    /**
     * Flux 10.3 transactions.
     * @var Transaction[]
     */
    public $transactions = [];

    /**
     * Flux 10.4 transaction payments.
     * @var TransactionPayment[]
     */
    public $transactionPayments = [];
}
