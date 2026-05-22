<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;
use UXML\UXML;

final class CiiWriterTest extends TestCase {
    public function testCanExportInvoiceWithoutOptionalPartyIdentifiers(): void {
        $seller = (new Party)
            ->setName('Seller Name Ltd.')
            ->setAddress(['Fake Street 123'])
            ->setCity('Springfield')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setName('Buyer Name Ltd.')
            ->setAddress(['Main Avenue 12'])
            ->setCity('Paris')
            ->setCountry('FR');

        $invoice = (new Invoice)
            ->setNumber('INV-001')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('S')
                ->setVatRate(20));

        $writer = new CiiWriter();
        $xml = UXML::fromString($writer->export($invoice));

        $this->assertEquals('Seller Name Ltd.', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name')?->asText());
        $this->assertEquals('Buyer Name Ltd.', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name')?->asText());

        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:GlobalID'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:URIUniversalCommunication'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization'));
    }

    public function testCanGenerateDocumentNotesWithSubjectCode(): void {
        $invoice = new Invoice();
        $invoice->setNumber('INV-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-03-30'))
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatRate(20))
            ->addNote('Late payment penalties', 'PMD')
            ->addNote('Recovery fees', 'PMT');

        $xml = UXML::fromString((new CiiWriter())->export($invoice));
        $notes = $xml->getAll('rsm:ExchangedDocument/ram:IncludedNote');

        $this->assertCount(2, $notes);
        $this->assertSame('Late payment penalties', $notes[0]->get('ram:Content')->asText());
        $this->assertSame('PMD', $notes[0]->get('ram:SubjectCode')->asText());
        $this->assertSame('Recovery fees', $notes[1]->get('ram:Content')->asText());
        $this->assertSame('PMT', $notes[1]->get('ram:SubjectCode')->asText());
    }
}
