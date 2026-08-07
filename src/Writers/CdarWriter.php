<?php
namespace Einvoicing\Writers;

use DateTime;
use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\DocumentContext;
use Einvoicing\Cdar\ExchangedDocument;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\Cdar\SpecifiedDocumentCharacteristic;
use Einvoicing\Cdar\SpecifiedDocumentStatus;
use Einvoicing\Cdar\TradeParty;
use Einvoicing\Cdar\ValueAmount;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\CrossDomainAcknowledgementAndResponse;
use UXML\UXML;
use function is_numeric;

/**
 * Writer for CDAR XML documents.
 */
class CdarWriter
{
    public const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';
    public const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    public const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    public const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100';

    /**
     * Export a CDAR instance to XML.
     */
    public function export(CrossDomainAcknowledgementAndResponse $cdar): string
    {
        $xml = $this->createRoot();

        if ($cdar->getDocumentContext() !== null) {
            $this->addDocumentContext($xml, $cdar->getDocumentContext());
        }
        if ($cdar->getExchangedDocument() !== null) {
            $this->addExchangedDocument($xml, $cdar->getExchangedDocument());
        }
        foreach ($cdar->getAcknowledgementDocuments() as $acknowledgementDocument) {
            $this->addAcknowledgementDocument($xml, $acknowledgementDocument);
        }

        return $xml->asXML();
    }

