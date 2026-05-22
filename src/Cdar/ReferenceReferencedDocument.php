<?php
namespace Einvoicing\Cdar;

use DateTime;
use Einvoicing\Cdar\Enums\DocumentTypeCode;
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\Enums\StatusCode;

/**
 * CDAR referenced document and its status update.
 */
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
    /** @var SpecifiedDocumentStatus[] */
    private array $specifiedDocumentStatuses = [];

    /**
     * Get the referenced invoice identifier.
     * Business meaning: invoice number (BT-1).
     */
    public function getIssuerAssignedId(): ?string
    {
        return $this->issuerAssignedId;
    }

    /**
     * Set the referenced invoice identifier.
     * Business meaning: invoice number (BT-1).
     */
    public function setIssuerAssignedId(?string $issuerAssignedId): self
    {
        $this->issuerAssignedId = $issuerAssignedId;
        return $this;
    }

    /**
     * Get the status code.
     * Business meaning: CDAR status category code.
     */
    public function getStatusCode(): ?string
    {
        return $this->statusCode;
    }

    /**
     * Set the status code.
     * Business meaning: CDAR status category code.
     */
    public function setStatusCode(StatusCode|int|string|null $statusCode): self
    {
        if ($statusCode instanceof StatusCode) {
            $statusCode = $statusCode->value;
        }
        $this->statusCode = ($statusCode === null) ? null : (string) $statusCode;
        return $this;
    }

    /**
     * Get the referenced document type code.
     * Business meaning: type of document concerned by the CDAR (e.g. invoice).
     */
    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    /**
     * Get the referenced document type enum.
     * Business meaning: type of document concerned by the CDAR (e.g. invoice).
     */
    public function getDocumentType(): ?DocumentTypeCode
    {
        if ($this->typeCode === null) {
            return null;
        }
        return DocumentTypeCode::tryFrom((int) $this->typeCode);
    }

    /**
     * Set the referenced document type code.
     * Business meaning: type of document concerned by the CDAR (e.g. invoice).
     */
    public function setTypeCode(DocumentTypeCode|int|string|null $typeCode): self
    {
        if ($typeCode instanceof DocumentTypeCode) {
            $typeCode = $typeCode->value;
        }
        $this->typeCode = ($typeCode === null) ? null : (string) $typeCode;
        return $this;
    }

    /**
     * Get the receipt date-time.
     * Business meaning: date-time when the invoice was received.
     */
    public function getReceiptDateTime(): ?DateTime
    {
        return $this->receiptDateTime;
    }

    /**
     * Set the receipt date-time.
     * Business meaning: date-time when the invoice was received.
     */
    public function setReceiptDateTime(?DateTime $receiptDateTime): self
    {
        $this->receiptDateTime = $receiptDateTime;
        return $this;
    }

    /**
     * Get the reference type code.
     * Business meaning: reference profile for the original document.
     */
    public function getReferenceTypeCode(): ?string
    {
        return $this->referenceTypeCode;
    }

    /**
     * Set the reference type code.
     * Business meaning: reference profile for the original document.
     */
    public function setReferenceTypeCode(?string $referenceTypeCode): self
    {
        $this->referenceTypeCode = $referenceTypeCode;
        return $this;
    }

    /**
     * Get the formatted issue date-time.
     * Business meaning: invoice issue date (BT-2).
     */
    public function getFormattedIssueDateTime(): ?DateTime
    {
        return $this->formattedIssueDateTime;
    }

    /**
     * Set the formatted issue date-time.
     * Business meaning: invoice issue date (BT-2).
     */
    public function setFormattedIssueDateTime(?DateTime $formattedIssueDateTime): self
    {
        $this->formattedIssueDateTime = $formattedIssueDateTime;
        return $this;
    }

    /**
     * Get the process condition code.
     * Business meaning: detailed lifecycle step (e.g. 200, 212).
     */
    public function getProcessConditionCode(): ?string
    {
        return $this->processConditionCode;
    }

    /**
     * Set the process condition code.
     * Business meaning: detailed lifecycle step (e.g. 200, 212).
     */
    public function setProcessConditionCode(ProcessConditionCode|int|string|null $processConditionCode): self
    {
        if ($processConditionCode instanceof ProcessConditionCode) {
            $processConditionCode = $processConditionCode->value;
        }
        $this->processConditionCode = ($processConditionCode === null) ? null : (string) $processConditionCode;
        return $this;
    }

    /**
     * Get the process condition label.
     * Business meaning: lifecycle status label.
     */
    public function getProcessCondition(): ?string
    {
        return $this->processCondition;
    }

    /**
     * Set the process condition label.
     * Business meaning: lifecycle status label.
     */
    public function setProcessCondition(?string $processCondition): self
    {
        $this->processCondition = $processCondition;
        return $this;
    }

    /**
     * Get the issuer trade party for the referenced document.
     * Business meaning: issuer of the referenced invoice.
     */
    public function getIssuerTradeParty(): ?TradeParty
    {
        return $this->issuerTradeParty;
    }

    /**
     * Set the issuer trade party for the referenced document.
     * Business meaning: issuer of the referenced invoice.
     */
    public function setIssuerTradeParty(?TradeParty $issuerTradeParty): self
    {
        $this->issuerTradeParty = $issuerTradeParty;
        return $this;
    }

    /**
     * Get the detailed document status data.
     * Business meaning: rejection/adjustment details and data points.
     */
    public function getSpecifiedDocumentStatus(): ?SpecifiedDocumentStatus
    {
        return $this->specifiedDocumentStatuses[0] ?? null;
    }

    /**
     * Set the detailed document status data.
     * Business meaning: rejection/adjustment details and data points.
     */
    public function setSpecifiedDocumentStatus(?SpecifiedDocumentStatus $specifiedDocumentStatus): self
    {
        $this->specifiedDocumentStatuses = $specifiedDocumentStatus === null ? [] : [$specifiedDocumentStatus];
        return $this;
    }

    /**
     * @return SpecifiedDocumentStatus[]
     * Business meaning: all detailed lifecycle events carried by the CDAR.
     */
    public function getSpecifiedDocumentStatuses(): array
    {
        return $this->specifiedDocumentStatuses;
    }

    /**
     * @param SpecifiedDocumentStatus[] $specifiedDocumentStatuses
     * Business meaning: all detailed lifecycle events carried by the CDAR.
     */
    public function setSpecifiedDocumentStatuses(array $specifiedDocumentStatuses): self
    {
        $this->specifiedDocumentStatuses = $specifiedDocumentStatuses;
        return $this;
    }

    /**
     * Add one detailed lifecycle event.
     * Business meaning: append a status block to the referenced document.
     */
    public function addSpecifiedDocumentStatus(SpecifiedDocumentStatus $specifiedDocumentStatus): self
    {
        $this->specifiedDocumentStatuses[] = $specifiedDocumentStatus;
        return $this;
    }

    /**
     * Apply a process condition and populate related fields.
     * Business meaning: ensures status code and label align with the lifecycle step.
     */
    public function applyProcessCondition(ProcessConditionCode $processConditionCode): self
    {
        $status = (new SpecifiedDocumentStatus())
            ->setProcessConditionCode((string) $processConditionCode->value)
            ->setProcessCondition($processConditionCode->xmlLabel());

        return $this
            ->setProcessConditionCode($processConditionCode)
            ->setProcessCondition($processConditionCode->xmlLabel())
            ->setStatusCode($processConditionCode->statusCode())
            ->setSpecifiedDocumentStatus($status);
    }
}
