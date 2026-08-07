<?php
namespace Tests\Integration;

use DateTime;
use Einvoicing\Attachment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Readers\UblReader;
use Einvoicing\Writers\UblWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;
use UXML\UXML;

final class UblRoundtripTest extends TestCase {
    use ValidatesAgainstXsd;

    private function getInvoice(): Invoice {
        $seller = (new Party)
            ->setElectronicAddress(new Identifier('123456789', '0002'))
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setElectronicAddress(new Identifier('987654321', '0002'))
            ->setName('Buyer Name Ltd.')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = new Invoice();
        $invoice->setSpecification('urn:cen.eu:en16931:2017')
            ->setBusinessProcess('B1')
            ->setNumber('INV-RT-001')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)->setName('Line #1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        return $invoice;
    }

    public function testDespatchAdviceReferenceRoundtrip(): void {
        $invoice = $this->getInvoice()->setDespatchAdviceReference('DESP-42');

        $xml = (new UblWriter())->export($invoice);
        $imported = (new UblReader())->import($xml);

        $this->assertSame(
            'DESP-42',
            UXML::fromString($xml)->get('cac:DespatchDocumentReference/cbc:ID')?->asText()
        );
        $this->assertSame('DESP-42', $imported->getDespatchAdviceReference());
    }

    public function testInvoicedObjectIdentifierRoundtrip(): void {
        $invoice = $this->getInvoice()->setInvoicedObjectIdentifier(new Identifier('OBJ-42', 'AWV'));

        $xml = (new UblWriter())->export($invoice);
        $imported = (new UblReader())->import($xml);

        $reference = UXML::fromString($xml)->get('cac:AdditionalDocumentReference');
        $this->assertSame('130', $reference?->get('cbc:DocumentTypeCode')?->asText());
        $this->assertSame('OBJ-42', $reference?->get('cbc:ID')?->asText());
        $this->assertSame('AWV', $reference?->get('cbc:ID')?->element()->getAttribute('schemeID'));

        // BT-18 must not be imported back as a supporting document (BG-24)
        $this->assertCount(0, $imported->getAttachments());
        $this->assertSame('OBJ-42', $imported->getInvoicedObjectIdentifier()?->getValue());
        $this->assertSame('AWV', $imported->getInvoicedObjectIdentifier()?->getScheme());
    }

    public function testReferenceOnlyAttachmentIsNotReportedAsInvoicedObject(): void {
        $invoice = $this->getInvoice()
            ->addAttachment((new Attachment)->setId(new Identifier('ATT-1', 'ABT')));

        $xml = (new UblWriter())->export($invoice);
        $imported = (new UblReader())->import($xml);

        $this->assertNull(UXML::fromString($xml)->get('cac:AdditionalDocumentReference/cbc:DocumentTypeCode'));
        $this->assertNull($imported->getInvoicedObjectIdentifier());
        $this->assertCount(1, $imported->getAttachments());
        $this->assertSame('ATT-1', $imported->getAttachments()[0]->getId()?->getValue());
    }
}
