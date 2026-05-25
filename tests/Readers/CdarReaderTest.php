<?php
namespace Tests\Readers;

use Einvoicing\Readers\CdarReader;
use PHPUnit\Framework\TestCase;

final class CdarReaderTest extends TestCase {
    public function testCanReadPlatformEmittedProcessConditionFromReference(): void {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossDomainAcknowledgementAndResponse
    xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100">
    <rsm:AcknowledgementDocument>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-201</ram:IssuerAssignedID>
            <ram:ProcessConditionCode>201</ram:ProcessConditionCode>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $reference = (new CdarReader())->import($xml)->getAcknowledgementDocument()?->getReference();

        $this->assertSame('INV-201', $reference?->getIssuerAssignedId());
        $this->assertSame('201', $reference?->getProcessConditionCode());
        $this->assertSame('Emise_par_la_plateforme', $reference?->getProcessCondition());
    }

    public function testCanReadMultipleSpecifiedDocumentStatuses(): void {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossDomainAcknowledgementAndResponse
    xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100">
    <rsm:AcknowledgementDocument>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-42</ram:IssuerAssignedID>
            <ram:SpecifiedDocumentStatus>
                <ram:ReferenceDateTime><qdt:DateTimeString format="102">20260522</qdt:DateTimeString></ram:ReferenceDateTime>
                <ram:ProcessConditionCode>212</ram:ProcessConditionCode>
                <ram:ProcessCondition>Encaissee</ram:ProcessCondition>
                <ram:SpecifiedDocumentCharacteristic>
                    <ram:ID>BT-20</ram:ID>
                    <ram:Description>Payment terms</ram:Description>
                    <ram:ValueChangedIndicator><udt:Indicator>true</udt:Indicator></ram:ValueChangedIndicator>
                    <ram:Value>Payable immediately</ram:Value>
                </ram:SpecifiedDocumentCharacteristic>
            </ram:SpecifiedDocumentStatus>
            <ram:SpecifiedDocumentStatus>
                <ram:ReferenceDateTime><qdt:DateTimeString format="102">20260523</qdt:DateTimeString></ram:ReferenceDateTime>
                <ram:ProcessConditionCode>210</ram:ProcessConditionCode>
                <ram:ProcessCondition>Refusee</ram:ProcessCondition>
            </ram:SpecifiedDocumentStatus>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $reference = (new CdarReader())->import($xml)->getAcknowledgementDocument()?->getReference();
        $statuses = $reference?->getSpecifiedDocumentStatuses() ?? [];

        $this->assertCount(2, $statuses);
        $this->assertSame('INV-42', $reference?->getIssuerAssignedId());
        $this->assertSame('2026-05-22', $statuses[0]->getReferenceDateTime()?->format('Y-m-d'));
        $this->assertSame('212', $statuses[0]->getProcessConditionCode());
        $this->assertSame('Encaissee', $statuses[0]->getProcessCondition());
        $this->assertSame('Payment terms', $statuses[0]->getCharacteristics()[0]->getDescription());
        $this->assertTrue($statuses[0]->getCharacteristics()[0]->getValueChangedIndicator());
        $this->assertSame('Payable immediately', $statuses[0]->getCharacteristics()[0]->getValue());
        $this->assertSame('210', $statuses[1]->getProcessConditionCode());
    }
}
