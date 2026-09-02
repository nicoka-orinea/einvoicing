<?php

namespace Tests\Writers;

use DateTime;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\AmountByRate;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\InvoicePayment;
use Einvoicing\Flux10\Issuer;
use Einvoicing\Flux10\Enums\IssuerRoleCode;
use Einvoicing\Flux10\Period;
use Einvoicing\Flux10\Report;
use Einvoicing\Flux10\Sender;
use Einvoicing\Flux10\TaxBreakdown;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\Flux10Writer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The writer refuses incomplete input rather than filling it in.
 *
 * Every case here used to produce a schema-valid document carrying an invented value —
 * a fabricated 0% VAT breakdown, an "UNKNOWN" scheme, a VAT total in the wrong currency.
 * Those pass the XSD and are accepted by the PPF, which makes them worse than a failure:
 * the declaration is wrong and nothing says so.
 */
final class Flux10WriterGuardsTest extends TestCase
{
    /**
     * An accredited platform cannot be inferred from the invoices being reported — G6.22.
     */
    public function testReportWithoutSenderIsRefused(): void
    {
        $report = $this->minimalReport()->setSender(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accredited platform');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * A VAT total in a foreign currency must be converted by the caller — G6.23.
     */
    public function testForeignCurrencyInvoiceWithoutEuroVatTotalIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setCurrencyCode('USD');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be expressed in EUR');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * Once converted, the document keeps its own currency but the VAT total is in euros.
     */
    public function testForeignCurrencyInvoiceUsesTheConvertedVatTotal(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]
            ->setCurrencyCode('USD')
            ->setVatAmountEur(183.45);

        $xml = (new Flux10Writer())->exportReport($report);

        $this->assertStringContainsString('<CurrencyCode>USD</CurrencyCode>', $xml);
        $this->assertStringContainsString('<TaxAmount CurrencyCode="EUR">183.45</TaxAmount>', $xml);
    }

    /**
     * A missing VAT breakdown used to be replaced by a single 0% line carrying the whole
     * taxable amount — a valid rate (G1.24), so the wrong declaration went through.
     */
    public function testInvoiceWithoutVatBreakdownIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->clearTaxBreakdown();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no VAT breakdown');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * Same fabrication on the payments side: a lone total cannot be attributed to a rate.
     */
    public function testPaymentWithoutRateBreakdownIsRefused(): void
    {
        $report = $this->baseReport()->addInvoicePayment(
            (new InvoicePayment())
                ->setInvoiceId('INV-1')
                ->setIssueDate(new DateTime('2026-01-10'))
                ->setPaymentDate(new DateTime('2026-01-20'))
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('broken down by VAT rate');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * An identifier without its ISO 6523 scheme used to be emitted as "UNKNOWN" — G2.19.
     */
    public function testIdentifierWithoutSchemeIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setSellerSchemeId(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing identifier scheme');

        // Validation has nothing to check without a scheme; the writer is what refuses.
        (new Flux10Writer())->exportReport($report, false);
    }

    /**
     * A scheme outside the ISO 6523 list cannot even be held by the model — G2.19.
     */
    public function testNonIcdSchemeIsRejectedAtAssignment(): void
    {
        $this->expectException(\ValueError::class);

        (new Flux10Invoice())->setSellerSchemeId('0009');
    }

    /**
     * An identifier that does not fit its scheme is caught by validation — G2.19.
     */
    public function testIdentifierInconsistentWithItsSchemeIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setSellerId('12345');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match scheme 0002');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * Rates outside the accepted list are refused — G1.24.
     */
    public function testUnknownVatRateIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->getTaxBreakdown()[0]->setRate(17.5);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not in the accepted list');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * Totals must match their breakdown, within a cent — G1.53.
     */
    public function testTotalsInconsistentWithBreakdownAreRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setTaxExclusiveAmount(1500.00);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not match the sum of the taxable bases');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * An exempt breakdown carries both its reason and its code — G1.40.
     */
    public function testExemptBreakdownWithoutReasonIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->getTaxBreakdown()[0]->setRate(0)->setCategoryCode('E');
        $report->getInvoices()[0]->setTaxAmount(0)->setTaxExclusiveAmount(1000.00);
        $report->getInvoices()[0]->getTaxBreakdown()[0]->setTaxAmount(0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires both an exemption reason');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * B2C invoice reporting is not allowed, so the buyer must be identified — G6.28.
     */
    public function testInvoiceWithoutBuyerIdentifierIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setBuyerId(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B2C invoice reporting is not allowed');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * Only the UNTDID 1001 subset is usable in Flux 10 — G1.01.
     */
    public function testInvoiceTypeOutsideTheSubsetIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setTypeCode('325');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not allowed in Flux 10');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * The invoicing framework (B1, S1, M1, …) has no EN 16931 equivalent — G1.02.
     */
    public function testDerivedInvoiceWithoutInvoicingFrameworkIsRefused(): void
    {
        $writer = (new Flux10Writer())->setSender($this->sender());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expects an invoicing framework code');

        // The Peppol preset fills the business process with its own URN, so the value is
        // present but meaningless for Flux 10.
        $writer->export($this->en16931InvoiceWithoutBusinessProcess());
    }

    /**
     * An entry of the wrong type used to be skipped silently, shrinking the declaration.
     */
    public function testUnexpectedEntryTypeIsRefused(): void
    {
        $report = $this->baseReport();
        $report->addInvoice($this->flux10Invoice());
        // Bypass the typed setter the way a decoded payload would
        (new \ReflectionProperty(Report::class, 'invoices'))->setValue($report, [new \stdClass()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a Einvoicing\Flux10\Invoice, got stdClass');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * ISO dates are what UBL-shaped callers hold; Flux 10 wants them unseparated — G1.09.
     */
    public function testIsoDateStringsAreNormalized(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setIssueDate('2026-01-10');

        $xml = (new Flux10Writer())->exportReport($report);

        $this->assertStringContainsString('<IssueDate>20260110</IssueDate>', $xml);
    }

    /**
     * Anything else is a caller bug and must not reach the PPF — G1.09.
     */
    public function testUnreadableDateStringIsRefused(): void
    {
        $report = $this->minimalReport();
        $report->getInvoices()[0]->setIssueDate('10/01/2026');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expected AAAAMMJJ');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * The timestamp is stamped at export time unless the caller pins it — TT-3, G7.43.
     */
    public function testTransmissionTimestampIsPinnable(): void
    {
        $report = $this->minimalReport()->setIssueDateTime(new DateTime('2026-02-01 08:30:00'));

        $xml = (new Flux10Writer())->exportReport($report);

        $this->assertStringContainsString('<DateTimeString>20260201083000</DateTimeString>', $xml);
    }

    public function testTransmissionTimestampDefaultsToExportTime(): void
    {
        $xml = (new Flux10Writer())->exportReport($this->minimalReport());

        $this->assertMatchesRegularExpression('#<DateTimeString>\d{14}</DateTimeString>#', $xml);
    }

    // -----------------------------------------------------------------------------

    private function sender(): Sender
    {
        return (new Sender())->setMatricule('PA01')->setName('Accredited Platform SA');
    }

    private function baseReport(): Report
    {
        return (new Report())
            ->setReportId('REPORT-2026-01')
            ->setTransmissionType('IN')
            ->setSender($this->sender())
            ->setIssuer(
                (new Issuer())
                    ->setSiren('123456789')
                    ->setName('Issuer SA')
                    ->setRoleCode(IssuerRoleCode::SELLER)
            )
            ->setPeriod(
                (new Period())
                    ->setStartDate(new DateTime('2026-01-01'))
                    ->setEndDate(new DateTime('2026-01-31'))
            );
    }

    private function flux10Invoice(): Flux10Invoice
    {
        return (new Flux10Invoice())
            ->setInvoiceId('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setTypeCode('380')
            ->setCurrencyCode('EUR')
            ->setBusinessProcessId('S1')
            ->setBusinessProcessTypeId(Flux10Writer::EREPORTING_PROFILE)
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
                (new TaxBreakdown())->setRate(20)->setTaxableAmount(1000.00)->setTaxAmount(200.00)
            );
    }

    private function minimalReport(): Report
    {
        return $this->baseReport()->addInvoice($this->flux10Invoice());
    }

    private function en16931InvoiceWithoutBusinessProcess(): Invoice
    {
        $seller = (new Party())
            ->setCompanyId(new \Einvoicing\Identifier('123456789', '0002'))
            ->setName('Seller SA')
            ->setCountry('FR')
            ->setVatNumber('FR12345678901');

        return (new Invoice(Peppol::class))
            ->setNumber('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setSeller($seller)
            ->setBuyer((new Party())->setName('Buyer GmbH')->setCountry('DE'))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(1000)->setQuantity(1)->setVatRate(20));
    }
}
