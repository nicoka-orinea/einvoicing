<?php

namespace Einvoicing\Writers;

use DateTime;
use Einvoicing\Flux10\AmountByRate as Flux10AmountByRate;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\InvoicePayment as Flux10InvoicePayment;
use Einvoicing\Flux10\Issuer as Flux10Issuer;
use Einvoicing\Flux10\IssuerRoleCode as Flux10IssuerRoleCode;
use Einvoicing\Flux10\Party as Flux10Party;
use Einvoicing\Flux10\Period as Flux10Period;
use Einvoicing\Flux10\Report as Flux10Report;
use Einvoicing\Flux10\TaxBreakdown as Flux10TaxBreakdown;
use Einvoicing\Flux10\Transaction as Flux10Transaction;
use Einvoicing\Flux10\TransactionPayment as Flux10TransactionPayment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\Models\VatBreakdown as InvoiceVatBreakdown;
use Einvoicing\Party;
use InvalidArgumentException;
use UXML\UXML;
use function preg_match;
use function trim;

class Flux10Writer extends AbstractMultiWriter
{
    private const ROOT_ELEMENT = 'Report';
    private const DATE_FORMAT = 'Y-m-d';
    private const DATE_TIME_FORMAT = 'c';
    private const DEFAULT_TRANSMISSION_TYPE = 'IN';

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
        $xml = $this->createRoot();

        $this->addReportDocument($xml, $report);

        $hasTransactions = !empty($report->getInvoices()) || !empty($report->getTransactions());
        if ($hasTransactions) {
            $transactionsReport = $xml->add('TransactionsReport');
            $this->addReportPeriod($transactionsReport, $report);
            $this->addInvoices($transactionsReport, $report->getInvoices());
            $this->addTransactions($transactionsReport, $report->getTransactions());
        }

        $hasPayments = !empty($report->getInvoicePayments()) || !empty($report->getTransactionPayments());
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
        $issueDateTime->add('DateTimeString', (new DateTime())->format(self::DATE_TIME_FORMAT));

        $transmissionType = $report->getTransmissionType();
        if ($transmissionType === '') {
            $transmissionType = self::DEFAULT_TRANSMISSION_TYPE;
        }
        $reportDocument->add('TypeCode', $transmissionType);

        $issuer = $report->getIssuer();
        if (!$issuer instanceof Flux10Issuer || $issuer->getRoleCode() === null) {
            throw new InvalidArgumentException('Flux10 report must define an issuer with a role code');
        }

        $sender = $report->getSender() ?? $issuer;
        if (!$sender instanceof Flux10Party) {
            throw new InvalidArgumentException('Flux10 report must define a sender');
        }

