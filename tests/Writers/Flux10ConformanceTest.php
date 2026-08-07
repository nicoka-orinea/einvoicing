<?php

namespace Tests\Writers;

use DateTime;
use DOMDocument;
use Einvoicing\Flux10\AmountByRate;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\InvoicePayment;
use Einvoicing\Flux10\Issuer;
use Einvoicing\Flux10\IssuerRoleCode;
use Einvoicing\Flux10\Party as Flux10Party;
use Einvoicing\Flux10\Period;
use Einvoicing\Flux10\Report;
use Einvoicing\Flux10\TaxBreakdown;
use Einvoicing\Flux10\Transaction;
use Einvoicing\Flux10\TransactionPayment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\Flux10Writer;
use PHPUnit\Framework\TestCase;

/**
 * Conformance harness for the Flux 10 e-reporting export.
 *
 * `Flux10WriterTest` guards serialization against the XSD and must stay green. This
 * class guards it against the *specification*, which the XSD cannot express, and is
 * expected to be red until the format and identification work lands. Its failures are
 * the backlog: each one names the TT/TG field and the G rule it breaks.
 *
 * Golden fixtures record the current output so later changes surface as readable diffs
 * rather than silent drift. They are snapshots, not targets — see fixtures/README.md.
 */
final class Flux10ConformanceTest extends TestCase
{
    use Flux10SemanticAssertions;

    private const FIXTURES = __DIR__ . '/flux10';

    /**
     * Sub-flux 10.1 — invoice-level reporting (B2B international).
     */
    public function testSubFlux101InvoiceReport(): void
    {
        $xml = (new Flux10Writer())->exportReport($this->reportWithInvoice());

        $this->assertMatchesGoldenFixture($xml, '10.1-invoices.xml');
        $this->assertFlux10Semantics($xml);
    }

    /**
     * Sub-flux 10.3 — aggregated transactions (B2C).
     */
    public function testSubFlux103TransactionReport(): void
    {
        $xml = (new Flux10Writer())->exportReport($this->reportWithTransactions());

        $this->assertMatchesGoldenFixture($xml, '10.3-transactions.xml');
        $this->assertFlux10Semantics($xml);
    }

    /**
     * Sub-flux 10.2 — payments collected against invoices.
     */
    public function testSubFlux102InvoicePaymentReport(): void
    {
        $xml = (new Flux10Writer())->exportReport($this->reportWithInvoicePayments());

        $this->assertMatchesGoldenFixture($xml, '10.2-invoice-payments.xml');
        $this->assertFlux10Semantics($xml);
    }

    /**
     * Sub-flux 10.4 — payments collected against aggregated transactions.
     */
    public function testSubFlux104TransactionPaymentReport(): void
    {
        $xml = (new Flux10Writer())->exportReport($this->reportWithTransactionPayments());

        $this->assertMatchesGoldenFixture($xml, '10.4-transaction-payments.xml');
        $this->assertFlux10Semantics($xml);
    }

    /**
     * The path taken by callers holding EN 16931 invoices, which derives the whole
     * transmission envelope by inference.
     */
    public function testDerivedReportFromEn16931Invoice(): void
    {
        $xml = (new Flux10Writer())->export($this->en16931Invoice());

        $this->assertMatchesGoldenFixture($xml, '10.1-derived-from-invoice.xml');
        $this->assertFlux10Semantics($xml);
    }

    /**
     * A transmission carries transactions or payments, never both — G6.29.
     */
    public function testMixedTransmissionIsRejected(): void
    {
        $report = $this->reportWithInvoice()
            ->addInvoicePayment(
                (new InvoicePayment())
                    ->setInvoiceId('INV-1')
                    ->setIssueDate(new DateTime('2026-01-10'))
                    ->setPaymentDate(new DateTime('2026-01-15'))
                    ->addAmountByRate((new AmountByRate())->setRate(20)->setAmount(120))
            );

        $xml = (new Flux10Writer())->exportReport($report);

        $this->assertContains(
            'Report carries both TransactionsReport and PaymentsReport — they must be transmitted separately (G6.29)',
            $this->findFlux10Violations($xml)
        );
    }

    // -----------------------------------------------------------------------------
    // Report builders
    // -----------------------------------------------------------------------------

