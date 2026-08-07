<?php
namespace Einvoicing\Readers;

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
use InvalidArgumentException;
use UXML\UXML;
use function floatval;
use ValueError;

/**
 * Reader for CDAR XML documents.
 */
class CdarReader
{
    /**
     * Import CDAR document
     * @throws InvalidArgumentException if failed to parse XML
     */
    public function import(string $document): CrossDomainAcknowledgementAndResponse
    {
        $cdar = new CrossDomainAcknowledgementAndResponse();
        $xml = UXML::fromString($document);

        $contextNode = $xml->get("rsm:ExchangedDocumentContext");
        if ($contextNode !== null) {
            $context = new DocumentContext();
            $businessProcessNode = $contextNode->get("ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID");
            if ($businessProcessNode !== null) {
                $context->setBusinessProcessId($businessProcessNode->asText());
            }
            $guidelineNode = $contextNode->get("ram:GuidelineSpecifiedDocumentContextParameter/ram:ID");
            if ($guidelineNode !== null) {
                $context->setGuidelineId($guidelineNode->asText());
            }
            $cdar->setDocumentContext($context);
        }

        $documentNode = $xml->get("rsm:ExchangedDocument");
        if ($documentNode !== null) {
            $exchanged = new ExchangedDocument();
            $idNode = $documentNode->get("ram:ID");
            if ($idNode !== null) {
                $exchanged->setId($idNode->asText());
            }
            $nameNode = $documentNode->get("ram:Name");
            if ($nameNode !== null) {
                $exchanged->setName($nameNode->asText());
            }
            $issueNode = $documentNode->get("ram:IssueDateTime/udt:DateTimeString");
            if ($issueNode !== null) {
                $exchanged->setIssueDateTime($this->parseDateTime($issueNode));
            }
            $senderNode = $documentNode->get("ram:SenderTradeParty");
            if ($senderNode !== null) {
                $exchanged->setSender($this->parseTradeParty($senderNode));
            }
            $issuerNode = $documentNode->get("ram:IssuerTradeParty");
            if ($issuerNode !== null) {
                $exchanged->setIssuer($this->parseTradeParty($issuerNode));
            }
            foreach ($documentNode->getAll("ram:RecipientTradeParty") as $recipientNode) {
                $exchanged->addRecipient($this->parseTradeParty($recipientNode));
            }
            $cdar->setExchangedDocument($exchanged);
        }

        foreach ($xml->getAll("rsm:AcknowledgementDocument") as $ackNode) {
            $ack = new AcknowledgementDocument();
            $multipleNode = $ackNode->get("ram:MultipleReferencesIndicator/udt:Indicator");
            if ($multipleNode !== null) {
                $ack->setMultipleReferencesIndicator($this->parseIndicator($multipleNode->asText()));
            }
            $typeNode = $ackNode->get("ram:TypeCode");
            if ($typeNode !== null) {
                $ack->setTypeCode($typeNode->asText());
            }
            $issueNode = $ackNode->get("ram:IssueDateTime/udt:DateTimeString");
            if ($issueNode !== null) {
                $ack->setIssueDateTime($this->parseDateTime($issueNode));
            }

            $referenceNode = $ackNode->get("ram:ReferenceReferencedDocument");
            if ($referenceNode !== null) {
                $ack->setReference($this->parseReferenceReferencedDocument($referenceNode));
            }
            $cdar->addAcknowledgementDocument($ack);
        }

        return $cdar;
    }


