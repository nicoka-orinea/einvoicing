<?php
namespace Tests\Readers;

use Einvoicing\Readers\CdarReader;
use PHPUnit\Framework\TestCase;

final class CdarReaderTest extends TestCase
{
    public function testCanReadPlatformEmittedProcessCondition(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossDomainAcknowledgementAndResponse xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100"
                                           xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
                                           xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"
                                           xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:AcknowledgementDocument>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-001</ram:IssuerAssignedID>
            <ram:ProcessConditionCode>201</ram:ProcessConditionCode>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $cdar = (new CdarReader())->import($xml);
        $reference = $cdar->getAcknowledgementDocument()?->getReference();

        $this->assertSame('201', $reference?->getProcessConditionCode());
        $this->assertSame('Emitted by platform', $reference?->getProcessCondition());
    }
}
