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
use Einvoicing\Invoice;
use Einvoicing\Models\VatBreakdown as InvoiceVatBreakdown;
use Einvoicing\Party;
use UXML\UXML;

class Flux10Writer extends AbstractMultiWriter
{
    private const ROOT_ELEMENT = 'MultiFlowReport';
    private const DATE_FORMAT = 'Y-m-d';
    private const DEFAULT_TRANSMISSION_TYPE = 'IN';

    public function exportAll(array $invoices): string
    {
        if (count($invoices) === 1 && $invoices[0] instanceof Flux10Report) {
            return $this->exportReport($invoices[0]);
        }

        $report = $this->buildReportFromInvoices($invoices);

        return $this->exportReport($report);
    }

    public function export(Invoice $invoice): string
    {
        return $this->exportAll([$invoice]);
    }

    public function exportReport(Flux10Report $report): string
    {
        $xml = $this->createRoot();

        $this->addReportHeaderFromReport($xml, $report);
        $this->addInvoices($xml, $report->getInvoices());
        $this->addInvoicePayments($xml, $report->getInvoicePayments());
        $this->addTransactions($xml, $report->getTransactions());
        $this->addTransactionPayments($xml, $report->getTransactionPayments());

        return $xml->asXML();
    }

    private function createRoot(): UXML
    {
        return UXML::newInstance(self::ROOT_ELEMENT);
    }

    private function addReportHeaderFromReport(UXML $xml, Flux10Report $report): void
    {
        $this->addStringNode($xml, 'reportId', $report->getReportId());
        $this->addStringNode($xml, 'reportName', $report->getReportName());

        $transmissionType = $report->getTransmissionType();
        if ($transmissionType === '') {
            $transmissionType = self::DEFAULT_TRANSMISSION_TYPE;
        }
        $this->addStringNode($xml, 'transmissionType', $transmissionType);

        $sender = $report->getSender();
        if ($sender instanceof Flux10Party) {
            $this->addPartyNode($xml->add('sender'), $sender);
        }

        $issuer = $report->getIssuer();
        if ($issuer instanceof Flux10Issuer) {
            $issuerNode = $xml->add('issuer');
            $this->addPartyNode($issuerNode, $issuer);
            $roleCode = $issuer->getRoleCode()?->value;
            $this->addStringNode($issuerNode, 'roleCode', $roleCode);
        }

        $period = $report->getPeriod();
        if ($period instanceof Flux10Period) {
            $periodNode = $xml->add('period');
            $this->addDateNode($periodNode, 'startDate', $period->getStartDate());
            $this->addDateNode($periodNode, 'endDate', $period->getEndDate());
        }
    }

    private function addInvoices(UXML $xml, array $invoices): void
    {
        if (empty($invoices)) {
            return;
        }

        $parent = $xml->add('invoices');
        foreach ($invoices as $invoice) {
            if (!$invoice instanceof Flux10Invoice) {
                continue;
            }

            $node = $parent->add('invoice');
            $this->addStringNode($node, 'invoiceId', $invoice->getInvoiceId());
            $this->addDateNode($node, 'issueDate', $invoice->getIssueDate());
            $this->addStringNode($node, 'typeCode', $invoice->getTypeCode());
            $this->addStringNode($node, 'sellerId', $invoice->getSellerId());
            $this->addStringNode($node, 'sellerCountry', $invoice->getSellerCountry());
            $this->addStringNode($node, 'sellerVatId', $invoice->getSellerVatId());
            $this->addStringNode($node, 'buyerId', $invoice->getBuyerId());
            $this->addStringNode($node, 'buyerCountry', $invoice->getBuyerCountry());
            $this->addStringNode($node, 'buyerVatId', $invoice->getBuyerVatId());
            $this->addAmountNode($node, 'taxExclusiveAmount', $invoice->getTaxExclusiveAmount());
            $this->addAmountNode($node, 'taxAmount', $invoice->getTaxAmount());

            $breakdown = $invoice->getTaxBreakdown();
            if (!empty($breakdown)) {
                $taxBreakdown = $node->add('taxBreakdown');
                foreach ($breakdown as $item) {
                    if (!$item instanceof Flux10TaxBreakdown) {
                        continue;
                    }
                    $this->addTaxBreakdownItem($taxBreakdown, $item);
                }
            }
        }
    }

    private function addInvoicePayments(UXML $xml, array $payments): void
    {
        if (empty($payments)) {
            return;
        }

        $parent = $xml->add('invoicePayments');
        foreach ($payments as $payment) {
            if (!$payment instanceof Flux10InvoicePayment) {
                continue;
            }

            $node = $parent->add('invoicePayment');
            $this->addStringNode($node, 'invoiceId', $payment->getInvoiceId());
            $this->addDateNode($node, 'paymentDate', $payment->getPaymentDate());
            $this->addAmountNode($node, 'amount', $payment->getAmount());
        }
    }

