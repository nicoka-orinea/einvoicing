<?php

namespace Tests\Writers;

use DateTime;
use DOMDocument;
use DOMXPath;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\AllowanceCharge;
use Einvoicing\Flux10\Delivery;
use Einvoicing\Flux10\Enums\IssuerRoleCode;
use Einvoicing\Flux10\Invoice as Flux10Invoice;
use Einvoicing\Flux10\Issuer;
use Einvoicing\Flux10\Line;
use Einvoicing\Flux10\Location;
use Einvoicing\Flux10\Note;
use Einvoicing\Flux10\Period;
use Einvoicing\Flux10\Price;
use Einvoicing\Flux10\ReferencedDocument;
use Einvoicing\Flux10\Report;
use Einvoicing\Flux10\Sender;
use Einvoicing\Flux10\TaxBreakdown;
use Einvoicing\Writers\Flux10Writer;
use PHPUnit\Framework\TestCase;

/**
 * The detail of sub-flux 10.1 — the blocks that make a real B2B international invoice
 * reportable rather than just its totals.
 */
final class Flux10InvoiceDetailTest extends TestCase
{
    use Flux10SemanticAssertions;

    /**
     * Credit notes and corrective invoices were not reportable at all without TG-11:
     * G1.32 requires them to name the invoice they amend.
     */
    public function testCreditNoteCarriesItsHeaderReference(): void
    {
        $invoice = $this->invoice()
            ->setTypeCode('381')
            ->addReferencedDocument(new ReferencedDocument('INV-ORIGIN', new DateTime('2025-12-05')));

        $xml = (new Flux10Writer())->exportReport($this->report($invoice));

        $this->assertFlux10Semantics($xml);
        $this->assertSame('INV-ORIGIN', $this->text($xml, '//Invoice/ReferencedDocument/ID'));
        $this->assertSame('20251205', $this->text($xml, '//Invoice/ReferencedDocument/IssueDate'));
    }

