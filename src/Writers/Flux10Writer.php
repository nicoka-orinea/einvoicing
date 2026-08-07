<?php

namespace Einvoicing\Writers;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\InvoicePayment as Flux10InvoicePayment;
use Einvoicing\Flux10\Issuer as Flux10Issuer;
use Einvoicing\Flux10\IssuerRoleCode as Flux10IssuerRoleCode;
use Einvoicing\Flux10\AmountByRate as Flux10AmountByRate;
use Einvoicing\Flux10\Period as Flux10Period;
use Einvoicing\Flux10\Report as Flux10Report;
use Einvoicing\Flux10\Sender as Flux10Sender;
use Einvoicing\Flux10\TaxBreakdown as Flux10TaxBreakdown;
use Einvoicing\Flux10\Transaction as Flux10Transaction;
use Einvoicing\Flux10\TransactionPayment as Flux10TransactionPayment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\Models\VatBreakdown as InvoiceVatBreakdown;
use Einvoicing\Party;
use InvalidArgumentException;
use UXML\UXML;
use function get_debug_type;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function trim;

class Flux10Writer extends AbstractMultiWriter
{
    private const ROOT_ELEMENT = 'Report';

    /** All Flux 10 dates are unseparated — G1.09 */
    private const DATE_FORMAT = 'Ymd';

    /** Transmission timestamp — TT-3, G7.53 */
    private const DATE_TIME_FORMAT = 'YmdHis';

    /** The timestamp belongs to the emitting platform, not to the server locale — G7.40 */
    private const TIMEZONE = 'Europe/Paris';

    private const DEFAULT_TRANSMISSION_TYPE = 'IN';

    /** Profile identifier of the e-reporting flow — TT-29, S1.12 */
    public const EREPORTING_PROFILE = 'urn.cpro.gouv.fr:1p0:ereporting';

    /** VAT totals are always expressed in euros — TT-202, G6.23 */
    private const VAT_CURRENCY = 'EUR';

    /**
     * Invoicing frameworks accepted in Flux 10 — TT-28, G1.02.
     *
     * Kept here until the dedicated enum lands: presets populate the EN 16931 business
     * process with their own URN (Peppol sets `urn:fdc:peppol.eu:…`), which would
     * otherwise be forwarded as-is and rejected.
     */
    private const BUSINESS_PROCESS_CODES = [
        'B1', 'S1', 'M1', 'B2', 'S2', 'M2', 'B4', 'S4', 'M4', 'S5', 'S6', 'B7', 'S7',
    ];

    /**
     * Emitting accredited platform, used when building a report from plain invoices.
     * @var Flux10Sender|null
     */
    private $sender = null;

    /**
     * Get the emitting accredited platform.
     */
    public function getSender(): ?Flux10Sender
    {
        return $this->sender;
    }