        $this->addReportParty($reportDocument->add('Sender'), $sender, $issuer->getRoleCode()->value, 'Sender');
        $this->addReportParty($reportDocument->add('Issuer'), $issuer, $issuer->getRoleCode()->value, 'Issuer');
    }

    private function addReportParty(UXML $parent, Flux10Party $party, string $roleCode, string $context): void
    {
        $schemeId = $party->getSchemeId() ?? 'UNKNOWN';
        $id = $party->getSiren();
        if ($id === null || $id === '') {
            throw new InvalidArgumentException("Flux10 report {$context} must define an Id value");
        }
        $parent->add('Id', $id, ['schemeId' => $schemeId]);
        $this->addRequiredStringNode($parent, 'Name', $party->getName(), "{$context}/Name");
        $parent->add('RoleCode', $roleCode);

        $uri = $party->getUriUniversalCommunication();
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
        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Flux10Invoice) {
                continue;
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
                    $this->addSchemeValueNode($buyer, 'CompanyId', $invoice->getBuyerId(), $invoice->getBuyerSchemeId());
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

            $taxAmount = $this->formatAmount($invoice->getTaxAmount());
            if ($taxAmount === null) {
                throw new InvalidArgumentException('Invoice/MonetaryTotal/TaxAmount is required');
            }
            $currencyCode = $invoice->getCurrencyCode();
            if ($currencyCode === null || $currencyCode === '') {
                throw new InvalidArgumentException('Invoice/CurrencyCode is required to set MonetaryTotal/TaxAmount@CurrencyCode');
            }
            $monetaryTotal->add('TaxAmount', $taxAmount, ['CurrencyCode' => $currencyCode]);

            $breakdown = $invoice->getTaxBreakdown();
            if (empty($breakdown)) {
                $fallback = new Flux10TaxBreakdown();
                $fallback->setRate(0);
                $fallback->setTaxableAmount($invoice->getTaxExclusiveAmount());
                $fallback->setTaxAmount($invoice->getTaxAmount());
                $breakdown = [$fallback];
            }

            foreach ($breakdown as $item) {
                if (!$item instanceof Flux10TaxBreakdown) {
                    continue;
                }
                $this->addInvoiceTaxSubtotal($node, $item);
            }
        }
    }

    private function addInvoicePayments(UXML $xml, array $payments): void
    {
        foreach ($payments as $payment) {
            if (!$payment instanceof Flux10InvoicePayment) {
                continue;
            }

            $node = $xml->add('Invoice');
            $this->addRequiredStringNode($node, 'InvoiceID', $payment->getInvoiceId(), 'PaymentsReport/Invoice/InvoiceID');
            $this->addRequiredDateNode($node, 'IssueDate', $payment->getIssueDate(), 'PaymentsReport/Invoice/IssueDate');

            $paymentNode = $node->add('Payment');
            $this->addRequiredDateNode($paymentNode, 'Date', $payment->getPaymentDate(), 'PaymentsReport/Invoice/Payment/Date');

            $subTotals = $payment->getAmountsByRate();
            if (empty($subTotals)) {
                $amount = $payment->getAmount();
                if ($amount !== null && $amount !== '') {
                    $fallback = new Flux10AmountByRate();
                    $fallback->setRate(0);
                    $fallback->setAmount($amount);
                    $subTotals = [$fallback];
                }
            }
            if (empty($subTotals)) {
                throw new InvalidArgumentException('PaymentsReport/Invoice/Payment/SubTotals must have at least one item');
            }

            foreach ($subTotals as $subTotal) {
                if (!$subTotal instanceof Flux10AmountByRate) {
                    continue;
                }
                $subTotalNode = $paymentNode->add('SubTotals');
                $this->addRequiredAmountNode($subTotalNode, 'TaxPercent', $subTotal->getRate(), 'PaymentsReport/Invoice/Payment/SubTotals/TaxPercent');
                $this->addStringNode($subTotalNode, 'CurrencyCode', $payment->getCurrencyCode());
                $this->addRequiredAmountNode($subTotalNode, 'Amount', $subTotal->getAmount(), 'PaymentsReport/Invoice/Payment/SubTotals/Amount');
            }
        }
    }

    private function addTransactions(UXML $xml, array $transactions): void
    {
        foreach ($transactions as $transaction) {
            if (!$transaction instanceof Flux10Transaction) {
                continue;
            }

            $node = $xml->add('Transactions');
            $this->addRequiredDateNode($node, 'Date', $transaction->getDate(), 'Transactions/Date');
            $this->addRequiredStringNode($node, 'TransactionsCurrency', $transaction->getCurrencyCode(), 'Transactions/TransactionsCurrency');
            $this->addStringNode($node, 'TaxDueDateTypeCode', $transaction->getTaxDueDateTypeCode());
            $this->addRequiredStringNode($node, 'CategoryCode', $transaction->getCategoryCode(), 'Transactions/CategoryCode');
            $this->addRequiredAmountNode($node, 'TaxExclusiveAmount', $transaction->getTaxExclusiveAmount(), 'Transactions/TaxExclusiveAmount');
            $this->addRequiredAmountNode($node, 'TaxTotal', $transaction->getTaxAmount(), 'Transactions/TaxTotal');

            if ($transaction->getTransactionCount() !== null) {
                $node->add('TransactionsCount', (string) $transaction->getTransactionCount());
            }

            $breakdown = $transaction->getTaxBreakdown();
            if (empty($breakdown)) {
                $fallback = new Flux10TaxBreakdown();
                $fallback->setRate(0);
                $fallback->setTaxableAmount($transaction->getTaxExclusiveAmount());
                $fallback->setTaxAmount($transaction->getTaxAmount());
                $breakdown = [$fallback];
            }

            foreach ($breakdown as $item) {
                if (!$item instanceof Flux10TaxBreakdown) {
                    continue;
                }
                $this->addTransactionTaxSubtotal($node, $item);
            }
        }
    }

    private function addTransactionPayments(UXML $xml, array $payments): void
    {
        foreach ($payments as $payment) {
            if (!$payment instanceof Flux10TransactionPayment) {
                continue;
            }

            $node = $xml->add('Transactions');
            $paymentNode = $node->add('Payment');
            $this->addRequiredDateNode($paymentNode, 'Date', $payment->getPaymentDate(), 'PaymentsReport/Transactions/Payment/Date');

            $amountsByRate = $payment->getAmountsByRate();
            if (empty($amountsByRate)) {
                throw new InvalidArgumentException('PaymentsReport/Transactions/Payment/SubTotals must have at least one item');
            }

            foreach ($amountsByRate as $amountByRate) {
                if (!$amountByRate instanceof Flux10AmountByRate) {
                    continue;
                }
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

        $issueDateBounds = $this->findIssueDateBounds($invoices);
        $reportId = $this->buildReportId($invoices, $issueDateBounds);
        if ($reportId !== null) {
            $report->setReportId($reportId);
        }

        $sender = $this->resolveSender($invoices);
        if ($sender !== null) {
            $report->setSender($this->buildFlux10Party($sender));
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

    private function buildFlux10Party(Party $party): Flux10Party
    {
        $fluxParty = new Flux10Party();
        $identifier = $this->getPartyIdentifier($party);
        $fluxParty->setSiren($identifier?->getValue());
        $fluxParty->setSchemeId($identifier?->getScheme());
        $fluxParty->setName($party->getName() ?? $party->getTradingName());
        $fluxParty->setVatId($party->getVatNumber());
        $fluxParty->setUriUniversalCommunication($this->buildUniversalCommunicationUri($party->getElectronicAddress()));

        return $fluxParty;
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
        $fluxInvoice = new Flux10Invoice();
        $fluxInvoice->setInvoiceId($invoice->getNumber());
        $fluxInvoice->setIssueDate($invoice->getIssueDate());
        $fluxInvoice->setTypeCode((string) $invoice->getType());
        $fluxInvoice->setCurrencyCode($invoice->getCurrency());
        $fluxInvoice->setDueDate($invoice->getDueDate());
        $fluxInvoice->setBusinessProcessId($invoice->getBusinessProcess() ?? 'UNKNOWN');
        $fluxInvoice->setBusinessProcessTypeId($invoice->getSpecification() ?? 'UNKNOWN');

        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $sellerCountry = $this->getPartyCountry($seller);
        $buyerCountry = $this->getPartyCountry($buyer);

        $fluxInvoice->setSellerCountry($sellerCountry);
        $fluxInvoice->setBuyerCountry($buyerCountry);

        $sellerIdentifier = $this->getPartyIdentifier($seller);
        $buyerIdentifier = $this->getPartyIdentifier($buyer);
        $fluxInvoice->setSellerId($sellerIdentifier?->getValue() ?? ($seller?->getVatNumber()));
        $fluxInvoice->setSellerSchemeId($sellerIdentifier?->getScheme() ?? ($seller?->getVatNumber() ? 'VAT' : null));
        $fluxInvoice->setSellerVatId($seller?->getVatNumber());

        $fluxInvoice->setBuyerId($buyerIdentifier?->getValue() ?? ($buyer?->getVatNumber()));
        $fluxInvoice->setBuyerSchemeId($buyerIdentifier?->getScheme() ?? ($buyer?->getVatNumber() ? 'VAT' : null));
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

    private function resolveSender(array $invoices): ?Party
    {
        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Invoice) {
                continue;
            }
            $reportingParty = $this->getReportingParty($invoice);
            if ($reportingParty !== null) {
                return $reportingParty;
            }
        }

        $firstInvoice = $invoices[0] ?? null;
        if ($firstInvoice instanceof Invoice) {
            return $firstInvoice->getSeller() ?? $firstInvoice->getBuyer();
        }

        return null;
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

    private function addRequiredStringNode(UXML $node, string $name, ?string $value, string $context): void
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required value for {$context}");
        }
        $node->add($name, $value);
    }

    private function addSchemeValueNode(UXML $node, string $name, ?string $value, ?string $schemeId): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $node->add($name, $value, ['schemeId' => $schemeId ?? 'UNKNOWN']);
    }

    private function addRequiredSchemeValueNode(UXML $node, string $name, ?string $value, ?string $schemeId, string $context): void
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing required value for {$context}");
        }
        $node->add($name, $value, ['schemeId' => $schemeId ?? 'UNKNOWN']);
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

    private function formatDate($date): ?string
    {
        if ($date instanceof DateTime) {
            return $date->format(self::DATE_FORMAT);
        }

        if (is_string($date) && $date !== '') {
            return $date;
        }

        return null;
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