    public function testCreditNoteWithoutAnyReferenceIsRefused(): void
    {
        $report = $this->report($this->invoice()->setTypeCode('381'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must reference at least one earlier invoice');

        (new Flux10Writer())->exportReport($report);
    }

    /**
     * A credit note may reference per line instead of in the header — G1.32.
     */
    public function testCreditNoteMayReferencePerLine(): void
    {
        $invoice = $this->invoice()
            ->setTypeCode('381')
            ->addLine(
                (new Line())
                    ->setProductName('Refunded item')
                    ->setReferencedDocument(new ReferencedDocument('INV-ORIGIN', new DateTime('2025-12-05')))
            );

        $xml = (new Flux10Writer())->exportReport($this->report($invoice));

        $this->assertFlux10Semantics($xml);
        $this->assertSame('INV-ORIGIN', $this->text($xml, '//Invoice/Line/ReferencedDocument/ID'));
    }

    /**
     * A partially referenced credit note is not acceptable: G1.32 asks for every line.
     */
    public function testCreditNoteWithPartiallyReferencedLinesIsRefused(): void
    {
        $invoice = $this->invoice()
            ->setTypeCode('381')
            ->addLine(
                (new Line())
                    ->setProductName('Refunded item')
                    ->setReferencedDocument(new ReferencedDocument('INV-ORIGIN', new DateTime('2025-12-05')))
            )
            ->addLine((new Line())->setProductName('Unreferenced item'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('on every line');

        (new Flux10Writer())->exportReport($this->report($invoice));
    }

    /**
     * A corrective invoice references exactly one earlier invoice — G1.32.
     */
    public function testCorrectiveInvoiceWithTwoReferencesIsRefused(): void
    {
        $invoice = $this->invoice()
            ->setTypeCode('384')
            ->addReferencedDocument(new ReferencedDocument('INV-A', new DateTime('2025-12-05')))
            ->addReferencedDocument(new ReferencedDocument('INV-B', new DateTime('2025-12-06')));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exactly one earlier invoice');

        (new Flux10Writer())->exportReport($this->report($invoice));
    }

    public function testCorrectiveInvoiceWithoutReferenceDateIsRefused(): void
    {
        $invoice = $this->invoice()
            ->setTypeCode('384')
            ->addReferencedDocument(new ReferencedDocument('INV-A'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('date of the invoice it amends');

        (new Flux10Writer())->exportReport($this->report($invoice));
    }

    /**
     * The whole header subtree, in the order transaction.xsd declares it.
     */
    public function testHeaderBlocksAreSerializedInSchemaOrder(): void
    {
        $invoice = $this->invoice()
            ->addNote(new Note('Membre d\'un assujetti unique', 'TXD'))
            ->setSellerTaxRepresentativeVatId('FR99999999999')
            ->setSellerTaxRepresentativeSchemeId('0002')
            ->addDelivery(
                (new Delivery())
                    ->setDate(new DateTime('2026-01-08'))
                    ->setLocation(
                        (new Location())
                            ->setLineOne('12 rue des Lilas')
                            ->setCityName('Berlin')
                            ->setPostalZone('10115')
                            ->setCountryId('DE')
                    )
            )
            ->setInvoicePeriod(
                (new Period())->setStartDate(new DateTime('2026-01-01'))->setEndDate(new DateTime('2026-01-31'))
            )
            ->addAllowanceCharge((new AllowanceCharge(50.00, false))->setTaxCategoryCode('S')->setTaxPercent(20));

        $xml = (new Flux10Writer())->exportReport($this->report($invoice));

        $this->assertFlux10Semantics($xml);
        $this->assertSame(
            [
                'ID', 'IssueDate', 'TypeCode', 'CurrencyCode', 'IncludedNote', 'BusinessProcess',
                'Seller', 'Buyer', 'SellerTaxRepresentative', 'Delivery', 'InvoicePeriod',
                'AllowanceCharge', 'MonetaryTotal', 'TaxSubTotal',
            ],
            $this->childNames($xml, '//TransactionsReport/Invoice')
        );

        $this->assertSame('TXD', $this->text($xml, '//Invoice/IncludedNote/Subject'));
        $this->assertSame('20260108', $this->text($xml, '//Invoice/Delivery/Date'));
        $this->assertSame('10115', $this->text($xml, '//Invoice/Delivery/Location/PostalZone'));
        $this->assertSame('false', $this->attribute($xml, '//Invoice/AllowanceCharge', 'ChargeIndicator'));
    }

    /**
     * The line subtree, likewise — and its own decimal precisions.
     */
    public function testLineBlocksAreSerializedInSchemaOrder(): void
    {
        $invoice = $this->invoice()->addLine(
            (new Line())
                ->addNote(new Note('Eco-participation (L. 541-10 du code de l\'environnement)', 'BLU'))
                ->setBilledQuantity(2.5)
                ->setUnitCode('C62')
                ->setDelivery(
                    (new Delivery())
                        ->setName('Warehouse')
                        ->setLocation((new Location())->setLineOne('1 Hauptstrasse')->setCountryId('DE'))
                )
                ->setInvoicePeriod(
                    (new Period())->setStartDate(new DateTime('2026-01-01'))->setEndDate(new DateTime('2026-01-31'))
                )
                ->addAllowanceCharge(new AllowanceCharge(12.50, true))
                ->setPrice((new Price())->setPriceAmount(400.123456))
                ->setProductName('Consulting')
        );

        $xml = (new Flux10Writer())->exportReport($this->report($invoice));

        $this->assertFlux10Semantics($xml);
        $this->assertSame(
            ['Note', 'BilledQuantity', 'Delivery', 'InvoicePeriod', 'AllowanceCharge', 'Price', 'Product'],
            $this->childNames($xml, '//Invoice/Line')
        );

        // G1.15 and G1.16 cap the decimals rather than pad them: trailing zeros are
        // dropped so they do not eat into the 19-digit budget.
        $this->assertSame('2.5', $this->text($xml, '//Invoice/Line/BilledQuantity'));
        $this->assertSame('C62', $this->attribute($xml, '//Invoice/Line/BilledQuantity', 'UnitCode'));
        $this->assertSame('400.123456', $this->text($xml, '//Invoice/Line/Price/PriceAmount'));
        $this->assertSame('true', $this->attribute($xml, '//Invoice/Line/AllowanceCharge', 'ChargeIndicator'));
        $this->assertSame('BLU', $this->text($xml, '//Invoice/Line/Note/Code'));
    }

    /**
     * An exempt breakdown carries its reason and code — G1.40 — in schema order.
     */
    public function testExemptBreakdownCarriesItsReason(): void
    {
        $invoice = $this->invoice()
            ->setTaxAmount(0)
            ->clearTaxBreakdown()
            ->addTaxBreakdownItem(
                (new TaxBreakdown())
                    ->setRate(0)
                    ->setTaxableAmount(1000.00)
                    ->setTaxAmount(0)
                    ->setCategoryCode('E')
                    ->setExemptionReason('Exonération de TVA — article 262 ter I du CGI')
                    ->setExemptionReasonCode('VATEX-EU-IC')
            );

        $xml = (new Flux10Writer())->exportReport($this->report($invoice));

        $this->assertFlux10Semantics($xml);
        $this->assertSame(
            ['Code', 'Percent', 'TaxExemptionReason', 'TaxExemptionReasonCode'],
            $this->childNames($xml, '//Invoice/TaxSubTotal/TaxCategory')
        );
        $this->assertSame('VATEX-EU-IC', $this->text($xml, '//Invoice/TaxSubTotal/TaxCategory/TaxExemptionReasonCode'));
    }

    /**
     * A line delivery address must name its country — TT-307 is mandatory.
     */
    public function testLineDeliveryWithoutCountryIsRefused(): void
    {
        $invoice = $this->invoice()->addLine(
            (new Line())
                ->setProductName('Consulting')
                ->setDelivery((new Delivery())->setLocation((new Location())->setCityName('Berlin')))
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice/Line/Delivery/Location/CountryId');

        (new Flux10Writer())->exportReport($this->report($invoice));
    }

    // -----------------------------------------------------------------------------

    private function invoice(): Flux10Invoice
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

    private function report(Flux10Invoice $invoice): Report
    {
        return (new Report())
            ->setReportId('REPORT-2026-01')
            ->setTransmissionType('IN')
            ->setIssueDateTime(new DateTime('2026-02-01 08:30:00'))
            ->setSender((new Sender())->setMatricule('PA01')->setName('Accredited Platform SA'))
            ->setIssuer(
                (new Issuer())->setSiren('123456789')->setName('Issuer SA')->setRoleCode(IssuerRoleCode::SELLER)
            )
            ->setPeriod(
                (new Period())->setStartDate(new DateTime('2026-01-01'))->setEndDate(new DateTime('2026-01-31'))
            )
            ->addInvoice($invoice);
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        return new DOMXPath($dom);
    }

    private function text(string $xml, string $query): ?string
    {
        return $this->xpath($xml)->query($query)->item(0)?->textContent;
    }

    private function attribute(string $xml, string $query, string $name): ?string
    {
        $node = $this->xpath($xml)->query($query)->item(0);

        return $node instanceof \DOMElement ? $node->getAttribute($name) : null;
    }

    /**
     * @return string[]
     */
    private function childNames(string $xml, string $query): array
    {
        $names = [];
        $parent = $this->xpath($xml)->query($query)->item(0);
        foreach ($parent?->childNodes ?? [] as $child) {
            if ($child instanceof \DOMElement) {
                $names[] = $child->nodeName;
            }
        }

        return $names;
    }
}
