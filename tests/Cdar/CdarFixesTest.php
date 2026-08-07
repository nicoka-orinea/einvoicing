<?php
namespace Tests\Cdar;

use DateTime;
use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\ExchangedDocument;
use Einvoicing\Cdar\Mapping\CdarStatusMap;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\Cdar\SpecifiedDocumentStatus;
use Einvoicing\CrossDomainAcknowledgementAndResponse;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Readers\CdarReader;
use Einvoicing\Writers\CdarWriter;
use PHPUnit\Framework\TestCase;

final class CdarFixesTest extends TestCase {
    private const HEADER = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossDomainAcknowledgementAndResponse
    xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100">
XML;

    /* ================= NOTES ================= */

    public function testReadsEveryPartOfEveryIncludedNote(): void {
        $xml = self::HEADER . <<<'XML'
    <rsm:AcknowledgementDocument>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-42</ram:IssuerAssignedID>
            <ram:SpecifiedDocumentStatus>
                <ram:ProcessConditionCode>210</ram:ProcessConditionCode>
                <ram:ReasonCode>DEST_ERR</ram:ReasonCode>
                <ram:IncludedNote>
                    <ram:ContentCode>AAI</ram:ContentCode>
                    <ram:Content languageID="fr">Destinataire inconnu</ram:Content>
                    <ram:SubjectCode>ACD</ram:SubjectCode>
                </ram:IncludedNote>
                <ram:IncludedNote>
                    <ram:ContentCode>ABL</ram:ContentCode>
                    <ram:Content>Second anomaly</ram:Content>
                    <ram:SubjectCode>AAY</ram:SubjectCode>
                </ram:IncludedNote>
            </ram:SpecifiedDocumentStatus>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $status = (new CdarReader())->import($xml)
            ->getAcknowledgementDocuments()[0]
            ->getReference()
            ->getSpecifiedDocumentStatuses()[0];
        $notes = $status->getIncludedNotes();

        $this->assertCount(2, $notes);
        $this->assertSame('Destinataire inconnu', $notes[0]['content']);
        $this->assertSame('fr', $notes[0]['languageId']);
        $this->assertSame('AAI', $notes[0]['contentCode']);
        $this->assertSame('ACD', $notes[0]['subjectCode']);
        $this->assertSame('Second anomaly', $notes[1]['content']);
        $this->assertNull($notes[1]['languageId']);
        $this->assertSame('ABL', $notes[1]['contentCode']);
        $this->assertSame('AAY', $notes[1]['subjectCode']);
    }

