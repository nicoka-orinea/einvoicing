<?php
namespace Einvoicing\Cdar;

use DateTime;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\Enums\StatusCode;

class ReferenceReferencedDocument
{
    private ?string $issuerAssignedId = null;
    private ?string $statusCode = null;
    private ?string $typeCode = null;
    private ?DateTime $receiptDateTime = null;
    private ?string $referenceTypeCode = null;
    private ?DateTime $formattedIssueDateTime = null;
    private ?string $processConditionCode = null;
    private ?string $processCondition = null;
    private ?TradeParty $issuerTradeParty = null;
    private ?SpecifiedDocumentStatus $specifiedDocumentStatus = null;

    public function getIssuerAssignedId(): ?string
    {
        return $this->issuerAssignedId;
    }

    public function setIssuerAssignedId(?string $issuerAssignedId): self
    {
        $this->issuerAssignedId = $issuerAssignedId;
        return $this;
    }

    public function getStatusCode(): ?string
    {
        return $this->statusCode;
    }

    public function setStatusCode(StatusCode|int|string|null $statusCode): self
    {
        if ($statusCode instanceof StatusCode) {
            $statusCode = $statusCode->value;
        }
        $this->statusCode = ($statusCode === null) ? null : (string) $statusCode;
        return $this;
    }

    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    public function setTypeCode(?string $typeCode): self
    {
        $this->typeCode = $typeCode;
        return $this;
    }

    public function getReceiptDateTime(): ?DateTime
    {
        return $this->receiptDateTime;
    }

    public function setReceiptDateTime(?DateTime $receiptDateTime): self
    {
        $this->receiptDateTime = $receiptDateTime;
        return $this;
    }

    public function getReferenceTypeCode(): ?string
    {
        return $this->referenceTypeCode;
    }

    public function setReferenceTypeCode(?string $referenceTypeCode): self
    {
        $this->referenceTypeCode = $referenceTypeCode;
        return $this;
    }

    public function getFormattedIssueDateTime(): ?DateTime
    {
        return $this->formattedIssueDateTime;
    }

    public function setFormattedIssueDateTime(?DateTime $formattedIssueDateTime): self
    {
        $this->formattedIssueDateTime = $formattedIssueDateTime;
        return $this;
    }

    public function getProcessConditionCode(): ?string
    {
        return $this->processConditionCode;
    }

    public function setProcessConditionCode(ProcessConditionCode|int|string|null $processConditionCode): self
    {
        if ($processConditionCode instanceof ProcessConditionCode) {
            $processConditionCode = $processConditionCode->value;
        }
        $this->processConditionCode = ($processConditionCode === null) ? null : (string) $processConditionCode;
        return $this;
    }

    public function getProcessCondition(): ?string
    {
        return $this->processCondition;
    }

    public function setProcessCondition(?string $processCondition): self
    {
        $this->processCondition = $processCondition;
        return $this;
    }

    public function getIssuerTradeParty(): ?TradeParty
    {
        return $this->issuerTradeParty;
    }

    public function setIssuerTradeParty(?TradeParty $issuerTradeParty): self
    {
        $this->issuerTradeParty = $issuerTradeParty;
        return $this;
    }

    public function getSpecifiedDocumentStatus(): ?SpecifiedDocumentStatus
    {
        return $this->specifiedDocumentStatus;
    }

    public function setSpecifiedDocumentStatus(?SpecifiedDocumentStatus $specifiedDocumentStatus): self
    {
        $this->specifiedDocumentStatus = $specifiedDocumentStatus;
        return $this;
    }

    public function applyProcessCondition(ProcessConditionCode $processConditionCode): self
    {
        return $this
            ->setProcessConditionCode($processConditionCode)
            ->setProcessCondition($processConditionCode->label())
            ->setStatusCode($processConditionCode->statusCode());
    }
}
