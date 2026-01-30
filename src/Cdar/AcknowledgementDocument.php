<?php
namespace Einvoicing\Cdar;

use DateTime;

class AcknowledgementDocument
{
    private ?bool $multipleReferencesIndicator = null;
    private ?string $typeCode = null;
    private ?DateTime $issueDateTime = null;
    private ?ReferenceReferencedDocument $reference = null;

    public function getMultipleReferencesIndicator(): ?bool
    {
        return $this->multipleReferencesIndicator;
    }

    public function setMultipleReferencesIndicator(?bool $multipleReferencesIndicator): self
    {
        $this->multipleReferencesIndicator = $multipleReferencesIndicator;
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

    public function getIssueDateTime(): ?DateTime
    {
        return $this->issueDateTime;
    }

    public function setIssueDateTime(?DateTime $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    public function getReference(): ?ReferenceReferencedDocument
    {
        return $this->reference;
    }

    public function setReference(?ReferenceReferencedDocument $reference): self
    {
        $this->reference = $reference;
        return $this;
    }
}
