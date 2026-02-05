<?php

namespace Tests\Writers;

use DateTime;
use DOMDocument;
use Einvoicing\Flux10\AmountByRate;
use Einvoicing\Flux10\InvoicePayment;
use Einvoicing\Flux10\Issuer;
use Einvoicing\Flux10\IssuerRoleCode;
use Einvoicing\Flux10\Party;
use Einvoicing\Flux10\Period;
use Einvoicing\Flux10\Report;
use Einvoicing\Flux10\TransactionPayment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\Flux10Writer;
use PHPUnit\Framework\TestCase;
use Einvoicing\Party as InvoiceParty;

final class Flux10WriterTest extends TestCase
{
    private function assertValidAgainstEreportingXsd(string $xml): void
    {
        $root = dirname(__DIR__, 2);
        $xsd = $root . '/specifications-externes-v3.1/3- XSD_v3.1/1 - E-reporting/ereporting.xsd';

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $dom->schemaValidate($xsd);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($valid) {
            $this->assertTrue(true);
            return;
        }

        $messages = [];
        foreach ($errors as $error) {
            $messages[] = trim($error->message);
        }
        $this->fail(implode("\n", $messages));
    }

    public function testCanGenerateXsdValidTransactionsReportFromInvoice(): void
    {
        $seller = (new InvoiceParty())
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller SA')
            ->setCountry('FR')
            ->setVatNumber('FR123456789');

        $buyer = (new InvoiceParty())
            ->setCompanyId(new Identifier('DE123456789', 'VAT'))
            ->setName('Buyer GmbH')
            ->setCountry('DE')
            ->setVatNumber('DE123456789');

        $invoice = new Invoice(Peppol::class);
        $invoice->setNumber('INV-1')
            ->setIssueDate(new DateTime('2025-01-10'))
            ->setBusinessProcess('PROCESS')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatRate(20));

        $writer = new Flux10Writer();
        $xml = $writer->export($invoice);
        $this->assertValidAgainstEreportingXsd($xml);
    }

    public function testCanGenerateXsdValidPaymentsReport(): void
    {
        $period = (new Period())
            ->setStartDate(new DateTime('2025-01-01'))
            ->setEndDate(new DateTime('2025-01-31'));

        $sender = (new Party())
            ->setSiren('123456789')
            ->setSchemeId('0002')
            ->setName('Sender SA');

        $issuer = (new Issuer())
            ->setSiren('123456789')
            ->setSchemeId('0002')
            ->setName('Issuer SA')
            ->setRoleCode(IssuerRoleCode::SELLER);

        $invoicePayment = (new InvoicePayment())
            ->setInvoiceId('INV-1')
            ->setIssueDate(new DateTime('2025-01-10'))
            ->setPaymentDate(new DateTime('2025-01-15'))
            ->setCurrencyCode('EUR')
            ->addAmountByRate((new AmountByRate())->setRate(20)->setAmount(120));

        $transactionPayment = (new TransactionPayment())
            ->setPaymentDate(new DateTime('2025-01-20'))
            ->setCurrencyCode('EUR')
            ->addAmountByRate((new AmountByRate())->setRate(10)->setAmount(50));

        $report = (new Report())
            ->setReportId('REPORT-1')
            ->setTransmissionType('IN')
            ->setSender($sender)
            ->setIssuer($issuer)
            ->setPeriod($period)
            ->addInvoicePayment($invoicePayment)
            ->addTransactionPayment($transactionPayment);

        $writer = new Flux10Writer();
        $xml = $writer->exportReport($report);
        $this->assertValidAgainstEreportingXsd($xml);
    }
}

