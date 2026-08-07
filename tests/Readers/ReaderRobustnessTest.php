<?php
namespace Tests\Readers;

use Einvoicing\Readers\CdarReader;
use Einvoicing\Readers\CiiReader;
use Einvoicing\Readers\UblReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Defensive behaviour of the three readers on hostile or malformed input.
 */
final class ReaderRobustnessTest extends TestCase {
    private const DOCTYPE_UBL = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE Invoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>&xxe;</cbc:ID>
</Invoice>
XML;

    private const DOCTYPE_CII = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE rsm:CrossIndustryInvoice [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<rsm:CrossIndustryInvoice
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100">
    <rsm:ExchangedDocument><ram:ID>&xxe;</ram:ID></rsm:ExchangedDocument>
</rsm:CrossIndustryInvoice>
XML;

    private const DOCTYPE_CDAR = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE rsm:CrossDomainAcknowledgementAndResponse [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<rsm:CrossDomainAcknowledgementAndResponse
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100">
    <rsm:ExchangedDocument><ram:ID>&xxe;</ram:ID></rsm:ExchangedDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

    /* ================= DOCTYPE ================= */

    public function testUblReaderRejectsDoctype(): void {
        $this->expectException(InvalidArgumentException::class);
        (new UblReader())->import(self::DOCTYPE_UBL);
    }

    public function testCiiReaderRejectsDoctype(): void {
        $this->expectException(InvalidArgumentException::class);
        (new CiiReader())->import(self::DOCTYPE_CII);
    }

    public function testCdarReaderRejectsDoctype(): void {
        $this->expectException(InvalidArgumentException::class);
        (new CdarReader())->import(self::DOCTYPE_CDAR);
    }

    /* ================= DATES ================= */

    public function testCiiReaderRejectsAnImpossibleFormat102Date(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocument>
        <ram:ID>INV-1</ram:ID>
        <ram:IssueDateTime><udt:DateTimeString format="102">20261399</udt:DateTimeString></ram:IssueDateTime>
    </rsm:ExchangedDocument>
</rsm:CrossIndustryInvoice>
XML;

        $this->expectException(InvalidArgumentException::class);
        (new CiiReader())->import($xml);
    }

    public function testCiiReaderResetsTheTimeOfAFormat102Date(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocument>
        <ram:ID>INV-1</ram:ID>
        <ram:IssueDateTime><udt:DateTimeString format="102">20260115</udt:DateTimeString></ram:IssueDateTime>
    </rsm:ExchangedDocument>
</rsm:CrossIndustryInvoice>
XML;

        $issueDate = (new CiiReader())->import($xml)->getIssueDate();

        $this->assertSame('2026-01-15 00:00:00', $issueDate?->format('Y-m-d H:i:s'));
    }

    public function testCdarReaderRejectsAnImpossibleFormat204Date(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossDomainAcknowledgementAndResponse
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocument>
        <ram:ID>CDV-1</ram:ID>
        <ram:IssueDateTime><udt:DateTimeString format="204">not-a-date</udt:DateTimeString></ram:IssueDateTime>
    </rsm:ExchangedDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $this->expectException(InvalidArgumentException::class);
        (new CdarReader())->import($xml);
    }

    public function testUblReaderRejectsAnImpossibleDate(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>INV-1</cbc:ID>
    <cbc:IssueDate>not-a-date</cbc:IssueDate>
</Invoice>
XML;

        $this->expectException(InvalidArgumentException::class);
        (new UblReader())->import($xml);
    }

    /* ================= BASE64 ================= */

    public function testCorruptBase64AttachmentIsSkippedWithoutFailing(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>INV-1</cbc:ID>
    <cbc:IssueDate>2026-01-15</cbc:IssueDate>
    <cac:AdditionalDocumentReference>
        <cbc:ID>ATT-1</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="application/pdf" filename="broken.pdf">!!!not base64!!!</cbc:EmbeddedDocumentBinaryObject>
        </cac:Attachment>
    </cac:AdditionalDocumentReference>
</Invoice>
XML;

        $invoice = (new UblReader())->import($xml);
        $attachments = $invoice->getAttachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('ATT-1', $attachments[0]->getId()?->getValue());
        $this->assertNull($attachments[0]->getContents());
        // The rest of the attachment survives
        $this->assertSame('application/pdf', $attachments[0]->getMimeCode());
        $this->assertSame('broken.pdf', $attachments[0]->getFilename());
    }

    public function testValidBase64AttachmentIsDecoded(): void {
        $contents = 'The attachment raw contents';
        $encoded = base64_encode($contents);
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
    <cbc:ID>INV-1</cbc:ID>
    <cbc:IssueDate>2026-01-15</cbc:IssueDate>
    <cac:AdditionalDocumentReference>
        <cbc:ID>ATT-1</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="application/pdf" filename="ok.pdf">$encoded</cbc:EmbeddedDocumentBinaryObject>
        </cac:Attachment>
    </cac:AdditionalDocumentReference>
</Invoice>
XML;

        $invoice = (new UblReader())->import($xml);

        $this->assertSame($contents, $invoice->getAttachments()[0]->getContents());
    }
}