    private function baseReport(): Report
    {
        $sender = (new Flux10Party())
            ->setSiren('123456789')
            ->setSchemeId('0002')
            ->setName('Sender SA');

        $issuer = (new Issuer())
            ->setSiren('123456789')
            ->setSchemeId('0002')
            ->setName('Issuer SA')
            ->setRoleCode(IssuerRoleCode::SELLER);

        return (new Report())
            ->setReportId('REPORT-2026-01')
            ->setTransmissionType('IN')
            ->setSender($sender)
            ->setIssuer($issuer)
            ->setPeriod(
                (new Period())
                    ->setStartDate(new DateTime('2026-01-01'))
                    ->setEndDate(new DateTime('2026-01-31'))
            );
    }

    private function reportWithInvoice(): Report
    {
        $invoice = (new Flux10Invoice())
            ->setInvoiceId('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setTypeCode('380')
            ->setCurrencyCode('EUR')
            ->setDueDate(new DateTime('2026-02-09'))
            ->setBusinessProcessId('S1')
            ->setBusinessProcessTypeId('urn.cpro.gouv.fr:1p0:ereporting')
            ->setSellerId('123456789')
            ->setSellerSchemeId('0002')
            ->setSellerVatId('FR12345678901')
            ->setSellerCountry('FR')
            ->setBuyerId('DE123456789012')
            ->setBuyerSchemeId('0223')
            ->setBuyerVatId('DE123456789')
            ->setBuyerCountry('DE')
            ->setTaxExclusiveAmount(1000.00)
            ->setTaxAmount(200.00)
            ->addTaxBreakdownItem(
                (new TaxBreakdown())
                    ->setRate(20)
                    ->setTaxableAmount(1000.00)
                    ->setTaxAmount(200.00)
            );

        return $this->baseReport()->addInvoice($invoice);
    }

    private function reportWithTransactions(): Report
    {
        $transaction = (new Transaction())
            ->setDate(new DateTime('2026-01-15'))
            ->setCurrencyCode('EUR')
            ->setCategoryCode('TPS1')
            ->setTaxExclusiveAmount(5000.00)
            ->setTaxAmount(1000.00)
            ->setTransactionCount(42)
            ->addTaxBreakdownItem(
                (new TaxBreakdown())
                    ->setRate(20)
                    ->setTaxableAmount(5000.00)
                    ->setTaxAmount(1000.00)
            );

        return $this->baseReport()->addTransaction($transaction);
    }

    private function reportWithInvoicePayments(): Report
    {
        $payment = (new InvoicePayment())
            ->setInvoiceId('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setPaymentDate(new DateTime('2026-01-20'))
            ->setCurrencyCode('EUR')
            ->addAmountByRate((new AmountByRate())->setRate(20)->setAmount(1200.00));

        return $this->baseReport()->addInvoicePayment($payment);
    }

    private function reportWithTransactionPayments(): Report
    {
        $payment = (new TransactionPayment())
            ->setPaymentDate(new DateTime('2026-01-25'))
            ->setCurrencyCode('EUR')
            ->addAmountByRate((new AmountByRate())->setRate(20)->setAmount(6000.00));

        return $this->baseReport()->addTransactionPayment($payment);
    }

    private function en16931Invoice(): Invoice
    {
        $seller = (new Party())
            ->setElectronicAddress(new Identifier('12345678900023', '0225'))
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller SA')
            ->setCountry('FR')
            ->setVatNumber('FR12345678901');

        $buyer = (new Party())
            ->setCompanyId(new Identifier('DE123456789', 'VAT'))
            ->setName('Buyer GmbH')
            ->setCountry('DE')
            ->setVatNumber('DE123456789');

        return (new Invoice(Peppol::class))
            ->setNumber('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setBusinessProcess('S1')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(1000)->setQuantity(1)->setVatRate(20));
    }

    // -----------------------------------------------------------------------------
    // Golden fixtures
    // -----------------------------------------------------------------------------

    /**
     * Compare against the recorded snapshot, writing it on first run.
     */
    private function assertMatchesGoldenFixture(string $xml, string $filename): void
    {
        $path = self::FIXTURES . '/' . $filename;
        $actual = $this->normalizeForComparison($xml);

        if (!file_exists($path)) {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Recorded new golden fixture: {$filename}");
        }

        $this->assertXmlStringEqualsXmlString(file_get_contents($path), $actual, "Serialization drift in {$filename}");
    }

    /**
     * The transmission timestamp is generated at export time and would otherwise make
     * every comparison fail. Pin it so the fixture stays about structure.
     */
    private function normalizeForComparison(string $xml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml);

        foreach ($dom->getElementsByTagName('DateTimeString') as $node) {
            $node->textContent = '@@TIMESTAMP@@';
        }

        return $dom->saveXML();
    }
}
