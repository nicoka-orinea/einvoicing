<?php
namespace Einvoicing\Cdar;

use DateTime;

/**
 * CDAR acknowledgement document holding status update.
 */
class AcknowledgementDocument
{
    private ?bool $multipleReferencesIndicator = null;
    private ?string $typeCode = null;
    private ?DateTime $issueDateTime = null;
    private ?ReferenceReferencedDocument $reference = null;

    /**
     * Get whether the acknowledgement refers to multiple documents.
     * Business meaning: mono-document vs multi-document indicator.
     */
    public function getMultipleReferencesIndicator(): ?bool
    {
        return $this->multipleReferencesIndicator;
    }

    /**
     * Set whether the acknowledgement refers to multiple documents.
     * Business meaning: mono-document vs multi-document indicator.
     */
    public function setMultipleReferencesIndicator(?bool $multipleReferencesIndicator): self
    {
        $this->multipleReferencesIndicator = $multipleReferencesIndicator;
        return $this;
    }

    /**
     * Get the acknowledgement document type code.
     * Business meaning: CDAR acknowledgement type (e.g. 23, 305).
     */
    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    /**
     * Set the acknowledgement document type code.
     * Business meaning: CDAR acknowledgement type (e.g. 23, 305).
     */
    public function setTypeCode(?string $typeCode): self
    {
        $this->typeCode = $typeCode;
        return $this;
    }

    /**
     * Get the acknowledgement issue date-time.
     * Business meaning: timestamp of the status deposit.
     */
    public function getIssueDateTime(): ?DateTime
    {
        return $this->issueDateTime;
    }

    /**
     * Set the acknowledgement issue date-time.
     * Business meaning: timestamp of the status deposit.
     */
    public function setIssueDateTime(?DateTime $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    /**
     * Get the referenced document data.
     * Business meaning: invoice reference and status payload.
     */
    public function getReference(): ?ReferenceReferencedDocument
    {
        return $this->reference;
    }

    /**
     * Set the referenced document data.
     * Business meaning: invoice reference and status payload.
     */
    public function setReference(?ReferenceReferencedDocument $reference): self
    {
        $this->reference = $reference;
        return $this;
    }
}