    /**
     * Set the emitting accredited platform (TG-3).
     *
     * Required by {@see exportAll()} and {@see export()}: an EN 16931 invoice carries no
     * platform matricule, so it cannot be inferred from the documents being reported.
     */
    public function setSender(?Flux10Sender $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    /**
     * Export one or more invoices, or an already prepared Flux 10 report.
     *
     * @param array<int,Invoice|Flux10Report> $invoices Invoices or a single Flux 10 report
     */
    public function exportAll(array $invoices): string
    {
        if (count($invoices) === 1 && $invoices[0] instanceof Flux10Report) {
            return $this->exportReport($invoices[0]);
        }

        $report = $this->buildReportFromInvoices($invoices);

        return $this->exportReport($report);
    }

    /**
     * Export a single invoice to Flux 10 XML.
     */
    public function export(Invoice $invoice): string
    {
        return $this->exportAll([$invoice]);
    }

    /**
     * Export an already prepared Flux 10 report to XML.
     */
    public function exportReport(Flux10Report $report): string
    {
        $hasTransactions = !empty($report->getInvoices()) || !empty($report->getTransactions());
        $hasPayments = !empty($report->getInvoicePayments()) || !empty($report->getTransactionPayments());

        if ($hasTransactions && $hasPayments) {
            throw new ValidationException(
                'A Flux 10 transmission carries either aggregated transactions or aggregated payments, ' .
                'never both. Split them into two transmissions.',
                'G6.29'
            );
        }

        $xml = $this->createRoot();

        $this->addReportDocument($xml, $report);

        if ($hasTransactions) {
            $transactionsReport = $xml->add('TransactionsReport');
            $this->addReportPeriod($transactionsReport, $report);
            $this->addInvoices($transactionsReport, $report->getInvoices());
            $this->addTransactions($transactionsReport, $report->getTransactions());
        }

        if ($hasPayments) {
            $paymentsReport = $xml->add('PaymentsReport');
            $this->addReportPeriod($paymentsReport, $report);
            $this->addInvoicePayments($paymentsReport, $report->getInvoicePayments());
            $this->addTransactionPayments($paymentsReport, $report->getTransactionPayments());
        }

        return $xml->asXML();
    }

    private function createRoot(): UXML
    {
        return UXML::newInstance(self::ROOT_ELEMENT);
    }

    private function addReportDocument(UXML $xml, Flux10Report $report): void
    {
        $reportDocument = $xml->add('ReportDocument');

        $this->addRequiredStringNode($reportDocument, 'Id', $report->getReportId(), 'ReportDocument/Id');
        $this->addStringNode($reportDocument, 'Name', $report->getReportName());

        $issueDateTime = $reportDocument->add('IssueDateTime');
        $issueDateTime->add('DateTimeString', $this->formatDateTime($report->getIssueDateTime()));

        $transmissionType = $report->getTransmissionType();
        if ($transmissionType === '') {
            $transmissionType = self::DEFAULT_TRANSMISSION_TYPE;
        }
        $reportDocument->add('TypeCode', $transmissionType);

        $sender = $report->getSender();
        if (!$sender instanceof Flux10Sender) {
            throw new ValidationException(
                'Flux10 report must define a Sender: the accredited platform emitting the transmission, ' .
                'identified by its 4-character matricule',
                'G6.22'
            );
        }

        $issuer = $report->getIssuer();
        if (!$issuer instanceof Flux10Issuer || $issuer->getRoleCode() === null) {
            throw new ValidationException(
                'Flux10 report must define an Issuer with a role code (SE or BY)',
                'G7.52'
            );
        }

        $this->addSenderParty($reportDocument->add('Sender'), $sender);
        $this->addIssuerParty($reportDocument->add('Issuer'), $issuer);
    }

    /**
     * Serialize the emitting accredited platform — TG-3.
     */
    private function addSenderParty(UXML $parent, Flux10Sender $sender): void
    {
        $matricule = $sender->getMatricule();
        if ($matricule === null || $matricule === '') {
            throw new ValidationException('Flux10 report Sender must define a platform matricule (TT-8)', 'G6.22');
        }

        $parent->add('Id', $matricule, ['schemeId' => Flux10Sender::SCHEME_ID]);
        $this->addRequiredStringNode($parent, 'Name', $sender->getName(), 'Sender/Name');
        $parent->add('RoleCode', $sender->getRoleCode());

        $this->addUniversalCommunication($parent, $sender->getUriUniversalCommunication());
    }

    /**
     * Serialize the declarant — TG-5. Its identifier is a SIREN under scheme 0002 (G6.26).
     */
    private function addIssuerParty(UXML $parent, Flux10Issuer $issuer): void
    {
        $siren = $issuer->getSiren();
        if ($siren === null || $siren === '') {
            throw new ValidationException('Flux10 report Issuer must define a SIREN (TT-13)', 'G6.26');
        }

        $roleCode = $issuer->getRoleCode();
        if ($roleCode === null) {
            throw new ValidationException('Flux10 report Issuer must declare a role code (TT-15)', 'G7.52');
        }

        $parent->add('Id', $siren, ['schemeId' => '0002']);
        $this->addRequiredStringNode($parent, 'Name', $issuer->getName(), 'Issuer/Name');
        $parent->add('RoleCode', $roleCode->value);

        $this->addUniversalCommunication($parent, $issuer->getUriUniversalCommunication());
    }

    private function addUniversalCommunication(UXML $parent, ?string $uri): void
    {
        if ($uri !== null && $uri !== '') {
            $parent->add('URIUniversalCommunication')->add('URIID', $uri);
        }
    }

    private function addReportPeriod(UXML $parent, Flux10Report $report): void
    {
        $period = $report->getPeriod();
        if (!$period instanceof Flux10Period) {
            throw new InvalidArgumentException('Flux10 report must define a period when exporting TransactionsReport/PaymentsReport');
        }

        $reportPeriod = $parent->add('ReportPeriod');
        $this->addRequiredDateNode($reportPeriod, 'StartDate', $period->getStartDate(), 'ReportPeriod/StartDate');
        $this->addRequiredDateNode($reportPeriod, 'EndDate', $period->getEndDate(), 'ReportPeriod/EndDate');
    }

    private function addInvoices(UXML $xml, array $invoices): void
    {
        foreach ($invoices as $index => $invoice) {
            if (!$invoice instanceof Flux10Invoice) {
                throw new InvalidArgumentException(sprintf(
                    'Report invoice #%s must be a %s, got %s',
                    $index,
                    Flux10Invoice::class,
                    get_debug_type($invoice)
                ));
            }

            $node = $xml->add('Invoice');
            $this->addRequiredStringNode($node, 'ID', $invoice->getInvoiceId(), 'Invoice/ID');
            $this->addRequiredDateNode($node, 'IssueDate', $invoice->getIssueDate(), 'Invoice/IssueDate');
            $this->addRequiredStringNode($node, 'TypeCode', $invoice->getTypeCode(), 'Invoice/TypeCode');
            $this->addRequiredStringNode($node, 'CurrencyCode', $invoice->getCurrencyCode(), 'Invoice/CurrencyCode');

            $this->addDateNode($node, 'DueDate', $invoice->getDueDate());
            $this->addStringNode($node, 'TaxDueDateTypeCode', $invoice->getTaxDueDateTypeCode());

            $businessProcess = $node->add('BusinessProcess');
            $this->addRequiredStringNode($businessProcess, 'ID', $invoice->getBusinessProcessId(), 'Invoice/BusinessProcess/ID');
            $this->addRequiredStringNode($businessProcess, 'TypeID', $invoice->getBusinessProcessTypeId(), 'Invoice/BusinessProcess/TypeID');

            $seller = $node->add('Seller');
            $this->addRequiredSchemeValueNode(
                $seller,
                'CompanyId',
                $invoice->getSellerId(),
                $invoice->getSellerSchemeId(),
                'Invoice/Seller/CompanyId'
            );
            if ($invoice->getSellerVatId() !== null && $invoice->getSellerVatId() !== '') {
                $seller->add('TaxRegistrationId', $invoice->getSellerVatId(), ['qualifyingId' => 'VAT']);
            }
            if ($invoice->getSellerCountry() !== null && $invoice->getSellerCountry() !== '') {
                $seller->add('PostalAddress')->add('CountryId', $invoice->getSellerCountry());
            }

            $hasBuyer =
                ($invoice->getBuyerId() !== null && $invoice->getBuyerId() !== '') ||
                ($invoice->getBuyerVatId() !== null && $invoice->getBuyerVatId() !== '') ||
                ($invoice->getBuyerCountry() !== null && $invoice->getBuyerCountry() !== '');
            if ($hasBuyer) {
                $buyer = $node->add('Buyer');
                if ($invoice->getBuyerId() !== null && $invoice->getBuyerId() !== '') {
                    $this->addSchemeValueNode($buyer, 'CompanyId', $invoice->getBuyerId(), $invoice->getBuyerSchemeId(), 'Invoice/Buyer/CompanyId');
                }
                if ($invoice->getBuyerVatId() !== null && $invoice->getBuyerVatId() !== '') {
                    $buyer->add('TaxRegistrationId', $invoice->getBuyerVatId(), ['qualifyingId' => 'VAT']);
                }
                if ($invoice->getBuyerCountry() !== null && $invoice->getBuyerCountry() !== '') {
                    $buyer->add('PostalAddress')->add('CountryId', $invoice->getBuyerCountry());
                }
            }

            $monetaryTotal = $node->add('MonetaryTotal');
            $this->addAmountNode($monetaryTotal, 'TaxExclusiveAmount', $invoice->getTaxExclusiveAmount());

            $monetaryTotal->add(
                'TaxAmount',
                $this->resolveVatAmountInEuros(
                    $invoice->getVatAmountEur(),
                    $invoice->getTaxAmount(),
                    $invoice->getCurrencyCode(),
                    'Invoice/MonetaryTotal/TaxAmount'
                ),
                ['CurrencyCode' => self::VAT_CURRENCY]
            );

            $breakdown = $invoice->getTaxBreakdown();
            if (empty($breakdown)) {
                throw new ValidationException(
                    sprintf('Invoice "%s" has no VAT breakdown (TG-23)', $invoice->getInvoiceId() ?? ''),
                    'G1.53'
                );
            }

            foreach ($breakdown as $item) {
                $this->addInvoiceTaxSubtotal($node, $this->assertTaxBreakdown($item, 'Invoice'));
            }
        }
    }

    private function addInvoicePayments(UXML $xml, array $payments): void
    {
        foreach ($payments as $index => $payment) {
            if (!$payment instanceof Flux10InvoicePayment) {
                throw new InvalidArgumentException(sprintf(
                    'Report invoice payment #%s must be a %s, got %s',
                    $index,
                    Flux10InvoicePayment::class,
                    get_debug_type($payment)
                ));
            }

            $node = $xml->add('Invoice');
            $this->addRequiredStringNode($node, 'InvoiceID', $payment->getInvoiceId(), 'PaymentsReport/Invoice/InvoiceID');
            $this->addRequiredDateNode($node, 'IssueDate', $payment->getIssueDate(), 'PaymentsReport/Invoice/IssueDate');

            $paymentNode = $node->add('Payment');
            $this->addRequiredDateNode($paymentNode, 'Date', $payment->getPaymentDate(), 'PaymentsReport/Invoice/Payment/Date');

            $subTotals = $payment->getAmountsByRate();
            if (empty($subTotals)) {
                throw new ValidationException(
                    sprintf(
                        'Payment for invoice "%s" has no amount broken down by VAT rate (TG-36)',
                        $payment->getInvoiceId() ?? ''
                    ),
                    'G1.53'
                );
            }

            foreach ($subTotals as $subTotal) {
                $subTotal = $this->assertAmountByRate($subTotal, 'PaymentsReport/Invoice/Payment/SubTotals');
                $subTotalNode = $paymentNode->add('SubTotals');
                $this->addRequiredAmountNode($subTotalNode, 'TaxPercent', $subTotal->getRate(), 'PaymentsReport/Invoice/Payment/SubTotals/TaxPercent');
                $this->addStringNode($subTotalNode, 'CurrencyCode', $payment->getCurrencyCode());
                $this->addRequiredAmountNode($subTotalNode, 'Amount', $subTotal->getAmount(), 'PaymentsReport/Invoice/Payment/SubTotals/Amount');
            }
        }
    }

    private function addTransactions(UXML $xml, array $transactions): void
    {
        foreach ($transactions as $index => $transaction) {
            if (!$transaction instanceof Flux10Transaction) {
                throw new InvalidArgumentException(sprintf(
                    'Report transaction #%s must be a %s, got %s',
                    $index,
                    Flux10Transaction::class,
                    get_debug_type($transaction)
                ));
            }

            $node = $xml->add('Transactions');
            $this->addRequiredDateNode($node, 'Date', $transaction->getDate(), 'Transactions/Date');
            $this->addRequiredStringNode($node, 'TransactionsCurrency', $transaction->getCurrencyCode(), 'Transactions/TransactionsCurrency');
            $this->addStringNode($node, 'TaxDueDateTypeCode', $transaction->getTaxDueDateTypeCode());
            $this->addRequiredStringNode($node, 'CategoryCode', $transaction->getCategoryCode(), 'Transactions/CategoryCode');
            $this->addRequiredAmountNode($node, 'TaxExclusiveAmount', $transaction->getTaxExclusiveAmount(), 'Transactions/TaxExclusiveAmount');

            $node->add('TaxTotal', $this->resolveVatAmountInEuros(
                $transaction->getVatAmountEur(),
                $transaction->getTaxAmount(),
                $transaction->getCurrencyCode(),
                'Transactions/TaxTotal'
            ));

            if ($transaction->getTransactionCount() !== null) {
                $node->add('TransactionsCount', (string) $transaction->getTransactionCount());
            }

            $breakdown = $transaction->getTaxBreakdown();
            if (empty($breakdown)) {
                throw new ValidationException('Transactions entry has no VAT breakdown (TG-32)', 'G1.53');
            }

            foreach ($breakdown as $item) {
                $this->addTransactionTaxSubtotal($node, $this->assertTaxBreakdown($item, 'Transactions'));
            }
        }
    }

    private function addTransactionPayments(UXML $xml, array $payments): void
    {
        foreach ($payments as $index => $payment) {
            if (!$payment instanceof Flux10TransactionPayment) {
                throw new InvalidArgumentException(sprintf(
                    'Report transaction payment #%s must be a %s, got %s',
                    $index,
                    Flux10TransactionPayment::class,
                    get_debug_type($payment)
                ));
            }

            $node = $xml->add('Transactions');
            $paymentNode = $node->add('Payment');
            $this->addRequiredDateNode($paymentNode, 'Date', $payment->getPaymentDate(), 'PaymentsReport/Transactions/Payment/Date');

            $amountsByRate = $payment->getAmountsByRate();
            if (empty($amountsByRate)) {
                throw new ValidationException(
                    'Transaction payment has no amount broken down by VAT rate (TG-39)',
                    'G1.53'
                );
            }

            foreach ($amountsByRate as $amountByRate) {
                $amountByRate = $this->assertAmountByRate($amountByRate, 'PaymentsReport/Transactions/Payment/SubTotals');
                $subTotalNode = $paymentNode->add('SubTotals');
                $this->addRequiredAmountNode($subTotalNode, 'TaxPercent', $amountByRate->getRate(), 'PaymentsReport/Transactions/Payment/SubTotals/TaxPercent');
                $this->addStringNode($subTotalNode, 'CurrencyCode', $payment->getCurrencyCode());
                $this->addRequiredAmountNode($subTotalNode, 'Amount', $amountByRate->getAmount(), 'PaymentsReport/Transactions/Payment/SubTotals/Amount');
            }
        }
    }

    private function addInvoiceTaxSubtotal(UXML $invoiceNode, Flux10TaxBreakdown $item): void
    {
        $node = $invoiceNode->add('TaxSubTotal');
        $this->addRequiredAmountNode($node, 'TaxableAmount', $item->getTaxableAmount(), 'Invoice/TaxSubTotal/TaxableAmount');
        $this->addRequiredAmountNode($node, 'TaxAmount', $item->getTaxAmount(), 'Invoice/TaxSubTotal/TaxAmount');

        $taxCategory = $node->add('TaxCategory');
        $this->addRequiredAmountNode($taxCategory, 'Percent', $item->getRate(), 'Invoice/TaxSubTotal/TaxCategory/Percent');
    }

    private function addTransactionTaxSubtotal(UXML $transactionsNode, Flux10TaxBreakdown $item): void
    {
        $node = $transactionsNode->add('TaxSubtotal');
        $this->addRequiredAmountNode($node, 'TaxPercent', $item->getRate(), 'Transactions/TaxSubtotal/TaxPercent');
        $this->addRequiredAmountNode($node, 'TaxableAmount', $item->getTaxableAmount(), 'Transactions/TaxSubtotal/TaxableAmount');
        $this->addRequiredAmountNode($node, 'TaxTotal', $item->getTaxAmount(), 'Transactions/TaxSubtotal/TaxTotal');
    }

    private function buildReportFromInvoices(array $invoices): Flux10Report
    {
        $report = new Flux10Report();
        $report->setTransmissionType(self::DEFAULT_TRANSMISSION_TYPE);
        $report->setSender($this->sender);

        $issueDateBounds = $this->findIssueDateBounds($invoices);
        $reportId = $this->buildReportId($invoices, $issueDateBounds);
        if ($reportId !== null) {
            $report->setReportId($reportId);
        }

        $issuer = $this->resolveIssuer($invoices);
        if ($issuer !== null) {
            $report->setIssuer($this->buildFlux10Issuer($issuer['party'], $issuer['roleCode']));
        }

        if ($issueDateBounds['start'] !== null && $issueDateBounds['end'] !== null) {
            $period = new Flux10Period();
            $period->setStartDate($issueDateBounds['start']);
            $period->setEndDate($issueDateBounds['end']);
            $report->setPeriod($period);
        }

        foreach ($invoices as $invoice) {
            if ($invoice instanceof Invoice) {
                $report->addInvoice($this->buildFlux10Invoice($invoice));
            }
        }

        return $report;
    }

    private function buildFlux10Issuer(Party $party, Flux10IssuerRoleCode $roleCode): Flux10Issuer
    {
        $issuer = new Flux10Issuer();
        $identifier = $this->getPartyIdentifier($party);
        $issuer->setSiren($identifier?->getValue());
        $issuer->setSchemeId($identifier?->getScheme());
        $issuer->setName($party->getName() ?? $party->getTradingName());
        $issuer->setVatId($party->getVatNumber());
        $issuer->setUriUniversalCommunication($this->buildUniversalCommunicationUri($party->getElectronicAddress()));
        $issuer->setRoleCode($roleCode);

        return $issuer;
    }

    private function buildUniversalCommunicationUri(?Identifier $identifier): ?string
    {
        if (!$identifier instanceof Identifier) {
            return null;
        }

        $value = trim($identifier->getValue());
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $value) === 1) {
            return $value;
        }

