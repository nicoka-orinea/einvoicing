<?php
namespace Einvoicing\Cdar;

class DocumentContext
{
    private ?string $businessProcessId = null;
    private ?string $guidelineId = null;

    public function getBusinessProcessId(): ?string
    {
        return $this->businessProcessId;
    }

    public function setBusinessProcessId(?string $businessProcessId): self
    {
        $this->businessProcessId = $businessProcessId;
        return $this;
    }

    public function getGuidelineId(): ?string
    {
        return $this->guidelineId;
    }

    public function setGuidelineId(?string $guidelineId): self
    {
        $this->guidelineId = $guidelineId;
        return $this;
    }
}