    private function addTransactions(UXML $xml, array $transactions): void
    {
        if (empty($transactions)) {
            return;
        }

        $parent = $xml->add('transactions');
        foreach ($transactions as $transaction) {
            if (!$transaction instanceof Flux10Transaction) {
                continue;
            }

            $node = $parent->add('transaction');
            $this->addDateNode($node, 'date', $transaction->getDate());
            $this->addStringNode($node, 'categoryCode', $transaction->getCategoryCode());
            $this->addAmountNode($node, 'taxExclusiveAmount', $transaction->getTaxExclusiveAmount());
            $this->addAmountNode($node, 'taxAmount', $transaction->getTaxAmount());

            $breakdown = $transaction->getTaxBreakdown();
            if (!empty($breakdown)) {
                $taxBreakdown = $node->add('taxBreakdown');
                foreach ($breakdown as $item) {
                    if (!$item instanceof Flux10TaxBreakdown) {
                        continue;
                    }
                    $this->addTaxBreakdownItem($taxBreakdown, $item);
                }
            }

            if ($transaction->getTransactionCount() !== null) {
                $node->add('transactionCount', (string) $transaction->getTransactionCount());
            }

            $this->addStringNode($node, 'taxDueDateTypeCode', $transaction->getTaxDueDateTypeCode());
        }
    }

    private function addTransactionPayments(UXML $xml, array $payments): void
    {
        if (empty($payments)) {
            return;
        }

        $parent = $xml->add('transactionPayments');
        foreach ($payments as $payment) {
            if (!$payment instanceof Flux10TransactionPayment) {
                continue;
            }

            $node = $parent->add('transactionPayment');
            $this->addDateNode($node, 'paymentDate', $payment->getPaymentDate());

            $amountsByRate = $payment->getAmountsByRate();
            if (!empty($amountsByRate)) {
                $amounts = $node->add('amountsByRate');
                foreach ($amountsByRate as $amountByRate) {
                    if (!$amountByRate instanceof Flux10AmountByRate) {
                        continue;
                    }
                    $amountNode = $amounts->add('item');
                    $this->addAmountNode($amountNode, 'rate', $amountByRate->getRate());
                    $this->addAmountNode($amountNode, 'amount', $amountByRate->getAmount());
                }
            }
        }
    }

    private function addTaxBreakdownItem(UXML $parent, Flux10TaxBreakdown $item): void
    {
        $node = $parent->add('item');
        $this->addAmountNode($node, 'rate', $item->getRate());
        $this->addAmountNode($node, 'taxableAmount', $item->getTaxableAmount());
        $this->addAmountNode($node, 'taxAmount', $item->getTaxAmount());
    }

    private function addPartyNode(UXML $node, Flux10Party $party): void
    {
        $this->addStringNode($node, 'siren', $party->getSiren());
        $this->addStringNode($node, 'name', $party->getName());
        $this->addStringNode($node, 'vatId', $party->getVatId());
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
        $fluxParty->setSiren($this->getPartyId($party));
        $fluxParty->setName($party->getName() ?? $party->getTradingName());
        $fluxParty->setVatId($party->getVatNumber());

        return $fluxParty;
    }

    private function buildFlux10Issuer(Party $party, Flux10IssuerRoleCode $roleCode): Flux10Issuer
    {
        $issuer = new Flux10Issuer();
        $issuer->setSiren($this->getPartyId($party));
        $issuer->setName($party->getName() ?? $party->getTradingName());
        $issuer->setVatId($party->getVatNumber());
        $issuer->setRoleCode($roleCode);

        return $issuer;
    }

    private function buildFlux10Invoice(Invoice $invoice): Flux10Invoice
    {
        $fluxInvoice = new Flux10Invoice();
        $fluxInvoice->setInvoiceId($invoice->getNumber());
        $fluxInvoice->setIssueDate($invoice->getIssueDate());
        $fluxInvoice->setTypeCode((string) $invoice->getType());

        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $sellerCountry = $this->getPartyCountry($seller);
        $buyerCountry = $this->getPartyCountry($buyer);

        $fluxInvoice->setSellerCountry($sellerCountry);
        $fluxInvoice->setBuyerCountry($buyerCountry);

        $sellerId = $this->getPartyId($seller);
        $buyerId = $this->getPartyId($buyer);

        if ($sellerCountry === 'FR') {
            $fluxInvoice->setSellerId($sellerId);
        } elseif ($sellerCountry !== null && $seller !== null) {
            $fluxInvoice->setSellerVatId($seller->getVatNumber());
        }

        if ($buyerCountry === 'FR') {
            $fluxInvoice->setBuyerId($buyerId);
        } elseif ($buyerCountry !== null && $buyer !== null) {
            $fluxInvoice->setBuyerVatId($buyer->getVatNumber());
        }

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

        if ($sellerCountry === 'FR' && $buyerCountry !== 'FR') {
            return Flux10IssuerRoleCode::SELLER;
        }
        if ($buyerCountry === 'FR' && $sellerCountry !== 'FR') {
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

    private function getPartyId(?Party $party): ?string
    {
        if ($party === null) {
            return null;
        }

        $companyId = $party->getCompanyId();
        if ($companyId !== null) {
            return $companyId->getValue();
        }

        $taxRegistrationId = $party->getTaxRegistrationId();
        if ($taxRegistrationId !== null) {
            return $taxRegistrationId->getValue();
        }

        $identifiers = $party->getIdentifiers();
        if (!empty($identifiers)) {
            return $identifiers[0]->getValue();
        }

        return null;
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
