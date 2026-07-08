<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
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

    public function testWritesBusinessTermsAndConsistentMonetarySummation(): void {
        $invoice = new Invoice();
        $invoice->setSpecification('urn:test:cii:profile')
            ->setBusinessProcess('urn:test:process')
            ->setNumber('INV-002')
            ->setBuyerReference('BR-1')
            ->setPurchaseOrderReference('PO-1')
            ->setSalesOrderReference('SO-1')
            ->setContractReference('CT-1')
            ->setIssueDate(new DateTime('2026-03-30'))
            ->setDueDate(new DateTime('2026-04-15'))
            ->setVatCurrency('EUR')
            ->setBuyerAccountingReference('ACC-1')
            ->setPaidAmount(20)
            ->setRoundingAmount(0.01)
            ->setSeller((new Party())->addIdentifier(new Identifier('LEGAL-SELLER', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setAddress(['Line 1', 'Line 2'])->setPostalCode('75001')->setCity('Paris')->setCountry('FR')->setVatNumber('FR123'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('LEGAL-BUYER', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setAddress(['Buyer line 1', 'Buyer line 2'])->setPostalCode('69001')->setCity('Lyon')->setCountry('FR')->setVatNumber('FR456'))
            ->addAllowance((new AllowanceOrCharge())->setAmount(10)->setVatCategory('S')->setVatRate(20))
            ->addCharge((new AllowanceOrCharge())->setAmount(5)->setVatCategory('S')->setVatRate(20))
            ->addPayment((new Payment())->setId('PAY-1')->setMeansCode('30')->setMeansText('Credit transfer')->addTransfer((new Transfer())->setAccountId('FR761234')->setAccountName('Main')->setProvider('AGRIFRPP')))
            ->addLine((new InvoiceLine())->setId('1')->setName('Line')->setDescription('Desc')->setSellerIdentifier('SELL-1')->setBuyerIdentifier('BUY-1')->setStandardIdentifier(new Identifier('1234567890123', '0160'))->setOriginCountry('FR')->setOrderLineReference('OL-1')->setBuyerAccountingReference('L-ACC')->setPrice(100, 2)->setQuantity(2)->setBaseQuantity(2)->setVatRate(20)->setUnit('C62')->setNote('Line note'));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $this->assertSame('urn:test:process', $xml->get('rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID')->asText());
        $this->assertSame('urn:test:cii:profile', $xml->get('rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID')->asText());
        $this->assertSame('BR-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerReference')->asText());
        $this->assertSame('PO-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID')->asText());
        $this->assertSame('SO-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerOrderReferencedDocument/ram:IssuerAssignedID')->asText());
        $this->assertSame('CT-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID')->asText());
        $this->assertSame('2.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity')->asText());
        $this->assertSame('ACC-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID')->asText());
        $this->assertSame('PAY-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:PaymentReference')->asText());
        $this->assertSame('10.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount')->asText());
        $this->assertSame('5.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:ChargeTotalAmount')->asText());
        $this->assertSame('20.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount')->asText());
        $this->assertSame('0.01', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:RoundingAmount')->asText());
    }

    public function testWritesPrecedingInvoiceReferenceForCreditNote(): void {
        $invoice = new Invoice();
        $invoice->setNumber('AV-001')
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setIssueDate(new DateTime('2026-05-20'))
            ->setCurrency('EUR')
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-042', new DateTime('2026-04-10')))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $ref = $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument');
        $this->assertNotNull($ref, 'BR-FR-CO-05: an InvoiceReferencedDocument must be present for a credit note');
        $this->assertSame('INV-042', $ref->get('ram:IssuerAssignedID')->asText());
        $date = $ref->get('ram:FormattedIssueDateTime/qdt:DateTimeString');
        $this->assertSame('20260410', $date->asText());
        $this->assertSame('102', $date->element()->getAttribute('format'));
    }

    public function testDoesNotWritePrecedingInvoiceReferenceForRegularInvoice(): void {
        $invoice = new Invoice();
        $invoice->setNumber('INV-003')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-05-20'))
            ->setCurrency('EUR')
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument'));
    }
}