    public function testNoteRoundtripKeepsEveryPartAndWritesNoEmptyAttribute(): void {
        $status = (new SpecifiedDocumentStatus())
            ->setProcessConditionCode('210')
            ->setReasonCode('DEST_ERR')
            ->addIncludedNote('Destinataire inconnu', 'fr', 'AAI', 'ACD')
            ->addIncludedNote('Second anomaly', null, 'ABL', 'AAY');

        $reference = (new ReferenceReferencedDocument())
            ->setIssuerAssignedId('INV-42')
            ->addSpecifiedDocumentStatus($status);

        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())
                ->setId('CDV-1')
                ->setIssueDateTime(new DateTime('2026-05-22')))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setIssueDateTime(new DateTime('2026-05-22'))
                ->setReference($reference));

        $xml = (new CdarWriter())->export($cdar);

        // No attribute is ever written empty
        $this->assertStringNotContainsString('schemeID=""', $xml);
        $this->assertStringNotContainsString('languageID=""', $xml);
        $this->assertStringNotContainsString('currencyID=""', $xml);
        $this->assertStringNotContainsString('format=""', $xml);

        $imported = (new CdarReader())->import($xml);
        $notes = $imported->getAcknowledgementDocuments()[0]
            ->getReference()
            ->getSpecifiedDocumentStatuses()[0]
            ->getIncludedNotes();

        $this->assertCount(2, $notes);
        $this->assertSame(['Destinataire inconnu', 'fr', 'AAI', 'ACD'], [
            $notes[0]['content'], $notes[0]['languageId'], $notes[0]['contentCode'], $notes[0]['subjectCode']
        ]);
        $this->assertSame(['Second anomaly', null, 'ABL', 'AAY'], [
            $notes[1]['content'], $notes[1]['languageId'], $notes[1]['contentCode'], $notes[1]['subjectCode']
        ]);
    }

    public function testDeprecatedStatusContentCodeStillAppliesAsFallback(): void {
        $status = (new SpecifiedDocumentStatus())
            ->setProcessConditionCode('210')
            ->setIncludedNoteContentCode('AAI') // @phpstan-ignore-line deprecated on purpose
            ->addIncludedNote('Note without its own code');

        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())
                    ->setIssuerAssignedId('INV-1')
                    ->addSpecifiedDocumentStatus($status)));

        $xml = (new CdarWriter())->export($cdar);

        $this->assertStringContainsString('<ram:ContentCode>AAI</ram:ContentCode>', $xml);
    }

    /* ================= MULTIPLE ACKNOWLEDGEMENT DOCUMENTS ================= */

    public function testReadsMultipleAcknowledgementDocuments(): void {
        $xml = self::HEADER . <<<'XML'
    <rsm:AcknowledgementDocument>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-1</ram:IssuerAssignedID>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
    <rsm:AcknowledgementDocument>
        <ram:TypeCode>381</ram:TypeCode>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-2</ram:IssuerAssignedID>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $cdar = (new CdarReader())->import($xml);
        $documents = $cdar->getAcknowledgementDocuments();

        $this->assertCount(2, $documents);
        $this->assertSame('INV-1', $documents[0]->getReference()?->getIssuerAssignedId());
        $this->assertSame('INV-2', $documents[1]->getReference()?->getIssuerAssignedId());
        // The deprecated accessor still returns the first one
        $this->assertSame($documents[0], $cdar->getAcknowledgementDocument());
    }

    public function testWritesEveryAcknowledgementDocument(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())->setIssuerAssignedId('INV-1')))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('381')
                ->setReference((new ReferenceReferencedDocument())->setIssuerAssignedId('INV-2')));

        $imported = (new CdarReader())->import((new CdarWriter())->export($cdar));

        $this->assertCount(2, $imported->getAcknowledgementDocuments());
    }

    /* ================= CODE LISTS ================= */

    public function testStatusMapCoversEveryProcessConditionCode(): void {
        $all = CdarStatusMap::all();

        $this->assertCount(count(ProcessConditionCode::cases()), $all);
        foreach (ProcessConditionCode::cases() as $case) {
            $this->assertNotNull(
                CdarStatusMap::forProcessConditionCode($case->value),
                "Missing definition for {$case->value}"
            );
        }
    }

    public function testPreviouslyMissingInvoiceStatusesAreMapped(): void {
        foreach ([206, 208, 209] as $code) {
            $this->assertNotNull(CdarStatusMap::forProcessConditionCode($code), "Missing definition for $code");
        }
    }

    /** @return array<string, array{int, string}> */
    public static function nonInvoiceStatusProvider(): array {
        return [
            'flux 1 submitted' => [250, 'Deposee'],
            'flux 1 rejected' => [251, 'Rejetee'],
            'e-reporting submitted' => [300, 'Deposee'],
            'e-reporting rejected' => [301, 'Rejetee'],
            'directory accepted' => [400, 'Acceptee'],
            'directory rejected' => [401, 'Rejetee'],
            'flow admissible' => [500, 'Recevable'],
            'flow inadmissible' => [501, 'Irrecevable'],
            'acknowledgement rejected' => [601, 'Rejete'],
        ];
    }

    /** @dataProvider nonInvoiceStatusProvider */
    public function testNonInvoiceStatusesUseTheSpecLabels(int $code, string $xmlLabel): void {
        $this->assertSame($xmlLabel, ProcessConditionCode::from($code)->xmlLabel());
    }

    public function testFrenchInvoiceTypesAreKnownDocumentTypeCodes(): void {
        foreach (\Einvoicing\Invoice::FR_ALLOWED_TYPES as $type) {
            $this->assertNotNull(
                \Einvoicing\Cdar\Enums\DocumentTypeCode::tryFrom($type),
                "Missing document type code $type"
            );
        }
    }

    public function testAcknowledgementObjectTypeCodesExist(): void {
        foreach ([303, 304, 305, 306] as $code) {
            $this->assertNotNull(\Einvoicing\Cdar\Enums\DocumentTypeCode::tryFrom($code));
        }
    }

    /* ================= STATUS LABEL ================= */

    public function testDocumentStatusLabelIsNotOverwritten(): void {
        $xml = self::HEADER . <<<'XML'
    <rsm:AcknowledgementDocument>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-42</ram:IssuerAssignedID>
            <ram:SpecifiedDocumentStatus>
                <ram:ProcessConditionCode>210</ram:ProcessConditionCode>
                <ram:ProcessCondition>Libelle_du_document</ram:ProcessCondition>
                <ram:ReasonCode>DEST_ERR</ram:ReasonCode>
            </ram:SpecifiedDocumentStatus>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $status = (new CdarReader())->import($xml)
            ->getAcknowledgementDocuments()[0]
            ->getReference()
            ->getSpecifiedDocumentStatuses()[0];

        $this->assertSame('210', $status->getProcessConditionCode());
        $this->assertSame('Libelle_du_document', $status->getProcessCondition());
    }

    public function testDocumentStatusLabelIsDerivedWhenAbsent(): void {
        $xml = self::HEADER . <<<'XML'
    <rsm:AcknowledgementDocument>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:ReferenceReferencedDocument>
            <ram:IssuerAssignedID>INV-42</ram:IssuerAssignedID>
            <ram:SpecifiedDocumentStatus>
                <ram:ProcessConditionCode>210</ram:ProcessConditionCode>
            </ram:SpecifiedDocumentStatus>
        </ram:ReferenceReferencedDocument>
    </rsm:AcknowledgementDocument>
</rsm:CrossDomainAcknowledgementAndResponse>
XML;

        $status = (new CdarReader())->import($xml)
            ->getAcknowledgementDocuments()[0]
            ->getReference()
            ->getSpecifiedDocumentStatuses()[0];

        $this->assertSame('Refusee', $status->getProcessCondition());
    }

    public function testWriterKeepsAnExplicitStatusLabel(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())
                    ->setIssuerAssignedId('INV-1')
                    ->setProcessConditionCode('210')
                    ->setProcessCondition('Libelle_metier')));

        $xml = (new CdarWriter())->export($cdar);

        $this->assertStringContainsString('<ram:ProcessCondition>Libelle_metier</ram:ProcessCondition>', $xml);
        $this->assertStringNotContainsString('Refusee', $xml);
    }

    /* ================= VALIDATION ================= */

    private function getValidCdar(): CrossDomainAcknowledgementAndResponse {
        return (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())
                ->setId('CDV-1')
                ->setIssueDateTime(new DateTime('2026-05-22')))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())
                    ->setIssuerAssignedId('INV-1')
                    ->addSpecifiedDocumentStatus((new SpecifiedDocumentStatus())
                        ->setProcessConditionCode('210')
                        ->setReasonCode('DEST_ERR'))));
    }

    public function testValidCdarPasses(): void {
        $this->getValidCdar()->validate();
        $this->assertTrue(true);
    }

    public function testRefusedStatusWithoutReasonCodeFails(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())
                    ->setIssuerAssignedId('INV-1')
                    ->addSpecifiedDocumentStatus((new SpecifiedDocumentStatus())
                        ->setProcessConditionCode('210'))));

        try {
            $cdar->validate();
            $this->fail('Expected validation to fail with G7.08');
        } catch (ValidationException $e) {
            $this->assertSame('G7.08', $e->getBusinessRuleId());
        }
    }

    public function testApprovedStatusNeedsNoReasonCode(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()))
            ->addAcknowledgementDocument((new AcknowledgementDocument())
                ->setTypeCode('380')
                ->setReference((new ReferenceReferencedDocument())
                    ->setIssuerAssignedId('INV-1')
                    ->addSpecifiedDocumentStatus((new SpecifiedDocumentStatus())
                        ->setProcessConditionCode('205'))));

        $cdar->validate();
        $this->assertTrue(true);
    }

    public function testMissingExchangedDocumentFails(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse());

        $this->expectException(ValidationException::class);
        $cdar->validate();
    }

    public function testMissingAcknowledgementDocumentFails(): void {
        $cdar = (new CrossDomainAcknowledgementAndResponse())
            ->setExchangedDocument((new ExchangedDocument())->setId('CDV-1')->setIssueDateTime(new DateTime()));

        try {
            $cdar->validate();
            $this->fail('Expected validation to fail');
        } catch (ValidationException $e) {
            $this->assertSame('CDAR-ACK', $e->getBusinessRuleId());
        }
    }
}
