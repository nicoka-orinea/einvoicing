<?php
namespace Tests\Writers;

use DateTime;
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

        $this->assertEquals('Seller Name Ltd.', $xml->get('ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name')?->asText());
        $this->assertEquals('Buyer Name Ltd.', $xml->get('ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name')?->asText());

        $this->assertNull($xml->get('ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:GlobalID'));
        $this->assertNull($xml->get('ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:URIUniversalCommunication'));
        $this->assertNull($xml->get('ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization'));
    }
}
