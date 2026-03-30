<?php
namespace Tests\Writers;

use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\CrossDomainAcknowledgementAndResponse;
use Einvoicing\Writers\CdarWriter;
use PHPUnit\Framework\TestCase;

final class CdarWriterTest extends TestCase
{
    public function testWritesPlatformEmittedProcessConditionLabel(): void
    {
        $reference = (new ReferenceReferencedDocument())
            ->setIssuerAssignedId('INV-001')
            ->setProcessConditionCode(ProcessConditionCode::EMITTED_BY_PLATFORM);

        $ack = (new AcknowledgementDocument())->setReference($reference);
        $cdar = (new CrossDomainAcknowledgementAndResponse())->setAcknowledgementDocument($ack);

        $xml = (new CdarWriter())->export($cdar);

        $this->assertStringContainsString('<ram:ProcessConditionCode>201</ram:ProcessConditionCode>', $xml);
        $this->assertStringContainsString('<ram:ProcessCondition>Emise_par_la_plateforme</ram:ProcessCondition>', $xml);
    }
}