    /**
     * Read an attribute, telling an absent one from an empty one.
     * DOMElement::getAttribute() returns an empty string for both.
     */
    private function attr(?UXML $node, string $name): ?string
    {
        if ($node === null) {
            return null;
        }
        $element = $node->element();
        return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    private function parseReferenceReferencedDocument(UXML $node): ReferenceReferencedDocument
    {
        $reference = new ReferenceReferencedDocument();
        $issuerIdNode = $node->get("ram:IssuerAssignedID");
        if ($issuerIdNode !== null) {
            $reference->setIssuerAssignedId($issuerIdNode->asText());
        }
        $statusCodeNode = $node->get("ram:StatusCode");
        if ($statusCodeNode !== null) {
            $reference->setStatusCode($statusCodeNode->asText());
        }
        $typeCodeNode = $node->get("ram:TypeCode");
        if ($typeCodeNode !== null) {
            $reference->setTypeCode($typeCodeNode->asText());
        }
        $receiptNode = $node->get("ram:ReceiptDateTime/udt:DateTimeString");
        if ($receiptNode !== null) {
            $reference->setReceiptDateTime($this->parseDateTime($receiptNode));
        }
        $referenceTypeNode = $node->get("ram:ReferenceTypeCode");
        if ($referenceTypeNode !== null) {
            $reference->setReferenceTypeCode($referenceTypeNode->asText());
        }
        $formattedNode = $node->get("ram:FormattedIssueDateTime/qdt:DateTimeString");
        if ($formattedNode !== null) {
            $reference->setFormattedIssueDateTime($this->parseDateTime($formattedNode));
        }
        $processCodeNode = $node->get("ram:ProcessConditionCode");
        $processCode = null;
        if ($processCodeNode !== null) {
            $processCode = $processCodeNode->asText();
            $reference->setProcessConditionCode($processCode);
            $label = $this->resolveProcessConditionLabel($processCode, null);
            if ($label !== null) {
                $reference->setProcessCondition($label);
            } else {
                $processCode = null;
            }
        }
        if ($processCode === null) {
            $processNode = $node->get("ram:ProcessCondition");
            if ($processNode !== null) {
                $reference->setProcessCondition($processNode->asText());
            }
        }
        $issuerPartyNode = $node->get("ram:IssuerTradeParty");
        if ($issuerPartyNode !== null) {
            $reference->setIssuerTradeParty($this->parseTradeParty($issuerPartyNode));
        }
        foreach ($node->getAll("ram:SpecifiedDocumentStatus") as $statusNode) {
            $reference->addSpecifiedDocumentStatus($this->parseSpecifiedDocumentStatus($statusNode));
        }
        return $reference;
    }

    private function parseSpecifiedDocumentStatus(UXML $node): SpecifiedDocumentStatus
    {
        $status = new SpecifiedDocumentStatus();
        $referenceDateNode = $node->get("ram:ReferenceDateTime/qdt:DateTimeString | ram:ReferenceDateTime/udt:DateTimeString");
        if ($referenceDateNode !== null) {
            $status->setReferenceDateTime($this->parseDateTime($referenceDateNode));
        }
        // The label carried by the document always wins; the code is only used to
        // derive one when the element is absent
        $processNode = $node->get("ram:ProcessCondition");
        $processCodeNode = $node->get("ram:ProcessConditionCode");
        if ($processCodeNode !== null) {
            $status->setProcessConditionCode($processCodeNode->asText());
        }
        if ($processNode !== null) {
            $status->setProcessCondition($processNode->asText());
        } elseif ($processCodeNode !== null) {
            $status->setProcessCondition($this->resolveProcessConditionLabel($processCodeNode->asText(), null));
        }
        $reasonCodeNode = $node->get("ram:ReasonCode");
        if ($reasonCodeNode !== null) {
            $status->setReasonCode($reasonCodeNode->asText());
        }
        $reasonNode = $node->get("ram:Reason");
        if ($reasonNode !== null) {
            $status->setReason($reasonNode->asText());
        }
        $actionCodeNode = $node->get("ram:RequestedActionCode");
        if ($actionCodeNode !== null) {
            $status->setRequestedActionCode($actionCodeNode->asText());
        }
        $actionNode = $node->get("ram:RequestedAction");
        if ($actionNode !== null) {
            $status->setRequestedAction($actionNode->asText());
        }
        $sequenceNode = $node->get("ram:SequenceNumeric");
        if ($sequenceNode !== null) {
            $status->setSequenceNumeric((int) $sequenceNode->asText());
        }
        foreach ($node->getAll("ram:IncludedNote") as $noteNode) {
            $contentNode = $noteNode->get("ram:Content");
            $content = $contentNode?->asText();
            if ($content === null) {
                continue;
            }
            $status->addIncludedNote(
                $content,
                $this->attr($contentNode, 'languageID'),
                $noteNode->get("ram:ContentCode")?->asText(),
                $noteNode->get("ram:SubjectCode")?->asText()
            );
        }
        foreach ($node->getAll("ram:SpecifiedDocumentCharacteristic") as $characteristicNode) {
            $status->addCharacteristic($this->parseSpecifiedDocumentCharacteristic($characteristicNode));
        }
        return $status;
    }

    private function parseSpecifiedDocumentCharacteristic(UXML $node): SpecifiedDocumentCharacteristic
    {
        $characteristic = new SpecifiedDocumentCharacteristic();
        $idNode = $node->get("ram:ID");
        if ($idNode !== null) {
            $characteristic->setId($idNode->asText());
        }
        $typeCodeNode = $node->get("ram:TypeCode");
        if ($typeCodeNode !== null) {
            $characteristic->setTypeCode($typeCodeNode->asText());
        }
        $indicatorNode = $node->get("ram:ValueChangedIndicator/udt:Indicator | ram:ValueChangedIndicator/udt:IndicatorString");
        if ($indicatorNode !== null) {
            $characteristic->setValueChangedIndicator($this->parseIndicator($indicatorNode->asText()));
        }
        $descriptionNode = $node->get("ram:Description | ram:Name");
        if ($descriptionNode !== null) {
            $characteristic->setDescription($descriptionNode->asText());
        }
        $locationNode = $node->get("ram:Location");
        if ($locationNode !== null) {
            $characteristic->setLocation($locationNode->asText());
        }
        $percentNode = $node->get("ram:ValuePercent");
        if ($percentNode !== null) {
            $characteristic->setValuePercent(floatval($percentNode->asText()));
        }
        $amountNode = $node->get("ram:ValueAmount");
        if ($amountNode !== null) {
            $amount = new ValueAmount();
            $amount->setAmount(floatval($amountNode->asText()));
            $currencyId = $this->attr($amountNode, 'currencyID');
            if ($currencyId !== null) {
                $amount->setCurrencyId($currencyId);
            }
            $characteristic->setValueAmount($amount);
        }
        $dateNode = $node->get("ram:ValueDateTime/udt:DateTimeString");
        if ($dateNode !== null) {
            $characteristic->setValueDateTime($this->parseDateTime($dateNode));
        }
        $textNode = $node->get("ram:Value | ram:ValueText");
        if ($textNode !== null) {
            $characteristic->setValue($textNode->asText());
        }
        return $characteristic;
    }

    private function parseTradeParty(UXML $node): TradeParty
    {
        $party = new TradeParty();
        $globalNode = $node->get("ram:GlobalID");
        if ($globalNode !== null) {
            $party->setGlobalId($globalNode->asText());
            $scheme = $this->attr($globalNode, 'schemeID');
            if ($scheme !== null) {
                $party->setGlobalIdScheme($scheme);
            }
        }
        $nameNode = $node->get("ram:Name");
        if ($nameNode !== null) {
            $party->setName($nameNode->asText());
        }
        $roleNode = $node->get("ram:RoleCode");
        if ($roleNode !== null) {
            $party->setRoleCode($roleNode->asText());
        }
        $uriNode = $node->get("ram:URIUniversalCommunication/ram:URIID");
        if ($uriNode !== null) {
            $party->setUri($uriNode->asText());
            $scheme = $this->attr($uriNode, 'schemeID');
            if ($scheme !== null) {
                $party->setUriScheme($scheme);
            }
        }
        return $party;
    }

    private function parseDateTime(UXML $node): DateTime
    {
        $format = $this->attr($node, 'format');
        $value = $node->asText();
        if ($format === '102') {
            return DateTime::createFromFormat('Ymd', $value)->setTime(0, 0, 0);
        }
        if ($format === '204') {
            return DateTime::createFromFormat('YmdHis', $value);
        }
        return new DateTime($value);
    }

    private function parseIndicator(string $value): bool
    {
        return strtolower(trim($value)) === 'true' || trim($value) === '1';
    }

    private function resolveProcessConditionLabel(string $code, ?string $fallback): ?string
    {
        try {
            return ProcessConditionCode::from((int) $code)->xmlLabel();
        } catch (ValueError $error) {
            return $fallback;
        }
    }
}
