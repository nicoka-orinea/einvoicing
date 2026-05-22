<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\Cdar\SpecifiedDocumentCharacteristic;
use Einvoicing\Cdar\SpecifiedDocumentStatus;
use Einvoicing\CrossDomainAcknowledgementAndResponse;
use Einvoicing\Writers\CdarWriter;
use PHPUnit\Framework\TestCase;
use UXML\UXML;

final class CdarWriterTest extends TestCase {
    public function testWritesStatusesInsideSpecifiedDocumentStatusNodes(): void {
        $reference = (new ReferenceReferencedDocument())
            ->setIssuerAssignedId('INV-99')
            ->setTypeCode('380');

        $paid = (new SpecifiedDocumentStatus())
            ->setReferenceDateTime(new DateTime('2026-05-22'))
            ->setProcessConditionCode('212')
            ->setProcessCondition('Encaissee')
            ->addCharacteristic(
                (new SpecifiedDocumentCharacteristic())
                    ->setId('BT-20')
                    ->setDescription('Payment terms')
                    ->setValueChangedIndicator(true)
                    ->setValue('Cash')
            );

        $reference->addSpecifiedDocumentStatus($paid);

        $ack = (new AcknowledgementDocument())
            ->setReference($reference);

        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setAcknowledgementDocument($ack);

        $xml = UXML::fromString((new CdarWriter())->export($cdar));
        $status = $xml->get('rsm:AcknowledgementDocument/ram:ReferenceReferencedDocument/ram:SpecifiedDocumentStatus');

        $this->assertSame('212', $status->get('ram:ProcessConditionCode')->asText());
        $this->assertSame('Encaissee', $status->get('ram:ProcessCondition')->asText());
        $this->assertSame('20260522', $status->get('ram:ReferenceDateTime/qdt:DateTimeString')->asText());
        $this->assertSame('Payment terms', $status->get('ram:SpecifiedDocumentCharacteristic/ram:Description')->asText());
        $this->assertSame('true', $status->get('ram:SpecifiedDocumentCharacteristic/ram:ValueChangedIndicator/udt:Indicator')->asText());
        $this->assertSame('Cash', $status->get('ram:SpecifiedDocumentCharacteristic/ram:Value')->asText());
    }
}