    private function createRoot(): UXML
    {
        return UXML::newInstance("rsm:CrossDomainAcknowledgementAndResponse", null, [
            'xmlns:qdt' => self::NS_QDT,
            'xmlns:udt' => self::NS_UDT,
            'xmlns:ram' => self::NS_RAM,
            'xmlns:rsm' => self::NS_RSM,
            'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        ]);
    }

    private function addDocumentContext(UXML $xml, DocumentContext $context): void
    {
        $node = $xml->add("rsm:ExchangedDocumentContext");
        if ($context->getBusinessProcessId() !== null) {
            $node->add("ram:BusinessProcessSpecifiedDocumentContextParameter")
                ->add("ram:ID", $context->getBusinessProcessId());
        }
        if ($context->getGuidelineId() !== null) {
            $node->add("ram:GuidelineSpecifiedDocumentContextParameter")
                ->add("ram:ID", $context->getGuidelineId());
        }
    }

    private function addExchangedDocument(UXML $xml, ExchangedDocument $document): void
    {
        $node = $xml->add("rsm:ExchangedDocument");
        if ($document->getId() !== null) {
            $node->add("ram:ID", $document->getId());
        }
        if ($document->getName() !== null) {
            $node->add("ram:Name", $document->getName());
        }
        if ($document->getIssueDateTime() !== null) {
            $issue = $node->add("ram:IssueDateTime");
            $this->addDateTimeString($issue, "udt:DateTimeString", $document->getIssueDateTime(), '204');
        }
        if ($document->getSender() !== null) {
            $this->addTradeParty($node->add("ram:SenderTradeParty"), $document->getSender());
        }
        if ($document->getIssuer() !== null) {
            $this->addTradeParty($node->add("ram:IssuerTradeParty"), $document->getIssuer());
        }
        foreach ($document->getRecipients() as $recipient) {
            $this->addTradeParty($node->add("ram:RecipientTradeParty"), $recipient);
        }
    }

    private function addAcknowledgementDocument(UXML $xml, AcknowledgementDocument $ack): void
    {
        $node = $xml->add("rsm:AcknowledgementDocument");
        if ($ack->getMultipleReferencesIndicator() !== null) {
            $indicator = $ack->getMultipleReferencesIndicator() ? 'true' : 'false';
            $node->add("ram:MultipleReferencesIndicator")
                ->add("udt:Indicator", $indicator);
        }
        if ($ack->getTypeCode() !== null) {
            $node->add("ram:TypeCode", $ack->getTypeCode());
        }
        if ($ack->getIssueDateTime() !== null) {
            $issue = $node->add("ram:IssueDateTime");
            $this->addDateTimeString($issue, "udt:DateTimeString", $ack->getIssueDateTime(), '204');
        }
        if ($ack->getReference() !== null) {
            $this->addReferenceReferencedDocument($node->add("ram:ReferenceReferencedDocument"), $ack->getReference());
        }
    }

    private function addReferenceReferencedDocument(UXML $node, ReferenceReferencedDocument $reference): void
    {
        if ($reference->getIssuerAssignedId() !== null) {
            $node->add("ram:IssuerAssignedID", $reference->getIssuerAssignedId());
        }
        if ($reference->getStatusCode() !== null) {
            $node->add("ram:StatusCode", $reference->getStatusCode());
        }
        if ($reference->getTypeCode() !== null) {
            $node->add("ram:TypeCode", $reference->getTypeCode());
        }
        if ($reference->getReceiptDateTime() !== null) {
            $receipt = $node->add("ram:ReceiptDateTime");
            $this->addDateTimeString($receipt, "udt:DateTimeString", $reference->getReceiptDateTime(), '204');
        }
        if ($reference->getReferenceTypeCode() !== null) {
            $node->add("ram:ReferenceTypeCode", $reference->getReferenceTypeCode());
        }
        if ($reference->getFormattedIssueDateTime() !== null) {
            $formatted = $node->add("ram:FormattedIssueDateTime");
            // Flux 6 / CDV MDT-100-1: FormattedIssueDateTime uses UNTDID 2379 format 204 (YmdHis).
            $this->addDateTimeString($formatted, "qdt:DateTimeString", $reference->getFormattedIssueDateTime(), '204');
        }
        $statuses = $reference->getSpecifiedDocumentStatuses();
        $processCode = $reference->getProcessConditionCode();
        if ($processCode !== null) {
            $node->add("ram:ProcessConditionCode", $processCode);
            $processLabel = $this->processConditionLabelForXml($processCode, $reference->getProcessCondition());
            if ($processLabel !== null) {
                $node->add("ram:ProcessCondition", $processLabel);
            }
        } elseif ($reference->getProcessCondition() !== null) {
            $node->add("ram:ProcessCondition", $reference->getProcessCondition());
        }
        if ($reference->getIssuerTradeParty() !== null) {
            $this->addTradeParty($node->add("ram:IssuerTradeParty"), $reference->getIssuerTradeParty());
        }
        foreach ($statuses as $status) {
            $this->addSpecifiedDocumentStatus($node->add("ram:SpecifiedDocumentStatus"), $status);
        }
    }

    private function addSpecifiedDocumentStatus(UXML $node, SpecifiedDocumentStatus $status): void
    {
        // Child order is fixed by the Flux 6 / CDV CI-ARM content model (Annexe 2):
        // ReferenceDateTime, ReasonCode, Reason, ProcessConditionCode, ProcessCondition,
        // RequestedActionCode, RequestedAction, SequenceNumeric, IncludedNote,
        // SpecifiedDocumentCharacteristic. Emitting ProcessConditionCode or SequenceNumeric
        // out of this order makes the CDAR fail XSD validation (cvc-complex-type.2.4.a).
        if ($status->getReferenceDateTime() !== null) {
            $referenceDate = $node->add("ram:ReferenceDateTime");
            // ram:ReferenceDateTime is a UN/CEFACT DateTimeType: it only accepts udt:DateTimeString
            // (or udt:DateTime), never qdt:DateTimeString. MDT-110-1: UNTDID 2379 format 204 (YmdHis).
            $this->addDateTimeString($referenceDate, "udt:DateTimeString", $status->getReferenceDateTime(), '204');
        }
        if ($status->getReasonCode() !== null) {
            $node->add("ram:ReasonCode", $status->getReasonCode());
        }
        if ($status->getReason() !== null) {
            $node->add("ram:Reason", $status->getReason());
        }
        if ($status->getProcessConditionCode() !== null) {
            $node->add("ram:ProcessConditionCode", $status->getProcessConditionCode());
        }
        if ($status->getProcessCondition() !== null) {
            $node->add("ram:ProcessCondition", $status->getProcessCondition());
        }
        if ($status->getRequestedActionCode() !== null) {
            $node->add("ram:RequestedActionCode", $status->getRequestedActionCode());
        }
        if ($status->getRequestedAction() !== null) {
            $node->add("ram:RequestedAction", $status->getRequestedAction());
        }
        $node->add("ram:SequenceNumeric", (string) ($status->getSequenceNumeric() ?? 1));
        // @phan-suppress-next-line PhanDeprecatedFunction the status level code is a fallback
        $fallbackContentCode = $status->getIncludedNoteContentCode();
        foreach ($status->getIncludedNotes() as $note) {
            $this->addIncludedNote($node->add("ram:IncludedNote"), $fallbackContentCode, $note);
        }
        foreach ($status->getCharacteristics() as $characteristic) {
            $this->addSpecifiedDocumentCharacteristic($node->add("ram:SpecifiedDocumentCharacteristic"), $characteristic);
        }
    }

    private function addSpecifiedDocumentCharacteristic(UXML $node, SpecifiedDocumentCharacteristic $characteristic): void
    {
        if ($characteristic->getId() !== null) {
            $node->add("ram:ID", $characteristic->getId());
        }
        if ($characteristic->getTypeCode() !== null) {
            $node->add("ram:TypeCode", $characteristic->getTypeCode());
        }
        if ($characteristic->getValueChangedIndicator() !== null) {
            $indicator = $characteristic->getValueChangedIndicator() ? 'true' : 'false';
            // MDT-209: SpecifiedDocumentCharacteristic/ValueChangedIndicator carries udt:IndicatorString
            // (unlike MultipleReferencesIndicator / CopyIndicator which use udt:Indicator).
            $node->add("ram:ValueChangedIndicator")
                ->add("udt:IndicatorString", $indicator);
        }
        if ($characteristic->getDescription() !== null) {
            $node->add("ram:Description", $characteristic->getDescription());
        }
        if ($characteristic->getLocation() !== null) {
            $node->add("ram:Location", $characteristic->getLocation());
        }
        if ($characteristic->getValue() !== null) {
            $node->add("ram:Value", $characteristic->getValue());
        }
        if ($characteristic->getValueAmount() !== null) {
            $this->addValueAmount($node, $characteristic->getValueAmount());
        }
        if ($characteristic->getValueDateTime() !== null) {
            $value = $node->add("ram:ValueDateTime");
            $this->addDateTimeString($value, "udt:DateTimeString", $characteristic->getValueDateTime(), '102');
        }
        if ($characteristic->getValuePercent() !== null) {
            $node->add("ram:ValuePercent", $this->formatNumber($characteristic->getValuePercent()));
        }
    }

    /**
     * Children of NoteType, in schema order: ContentCode, Content, SubjectCode
     * @param array{content: string, languageId: ?string, contentCode: ?string, subjectCode: ?string} $note
     * @param string|null $fallbackContentCode Deprecated status level content code
     */
    private function addIncludedNote(UXML $node, ?string $fallbackContentCode, array $note): void
    {
        $contentCode = $note['contentCode'] ?? $fallbackContentCode;
        if ($contentCode !== null) {
            $node->add("ram:ContentCode", $contentCode);
        }
        $attributes = [];
        if ($note['languageId'] !== null) {
            $attributes['languageID'] = $note['languageId'];
        }
        $node->add("ram:Content", $note['content'], $attributes);
        $subjectCode = $note['subjectCode'] ?? null;
        if ($subjectCode !== null) {
            $node->add("ram:SubjectCode", $subjectCode);
        }
    }

    private function addValueAmount(UXML $node, ValueAmount $amount): void
    {
        if ($amount->getAmount() === null) {
            return;
        }
        $attributes = [];
        if ($amount->getCurrencyId() !== null) {
            $attributes['currencyID'] = $amount->getCurrencyId();
        }
        $node->add("ram:ValueAmount", $this->formatNumber($amount->getAmount()), $attributes);
    }

    private function addTradeParty(UXML $node, TradeParty $party): void
    {
        if ($party->getGlobalId() !== null) {
            $attributes = [];
            if ($party->getGlobalIdScheme() !== null) {
                $attributes['schemeID'] = $party->getGlobalIdScheme();
            }
            $node->add("ram:GlobalID", $party->getGlobalId(), $attributes);
        }
        if ($party->getName() !== null) {
            $node->add("ram:Name", $party->getName());
        }
        if ($party->getRoleCode() !== null) {
            $node->add("ram:RoleCode", $party->getRoleCode());
        }
        if ($party->getUri() !== null) {
            $attributes = [];
            if ($party->getUriScheme() !== null) {
                $attributes['schemeID'] = $party->getUriScheme();
            }
            $node->add("ram:URIUniversalCommunication")
                ->add("ram:URIID", $party->getUri(), $attributes);
        }
    }

    private function addDateTimeString(UXML $node, string $name, DateTime $dateTime, string $format): void
    {
        $node->add($name, $this->formatDateTime($dateTime, $format), ['format' => $format]);
    }

    private function formatDateTime(DateTime $dateTime, string $format): string
    {
        if ($format === '102') {
            return $dateTime->format('Ymd');
        }
        if ($format === '204') {
            return $dateTime->format('YmdHis');
        }
        return $dateTime->format(DateTime::ATOM);
    }

    private function formatNumber(float $number): string
    {
        $number = $this->trimFloat($number);
        if (is_numeric($number)) {
            return $number;
        }
        return (string) $number;
    }

    private function trimFloat(float $number): string
    {
        $value = rtrim(rtrim(sprintf('%.8F', $number), '0'), '.');
        if ($value === '-0') {
            $value = '0';
        }
        return $value;
    }

    /**
     * Resolve the label to write next to a process condition code.
     * An explicit label set on the model always wins: it is only derived from
     * the code when none was provided.
     */
    private function processConditionLabelForXml(string $code, ?string $explicitLabel): ?string
    {
        if ($explicitLabel !== null) {
            return $explicitLabel;
        }
        if (!is_numeric($code)) {
            return null;
        }
        try {
            return ProcessConditionCode::from((int) $code)->xmlLabel();
        } catch (\ValueError $error) {
            return null;
        }
    }
}
