<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\Cdar\SpecifiedDocumentCharacteristic;
use Einvoicing\Cdar\SpecifiedDocumentStatus;
use Einvoicing\CrossDomainAcknowledgementAndResponse;
use Einvoicing\Writers\CdarWriter;
use PHPUnit\Framework\TestCase;
use UXML\UXML;

final class CdarWriterTest extends TestCase {
    public function testWritesPlatformEmittedProcessConditionLabelOnReference(): void {
        $reference = (new ReferenceReferencedDocument())
            ->setIssuerAssignedId('INV-201')
            ->applyProcessCondition(ProcessConditionCode::EMITTED_BY_PLATFORM);

        $ack = (new AcknowledgementDocument())
            ->setReference($reference);

        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setAcknowledgementDocument($ack);

        $xml = UXML::fromString((new CdarWriter())->export($cdar));
        $referenceNode = $xml->get('rsm:AcknowledgementDocument/ram:ReferenceReferencedDocument');

        $this->assertSame('201', $referenceNode->get('ram:ProcessConditionCode')->asText());
        $this->assertSame('Emise_par_la_plateforme', $referenceNode->get('ram:ProcessCondition')->asText());
        $this->assertSame('10', $referenceNode->get('ram:StatusCode')->asText());
    }

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
        $this->assertSame('20260522000000', $status->get('ram:ReferenceDateTime/udt:DateTimeString')->asText());
        $this->assertSame('204', $status->get('ram:ReferenceDateTime/udt:DateTimeString')->element()->getAttribute('format'));
        $this->assertSame('Payment terms', $status->get('ram:SpecifiedDocumentCharacteristic/ram:Description')->asText());
        $this->assertSame('true', $status->get('ram:SpecifiedDocumentCharacteristic/ram:ValueChangedIndicator/udt:IndicatorString')->asText());
        $this->assertSame('Cash', $status->get('ram:SpecifiedDocumentCharacteristic/ram:Value')->asText());
    }

    public function testWritesSpecifiedDocumentStatusChildrenInFlux6SchemaOrder(): void {
        $reference = (new ReferenceReferencedDocument())
            ->setIssuerAssignedId('INV-210')
            ->setTypeCode('380');

        $rejected = (new SpecifiedDocumentStatus())
            ->setReferenceDateTime(new DateTime('2026-05-22 14:15:16'))
            ->setReasonCode('REFUSED')
            ->setReason('Refus acheteur')
            ->setProcessConditionCode('210')
            ->setProcessCondition('Refusee')
            ->setRequestedActionCode('CORRECT')
            ->setRequestedAction('Corriger la facture')
            ->setSequenceNumeric(2)
            ->setIncludedNoteContentCode('AAI')
            ->addIncludedNote('Motif detaille')
            ->addCharacteristic(
                (new SpecifiedDocumentCharacteristic())
                    ->setId('BT-20')
                    ->setValueDateTime(new DateTime('2026-05-22'))
            );

        $reference->addSpecifiedDocumentStatus($rejected);

        $ack = (new AcknowledgementDocument())
            ->setReference($reference);

        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setAcknowledgementDocument($ack);

        $document = new \DOMDocument();
        $document->loadXML((new CdarWriter())->export($cdar));
        $xpath = new \DOMXPath($document);
        $status = $xpath->query('//*[local-name() = "SpecifiedDocumentStatus"]')->item(0);

        $this->assertNotNull($status);
        $this->assertSame([
            'ReferenceDateTime',
            'ReasonCode',
            'Reason',
            'ProcessConditionCode',
            'ProcessCondition',
            'RequestedActionCode',
            'RequestedAction',
            'SequenceNumeric',
            'IncludedNote',
            'SpecifiedDocumentCharacteristic',
        ], $this->directChildElementNames($status));

        $xml = UXML::fromString($document->saveXML());
        $statusNode = $xml->get('rsm:AcknowledgementDocument/ram:ReferenceReferencedDocument/ram:SpecifiedDocumentStatus');
        $this->assertSame('20260522141516', $statusNode->get('ram:ReferenceDateTime/udt:DateTimeString')->asText());
        $this->assertSame('204', $statusNode->get('ram:ReferenceDateTime/udt:DateTimeString')->element()->getAttribute('format'));
        $this->assertSame('20260522', $statusNode->get('ram:SpecifiedDocumentCharacteristic/ram:ValueDateTime/udt:DateTimeString')->asText());
        $this->assertSame('102', $statusNode->get('ram:SpecifiedDocumentCharacteristic/ram:ValueDateTime/udt:DateTimeString')->element()->getAttribute('format'));
    }

    /**
     * @return string[]
     */
    private function directChildElementNames(\DOMNode $node): array {
        $names = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $names[] = $child->localName;
            }
        }
        return $names;
    }
}