        $scheme = $identifier->getScheme();
        if ($scheme !== null && $scheme !== '') {
            return "iso6523-actorid-upis::{$scheme}:{$value}";
        }

        return "urn:identifier:{$value}";
    }

    private function buildFlux10Invoice(Invoice $invoice): Flux10Invoice
    {
        $businessProcess = $invoice->getBusinessProcess();
        if (!in_array($businessProcess, self::BUSINESS_PROCESS_CODES, true)) {
            throw new ValidationException(sprintf(
                'Invoice "%s" carries "%s" as business process; Flux 10 expects an invoicing framework code (%s). ' .
                'Presets set their own specification URN here, so it must be overridden with setBusinessProcess().',
                $invoice->getNumber() ?? '',
                $businessProcess ?? '',
                implode(', ', self::BUSINESS_PROCESS_CODES)
            ), 'G1.02');
        }

        $fluxInvoice = new Flux10Invoice();
        $fluxInvoice->setInvoiceId($invoice->getNumber());
        $fluxInvoice->setIssueDate($invoice->getIssueDate());
        $fluxInvoice->setTypeCode((string) $invoice->getType());
        $fluxInvoice->setCurrencyCode($invoice->getCurrency());
        $fluxInvoice->setDueDate($invoice->getDueDate());
        $fluxInvoice->setBusinessProcessId($businessProcess);
        $fluxInvoice->setBusinessProcessTypeId(self::EREPORTING_PROFILE);

        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();

        $fluxInvoice->setSellerCountry($this->getPartyCountry($seller));
        $fluxInvoice->setBuyerCountry($this->getPartyCountry($buyer));

        $sellerIdentifier = $this->getPartyIdentifier($seller);
        $buyerIdentifier = $this->getPartyIdentifier($buyer);
        $fluxInvoice->setSellerId($sellerIdentifier?->getValue() ?? ($seller?->getVatNumber()));
        $fluxInvoice->setSellerSchemeId($sellerIdentifier?->getScheme());
        $fluxInvoice->setSellerVatId($seller?->getVatNumber());

        $fluxInvoice->setBuyerId($buyerIdentifier?->getValue() ?? ($buyer?->getVatNumber()));
        $fluxInvoice->setBuyerSchemeId($buyerIdentifier?->getScheme());
        $fluxInvoice->setBuyerVatId($buyer?->getVatNumber());

        $totals = $invoice->getTotals();
        $fluxInvoice->setTaxExclusiveAmount($totals->taxExclusiveAmount);
        $fluxInvoice->setTaxAmount($totals->vatAmount);

        foreach ($totals->vatBreakdown as $item) {
            if (!$item instanceof InvoiceVatBreakdown) {
                continue;
            }
            $fluxInvoice->addTaxBreakdownItem($this->buildFlux10TaxBreakdown($item));
        }

        return $fluxInvoice;
    }

    private function buildFlux10TaxBreakdown(InvoiceVatBreakdown $item): Flux10TaxBreakdown
    {
        $fluxItem = new Flux10TaxBreakdown();
        $fluxItem->setRate($item->rate);
        $fluxItem->setTaxableAmount($item->taxableAmount);
        $fluxItem->setTaxAmount($item->taxAmount);

        return $fluxItem;
    }

    private function resolveIssuer(array $invoices): ?array
    {
        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $roleCode = $this->getReportingRole($invoice);
            $reportingParty = $this->getReportingParty($invoice);
            if ($roleCode !== null && $reportingParty !== null) {
                return [
                    'party' => $reportingParty,
                    'roleCode' => $roleCode,
                ];
            }
        }

        return null;
    }

    private function getReportingParty(Invoice $invoice): ?Party
    {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $sellerCountry = $this->getPartyCountry($seller);
        $buyerCountry = $this->getPartyCountry($buyer);

        if ($sellerCountry === 'FR') {
            return $seller;
        }
        if ($buyerCountry === 'FR') {
            return $buyer;
        }

        return null;
    }

    private function getReportingRole(Invoice $invoice): ?Flux10IssuerRoleCode
    {
        $sellerCountry = $this->getPartyCountry($invoice->getSeller());
        $buyerCountry = $this->getPartyCountry($invoice->getBuyer());

        if ($sellerCountry === 'FR') {
            return Flux10IssuerRoleCode::SELLER;
        }
        if ($buyerCountry === 'FR') {
            return Flux10IssuerRoleCode::BUYER;
        }

        return null;
    }

    private function findIssueDateBounds(array $invoices): array
    {
        $start = null;
        $end = null;

        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $issueDate = $invoice->getIssueDate();
            if ($issueDate === null) {
                continue;
            }

            if ($start === null || $issueDate < $start) {
                $start = $issueDate;
            }
            if ($end === null || $issueDate > $end) {
                $end = $issueDate;
            }
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function buildReportId(array $invoices, array $issueDateBounds): ?string
    {
        if (count($invoices) === 1 && $invoices[0] instanceof Invoice) {
            $invoiceId = $invoices[0]->getNumber();
            if ($invoiceId !== null) {
                return $invoiceId;
            }
        }

        if ($issueDateBounds['start'] instanceof DateTime && $issueDateBounds['end'] instanceof DateTime) {
            return 'REPORT-' . $issueDateBounds['start']->format('Ymd') . '-' . $issueDateBounds['end']->format('Ymd');
        }

        return null;
    }

    private function getPartyIdentifier(?Party $party): ?Identifier
    {
        if ($party === null) {
            return null;
        }

        $companyId = $party->getCompanyId();
        if ($companyId !== null) {
            return $companyId;
        }

        $taxRegistrationId = $party->getTaxRegistrationId();
        if ($taxRegistrationId !== null) {
            return $taxRegistrationId;
        }

        $identifiers = $party->getIdentifiers();
        if (!empty($identifiers)) {
            return $identifiers[0];
        }

        return null;
    }

    /**
     * Resolve the VAT total in euros — TT-202/TT-83, G6.23.
     *
     * Falls back to the document total only when the document is already in euros:
     * converting is a business decision the library must not take silently.
     */
    private function resolveVatAmountInEuros(
        float|string|null $vatAmountEur,
        float|string|null $taxAmount,
        ?string $currencyCode,
        string $context
    ): string {
        $formatted = $this->formatAmount($vatAmountEur);
        if ($formatted !== null) {
            return $formatted;
        }

        if ($currencyCode === self::VAT_CURRENCY) {
            $formatted = $this->formatAmount($taxAmount);
            if ($formatted !== null) {
                return $formatted;
            }
            throw new InvalidArgumentException("Missing required amount for {$context}");
        }

        throw new ValidationException(sprintf(
            '%s must be expressed in EUR but the document currency is "%s". Set the converted amount ' .
            'explicitly with setVatAmountEur().',
            $context,
            $currencyCode ?? ''
        ), 'G6.23');
    }

    private function assertTaxBreakdown(mixed $item, string $context): Flux10TaxBreakdown
    {
        if (!$item instanceof Flux10TaxBreakdown) {
            throw new InvalidArgumentException(sprintf(
                '%s VAT breakdown item must be a %s, got %s',
                $context,
                Flux10TaxBreakdown::class,
                get_debug_type($item)
            ));
        }

        return $item;
    }

    private function assertAmountByRate(mixed $item, string $context): Flux10AmountByRate
    {
        if (!$item instanceof Flux10AmountByRate) {
            throw new InvalidArgumentException(sprintf(
                '%s item must be a %s, got %s',
                $context,
                Flux10AmountByRate::class,
                get_debug_type($item)
            ));
        }

        return $item;
    }

    private function addRequiredStringNode(UXML $node, string $name, ?string $value, string $context): void
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required value for {$context}");
        }
        $node->add($name, $value);
    }

    private function addSchemeValueNode(UXML $node, string $name, ?string $value, ?string $schemeId, string $context): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->addRequiredSchemeValueNode($node, $name, $value, $schemeId, $context);
    }

    private function addRequiredSchemeValueNode(UXML $node, string $name, ?string $value, ?string $schemeId, string $context): void
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required value for {$context}");
        }
        if ($schemeId === null || $schemeId === '') {
            throw new ValidationException(
                "Missing identifier scheme for {$context}: expected an ISO 6523 (ICD) code",
                'G2.19'
            );
        }
        $node->add($name, $value, ['schemeId' => $schemeId]);
    }

    private function addRequiredDateNode(UXML $node, string $name, $date, string $context): void
    {
        $formatted = $this->formatDate($date);
        if ($formatted === null) {
            throw new InvalidArgumentException("Missing required date for {$context}");
        }
        $node->add($name, $formatted);
    }

    private function addRequiredAmountNode(UXML $node, string $name, $amount, string $context): void
    {
        $formatted = $this->formatAmount($amount);
        if ($formatted === null) {
            throw new InvalidArgumentException("Missing required amount for {$context}");
        }
        $node->add($name, $formatted);
    }

    private function getPartyCountry(?Party $party): ?string
    {
        if ($party === null) {
            return null;
        }

        return $party->getCountry();
    }

    private function addStringNode(UXML $node, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $node->add($name, $value);
    }

    private function addDateNode(UXML $node, string $name, $date): void
    {
        $formatted = $this->formatDate($date);
        if ($formatted === null) {
            return;
        }

        $node->add($name, $formatted);
    }

    private function addAmountNode(UXML $node, string $name, $amount): void
    {
        $formatted = $this->formatAmount($amount);
        if ($formatted === null) {
            return;
        }

        $node->add($name, $formatted);
    }

    /**
     * Format a date as `AAAAMMJJ` — G1.09.
     *
     * A string is accepted only if it already is a Flux 10 date or an ISO one, which is
     * what UBL-shaped callers hold; anything else is refused rather than passed through.
     */
    private function formatDate($date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format(self::DATE_FORMAT);
        }

        if (is_string($date) && $date !== '') {
            if (preg_match('/^\d{8}$/', $date) === 1) {
                return $date;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed !== false) {
                return $parsed->format(self::DATE_FORMAT);
            }

            throw new ValidationException(
                sprintf('Cannot read "%s" as a Flux 10 date, expected AAAAMMJJ', $date),
                'G1.09'
            );
        }

        return null;
    }

    /**
     * Format the transmission timestamp as `AAAAMMJJHHMMSS` — TT-3, G7.53.
     */
    private function formatDateTime($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(self::DATE_TIME_FORMAT);
        }

        if (is_string($value) && $value !== '') {
            if (preg_match('/^\d{14}$/', $value) === 1) {
                return $value;
            }

            throw new ValidationException(
                sprintf('Cannot read "%s" as a Flux 10 timestamp, expected AAAAMMJJHHMMSS', $value),
                'G7.53'
            );
        }

        return (new DateTime('now', new DateTimeZone(self::TIMEZONE)))->format(self::DATE_TIME_FORMAT);
    }

    private function formatAmount($amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        if (is_string($amount)) {
            if ($amount === '') {
                return null;
            }
            if (is_numeric($amount)) {
                return number_format(round((float) $amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
            }
            return $amount;
        }

        if (is_int($amount) || is_float($amount)) {
            return number_format(round((float) $amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
        }

        return null;
    }
}
