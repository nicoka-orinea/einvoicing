<?php

namespace Einvoicing\Writers;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\Line as Flux10Line;
use Einvoicing\Flux10\Location as Flux10Location;
use Einvoicing\Flux10\Note as Flux10Note;
use Einvoicing\Flux10\Price as Flux10Price;
use Einvoicing\Flux10\ReferencedDocument as Flux10ReferencedDocument;
use Einvoicing\Flux10\AllowanceCharge as Flux10AllowanceCharge;
use Einvoicing\Flux10\Delivery as Flux10Delivery;
use Einvoicing\Flux10\InvoicePayment as Flux10InvoicePayment;
use Einvoicing\Flux10\Issuer as Flux10Issuer;
use Einvoicing\Flux10\Enums\BusinessProcessCode;
use Einvoicing\Flux10\Enums\IcdSchemeId;
use Einvoicing\Flux10\Enums\VatCategoryCode;
use Einvoicing\Flux10\Enums\IssuerRoleCode as Flux10IssuerRoleCode;
use Einvoicing\Flux10\AmountByRate as Flux10AmountByRate;
use Einvoicing\Flux10\Period as Flux10Period;
use Einvoicing\Flux10\Report as Flux10Report;
use Einvoicing\Flux10\Sender as Flux10Sender;
use Einvoicing\Flux10\TaxBreakdown as Flux10TaxBreakdown;
use Einvoicing\Flux10\Transaction as Flux10Transaction;
use Einvoicing\Flux10\TransactionPayment as Flux10TransactionPayment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Models\VatBreakdown as InvoiceVatBreakdown;
use Einvoicing\Party;
use InvalidArgumentException;
use UXML\UXML;
use function get_debug_type;
use function implode;
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

    /** Profile identifier of the e-reporting flow — TT-29, S1.12 */
    public const EREPORTING_PROFILE = 'urn.cpro.gouv.fr:1p0:ereporting';

    /** VAT totals are always expressed in euros — TT-202, G6.23 */
    private const VAT_CURRENCY = 'EUR';

    /** Amounts carry at most 2 decimals — G1.14 */
    private const AMOUNT_DECIMALS = 2;

    /** Collected amounts and unit prices carry up to 6 — TT-95, TT-99, G7.07, G1.16 */
    private const PRICE_DECIMALS = 6;

    /** Quantities carry up to 4 decimals — TT-62, G1.15 */
    private const QUANTITY_DECIMALS = 4;

    /** An amount is capped at 19 digits, separator excluded — G1.14 */
    private const MAX_AMOUNT_DIGITS = 19;

    /**
     * Emitting accredited platform, used when building a report from plain invoices.
     * @var Flux10Sender|null
     */
    private $sender = null;

    /**
     * Declared period, used when building a report from plain invoices.
     * @var Flux10Period|null
     */
    private $period = null;

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
     * Get the declared reporting period.
     */
    public function getPeriod(): ?Flux10Period
    {
        return $this->period;
    }

    /**
     * Set the declared reporting period (TG-7/TG-33).
     *
     * Without it, {@see exportAll()} infers the period from the invoice issue dates,
     * which yields a start equal to the end as soon as a single day is reported — a
     * period the PPF rejects (G6.25).
     */
    public function setPeriod(?Flux10Period $period): self
    {
        $this->period = $period;
        return $this;
    }

    /**
     * Export one or more invoices, or an already prepared Flux 10 report.
     *
     * An EN 16931 invoice carries neither the emitting platform, nor the transmission
     * identifier, nor the declared period, so this path has to guess them — and a guessed
     * envelope is rejected by the PPF.
     *
     * @param array<int,Invoice|Flux10Report> $invoices Invoices or a single Flux 10 report
     * @deprecated 0.3.0
     * @see \Einvoicing\Flux10\ReportBuilder
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
     *
     * @deprecated 0.3.0
     * @see \Einvoicing\Flux10\ReportBuilder
     */
    public function export(Invoice $invoice): string
    {
        // Both entry points are deprecated together
        // @phan-suppress-next-line PhanDeprecatedFunction
        return $this->exportAll([$invoice]);
    }

    /**
     * Export an already prepared Flux 10 report to XML.
     */
    public function exportReport(Flux10Report $report, bool $validate = true): string
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

        if ($validate) {
            $report->validate();
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

        $reportDocument->add('TypeCode', $report->getTransmissionType()->value);

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

            $this->addNotes($node, 'IncludedNote', $invoice->getNotes(), 'Subject', 'Content');

            $businessProcess = $node->add('BusinessProcess');
            $this->addRequiredStringNode($businessProcess, 'ID', $invoice->getBusinessProcessId()?->value, 'Invoice/BusinessProcess/ID');
            $this->addRequiredStringNode($businessProcess, 'TypeID', $invoice->getBusinessProcessTypeId(), 'Invoice/BusinessProcess/TypeID');

            foreach ($invoice->getReferencedDocuments() as $reference) {
                $referenceNode = $node->add('ReferencedDocument');
                $this->addRequiredStringNode($referenceNode, 'ID', $reference->getId(), 'Invoice/ReferencedDocument/ID');
                $this->addDateNode($referenceNode, 'IssueDate', $reference->getIssueDate());
            }

            $seller = $node->add('Seller');
            $this->addRequiredSchemeValueNode(
                $seller,
                'CompanyId',
                $invoice->getSellerId(),
                $invoice->getSellerSchemeId()?->value,
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
                    $this->addSchemeValueNode($buyer, 'CompanyId', $invoice->getBuyerId(), $invoice->getBuyerSchemeId()?->value, 'Invoice/Buyer/CompanyId');
                }
                if ($invoice->getBuyerVatId() !== null && $invoice->getBuyerVatId() !== '') {
                    $buyer->add('TaxRegistrationId', $invoice->getBuyerVatId(), ['qualifyingId' => 'VAT']);
                }
                if ($invoice->getBuyerCountry() !== null && $invoice->getBuyerCountry() !== '') {
                    $buyer->add('PostalAddress')->add('CountryId', $invoice->getBuyerCountry());
                }
            }

            if ($invoice->getSellerTaxRepresentativeVatId() !== null && $invoice->getSellerTaxRepresentativeVatId() !== '') {
                $this->addRequiredSchemeValueNode(
                    $node->add('SellerTaxRepresentative'),
                    'TaxRegistrationId',
                    $invoice->getSellerTaxRepresentativeVatId(),
                    $invoice->getSellerTaxRepresentativeSchemeId(),
                    'Invoice/SellerTaxRepresentative/TaxRegistrationId'
                );
            }

            foreach ($invoice->getDeliveries() as $delivery) {
                $deliveryNode = $node->add('Delivery');
                $this->addDateNode($deliveryNode, 'Date', $delivery->getDate());
                $this->addLocation($deliveryNode, $delivery->getLocation(), false);
            }

            $this->addPeriod($node, 'InvoicePeriod', $invoice->getInvoicePeriod());

            foreach ($invoice->getAllowancesCharges() as $allowanceCharge) {
                $allowanceNode = $node->add('AllowanceCharge', null, [
                    'ChargeIndicator' => $allowanceCharge->isCharge() ? 'true' : 'false',
                ]);
                $this->addAmountNode($allowanceNode, 'Amount', $allowanceCharge->getAmount());
                $this->addStringNode($allowanceNode, 'TaxCategoryCode', $allowanceCharge->getTaxCategoryCode()?->value);
                $this->addAmountNode($allowanceNode, 'TaxPercent', $allowanceCharge->getTaxPercent());
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

            foreach ($invoice->getLines() as $line) {
                $this->addInvoiceLine($node, $line);
            }
        }
    }

    /**
     * Serialize an invoice line — TG-24, in the order transaction.xsd declares.
     */
    private function addInvoiceLine(UXML $invoiceNode, Flux10Line $line): void
    {
        $node = $invoiceNode->add('Line');

        $this->addNotes($node, 'Note', $line->getNotes(), 'Code', 'Comment');

        $quantity = $this->formatAmount($line->getBilledQuantity(), self::QUANTITY_DECIMALS);
        if ($quantity !== null) {
            $attributes = [];
            if ($line->getUnitCode() !== null && $line->getUnitCode() !== '') {
                $attributes['UnitCode'] = $line->getUnitCode();
            }
            $node->add('BilledQuantity', $quantity, $attributes);
        }

        $reference = $line->getReferencedDocument();
        if ($reference !== null) {
            $referenceNode = $node->add('ReferencedDocument');
            $this->addStringNode($referenceNode, 'ID', $reference->getId());
            $this->addDateNode($referenceNode, 'IssueDate', $reference->getIssueDate());
        }

        $delivery = $line->getDelivery();
        if ($delivery !== null) {
            $deliveryNode = $node->add('Delivery');
            $this->addStringNode($deliveryNode, 'Name', $delivery->getName());
            $this->addLocation($deliveryNode, $delivery->getLocation(), true);
        }

        $this->addPeriod($node, 'InvoicePeriod', $line->getInvoicePeriod());

        foreach ($line->getAllowancesCharges() as $allowanceCharge) {
            $allowanceNode = $node->add('AllowanceCharge', null, [
                'ChargeIndicator' => $allowanceCharge->isCharge() ? 'true' : 'false',
            ]);
            $this->addRequiredAmountNode($allowanceNode, 'Amount', $allowanceCharge->getAmount(), 'Invoice/Line/AllowanceCharge/Amount');
        }

        $price = $line->getPrice();
        if ($price !== null && !$price->isEmpty()) {
            $priceNode = $node->add('Price');
            $this->addAmountNode($priceNode, 'PriceAmount', $price->getPriceAmount(), self::PRICE_DECIMALS);
            $this->addAmountNode($priceNode, 'AllowanceChargeAmount', $price->getAllowanceChargeAmount(), self::PRICE_DECIMALS);
            $this->addAmountNode($priceNode, 'AllowanceChargeBaseAmount', $price->getAllowanceChargeBaseAmount(), self::PRICE_DECIMALS);
        }

        if ($line->getProductName() !== null && $line->getProductName() !== '') {
            $node->add('Product')->add('Name', $line->getProductName());
        }
    }

    /**
     * Serialize notes, whose two children are named differently on the invoice (Subject,
     * Content) and on a line (Code, Comment).
     *
     * @param Flux10Note[] $notes
     */
    private function addNotes(UXML $parent, string $element, array $notes, string $subjectName, string $contentName): void
    {
        foreach ($notes as $note) {
            $noteNode = $parent->add($element);
            $this->addStringNode($noteNode, $subjectName, $note->getSubject());
            $this->addStringNode($noteNode, $contentName, $note->getContent());
        }
    }

    /**
     * Serialize a delivery address — TG-19 on the invoice, TG-42 on a line.
     *
     * The line variant drops the second and third address lines and makes the country
     * mandatory (TT-307).
     */
    private function addLocation(UXML $parent, ?Flux10Location $location, bool $onLine): void
    {
        if ($location === null || $location->isEmpty()) {
            return;
        }

        $node = $parent->add('Location');
        $this->addStringNode($node, 'LineOne', $location->getLineOne());
        if (!$onLine) {
            $this->addStringNode($node, 'LineTwo', $location->getLineTwo());
            $this->addStringNode($node, 'LineThree', $location->getLineThree());
        }
        $this->addStringNode($node, 'CityName', $location->getCityName());
        $this->addStringNode($node, 'PostalZone', $location->getPostalZone());
        $this->addStringNode($node, 'CountrySubentity', $location->getCountrySubentity());

        if ($onLine) {
            $this->addRequiredStringNode($node, 'CountryId', $location->getCountryId(), 'Invoice/Line/Delivery/Location/CountryId');
        } else {
            $this->addStringNode($node, 'CountryId', $location->getCountryId());
        }
    }

    /**
     * Serialize a start/end date pair — TG-18 on the invoice, TG-25 on a line.
     */
    private function addPeriod(UXML $parent, string $element, ?Flux10Period $period): void
    {
        if ($period === null) {
            return;
        }

        $start = $this->formatDate($period->getStartDate());
        $end = $this->formatDate($period->getEndDate());
        if ($start === null && $end === null) {
            return;
        }

        $node = $parent->add($element);
        if ($start !== null) {
            $node->add('StartDate', $start);
        }
        if ($end !== null) {
            $node->add('EndDate', $end);
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
                $this->addRequiredAmountNode($subTotalNode, 'Amount', $subTotal->getAmount(), 'PaymentsReport/Invoice/Payment/SubTotals/Amount', self::PRICE_DECIMALS);
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
            $this->addRequiredStringNode($node, 'CategoryCode', $transaction->getCategoryCode()?->value, 'Transactions/CategoryCode');
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
                $this->addRequiredAmountNode($subTotalNode, 'Amount', $amountByRate->getAmount(), 'PaymentsReport/Transactions/Payment/SubTotals/Amount', self::PRICE_DECIMALS);
            }
        }
    }

    private function addInvoiceTaxSubtotal(UXML $invoiceNode, Flux10TaxBreakdown $item): void
    {
        $node = $invoiceNode->add('TaxSubTotal');
        $this->addRequiredAmountNode($node, 'TaxableAmount', $item->getTaxableAmount(), 'Invoice/TaxSubTotal/TaxableAmount');
        $this->addRequiredAmountNode($node, 'TaxAmount', $item->getTaxAmount(), 'Invoice/TaxSubTotal/TaxAmount');

        $taxCategory = $node->add('TaxCategory');
        $this->addStringNode($taxCategory, 'Code', $item->getCategoryCode()?->value);
        $this->addRequiredAmountNode($taxCategory, 'Percent', $item->getRate(), 'Invoice/TaxSubTotal/TaxCategory/Percent');
        $this->addStringNode($taxCategory, 'TaxExemptionReason', $item->getExemptionReason());
        $this->addStringNode($taxCategory, 'TaxExemptionReasonCode', $item->getExemptionReasonCode());
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

        if ($this->period !== null) {
            $report->setPeriod($this->period);
        } elseif ($issueDateBounds['start'] !== null && $issueDateBounds['end'] !== null) {
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
        $framework = BusinessProcessCode::tryFrom((string) $invoice->getBusinessProcess());
        if ($framework === null) {
            throw new ValidationException(sprintf(
                'Invoice "%s" carries "%s" as business process; Flux 10 expects an invoicing framework code (%s). ' .
                'Presets set their own specification URN here, so it must be overridden with setBusinessProcess().',
                $invoice->getNumber() ?? '',
                $invoice->getBusinessProcess() ?? '',
                implode(', ', array_column(BusinessProcessCode::cases(), 'value'))
            ), 'G1.02');
        }

        $fluxInvoice = new Flux10Invoice();
        $fluxInvoice->setInvoiceId($invoice->getNumber());
        $fluxInvoice->setIssueDate($invoice->getIssueDate());
        $fluxInvoice->setTypeCode((string) $invoice->getType());
        $fluxInvoice->setCurrencyCode($invoice->getCurrency());
        $fluxInvoice->setDueDate($invoice->getDueDate());
        $fluxInvoice->setBusinessProcessId($framework);
        $fluxInvoice->setBusinessProcessTypeId(self::EREPORTING_PROFILE);

        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();

        $fluxInvoice->setSellerCountry($this->getPartyCountry($seller));
        $fluxInvoice->setBuyerCountry($this->getPartyCountry($buyer));

        $sellerIdentifier = $this->getPartyIdentifier($seller);
        $buyerIdentifier = $this->getPartyIdentifier($buyer);
        $fluxInvoice->setSellerId($sellerIdentifier?->getValue() ?? ($seller?->getVatNumber()));
        $fluxInvoice->setSellerSchemeId($this->resolveIcdScheme($sellerIdentifier, $this->getPartyCountry($seller)));
        $fluxInvoice->setSellerVatId($seller?->getVatNumber());

        $fluxInvoice->setBuyerId($buyerIdentifier?->getValue() ?? ($buyer?->getVatNumber()));
        $fluxInvoice->setBuyerSchemeId($this->resolveIcdScheme($buyerIdentifier, $this->getPartyCountry($buyer)));
        $fluxInvoice->setBuyerVatId($buyer?->getVatNumber());

        foreach ($invoice->getPrecedingInvoiceReferences() as $reference) {
            $fluxInvoice->addReferencedDocument(
                new Flux10ReferencedDocument($reference->getValue(), $reference->getIssueDate())
            );
        }

        foreach ($invoice->getDocumentNotes() as $note) {
            $fluxInvoice->addNote(new Flux10Note($note->getContent(), $note->getSubjectCode()));
        }

        $this->addDerivedInvoicePeriod($fluxInvoice, $invoice);
        $this->addDerivedDelivery($fluxInvoice, $invoice);

        foreach ($invoice->getLines() as $line) {
            $fluxInvoice->addLine($this->buildFlux10Line($line));
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

    /**
     * Carry the invoicing period over — TG-18.
     */
    private function addDerivedInvoicePeriod(Flux10Invoice $fluxInvoice, Invoice $invoice): void
    {
        $start = $invoice->getPeriodStartDate();
        $end = $invoice->getPeriodEndDate();
        if ($start === null && $end === null) {
            return;
        }

        $fluxInvoice->setInvoicePeriod(
            (new Flux10Period())->setStartDate($start)->setEndDate($end)
        );
    }

    /**
     * Carry the delivery date and address over — TG-17.
     */
    private function addDerivedDelivery(Flux10Invoice $fluxInvoice, Invoice $invoice): void
    {
        $delivery = $invoice->getDelivery();
        if ($delivery === null) {
            return;
        }

        $address = $delivery->getAddress();
        $location = (new Flux10Location())
            ->setLineOne($address[0] ?? null)
            ->setLineTwo($address[1] ?? null)
            ->setLineThree($address[2] ?? null)
            ->setCityName($delivery->getCity())
            ->setPostalZone($delivery->getPostalCode())
            ->setCountrySubentity($delivery->getSubdivision())
            ->setCountryId($delivery->getCountry());

        $fluxDelivery = (new Flux10Delivery())->setDate($delivery->getDate());
        if (!$location->isEmpty()) {
            $fluxDelivery->setLocation($location);
        }

        $fluxInvoice->addDelivery($fluxDelivery);
    }

    /**
     * Build a Flux 10 line from an EN 16931 one — TG-24.
     */
    private function buildFlux10Line(InvoiceLine $line): Flux10Line
    {
        $fluxLine = (new Flux10Line())
            ->setBilledQuantity($line->getQuantity())
            ->setUnitCode($line->getUnit())
            ->setProductName($line->getName());

        if ($line->getNote() !== null) {
            $fluxLine->addNote(new Flux10Note($line->getNote()));
        }

        // Only the net price has an EN 16931 counterpart; the gross price and its
        // discount (TT-70/TT-71) are set by the caller when known.
        $price = (new Flux10Price())->setPriceAmount($line->getPrice());
        if (!$price->isEmpty()) {
            $fluxLine->setPrice($price);
        }

        // TT-67/TT-68 expect a monetary amount; an EN 16931 allowance may be a
        // percentage, which only resolves against the line base.
        $base = $line->getNetAmountBeforeAllowancesCharges() ?? 0.0;
        foreach ($line->getAllowances() as $allowance) {
            $fluxLine->addAllowanceCharge(new Flux10AllowanceCharge($allowance->getEffectiveAmount($base), false));
        }
        foreach ($line->getCharges() as $charge) {
            $fluxLine->addAllowanceCharge(new Flux10AllowanceCharge($charge->getEffectiveAmount($base), true));
        }

        return $fluxLine;
    }

    private function buildFlux10TaxBreakdown(InvoiceVatBreakdown $item): Flux10TaxBreakdown
    {
        $fluxItem = new Flux10TaxBreakdown();
        $fluxItem->setRate($item->rate);
        $fluxItem->setTaxableAmount($item->taxableAmount);
        $fluxItem->setTaxAmount($item->taxAmount);
        $fluxItem->setCategoryCode(VatCategoryCode::tryFrom((string) $item->category));
        $fluxItem->setExemptionReason($item->exemptionReason);
        $fluxItem->setExemptionReasonCode($item->exemptionReasonCode);

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

    private function addRequiredAmountNode(UXML $node, string $name, $amount, string $context, int $decimals = self::AMOUNT_DECIMALS): void
    {
        $formatted = $this->formatAmount($amount, $decimals);
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

    private function addAmountNode(UXML $node, string $name, $amount, int $decimals = self::AMOUNT_DECIMALS): void
    {
        $formatted = $this->formatAmount($amount, $decimals);
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

    /**
     * Format an amount with the precision the annexe defines for that field.
     *
     * Amounts carry 2 decimals (G1.14), collected amounts and unit prices up to 6
     * (G7.07, G1.16), quantities 4 (G1.15).
     */
    private function formatAmount($amount, int $decimals = self::AMOUNT_DECIMALS): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (!is_numeric($amount)) {
            return is_string($amount) ? $amount : null;
        }

        $formatted = number_format(round((float) $amount, $decimals, PHP_ROUND_HALF_UP), $decimals, '.', '');

        // Trailing zeros are not significant and would eat into the 19-digit budget
        if ($decimals > self::AMOUNT_DECIMALS) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            if (!str_contains($formatted, '.')) {
                $formatted = number_format((float) $formatted, self::AMOUNT_DECIMALS, '.', '');
            }
        }

        $digits = strlen(str_replace(['.', '-'], '', $formatted));
        if ($digits > self::MAX_AMOUNT_DIGITS) {
            throw new ValidationException(sprintf(
                'The amount %s exceeds %d digits',
                $formatted,
                self::MAX_AMOUNT_DIGITS
            ), 'G1.14');
        }

        return $formatted;
    }

    /**
     * Resolve the ISO 6523 scheme of a party identifier — G2.19.
     *
     * An EN 16931 party carries whatever scheme its source system used ("VAT", SIRET
     * "0009", …), most of which are not admissible in Flux 10, so anything outside the
     * ICD list is re-derived from the country.
     */
    private function resolveIcdScheme(?Identifier $identifier, ?string $countryCode): ?IcdSchemeId
    {
        $scheme = IcdSchemeId::tryFrom((string) $identifier?->getScheme());
        if ($scheme !== null) {
            return $scheme;
        }

        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        return IcdSchemeId::fromCountry($countryCode);
    }
}
