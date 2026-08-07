<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;

/**
 * Witness test for the XSD validation harness: a nominal French invoice must
 * validate against the complete Factur-X EN 16931 CII schema.
 */
final class CiiWriterXsdTest extends TestCase {
    use ValidatesAgainstXsd;

    public function testNominalFrenchInvoiceIsValidFacturX(): void {
        $seller = (new Party)
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setCompanyId(new Identifier('987654321', '0002'))
            ->setName('Buyer Name Ltd.')
            ->setAddress(['Main Avenue 12'])
            ->setPostalCode('69001')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = new Invoice();
        $invoice->setSpecification('urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931')
            ->setBusinessProcess('B1')
            ->setNumber('INV-XSD-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setDueDate(new DateTime('2026-02-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setUnit('C62')
                ->setVatCategory('S')
                ->setVatRate(20));

        $this->assertValidFacturX((new CiiWriter())->export($invoice));
    }
}
