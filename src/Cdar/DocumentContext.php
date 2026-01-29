<?php
namespace Einvoicing\Cdar;

/**
 * CDAR document context metadata.
 */
class DocumentContext
{
    private ?string $businessProcessId = null;
    private ?string $guidelineId = null;

    /**
     * Get the business process identifier.
     * Business meaning: billing framework or process scope (e.g. REGULATED).
     */
    public function getBusinessProcessId(): ?string
    {
        return $this->businessProcessId;
    }

    /**
     * Set the business process identifier.
     * Business meaning: billing framework or process scope (e.g. REGULATED).
     */
    public function setBusinessProcessId(?string $businessProcessId): self
    {
        $this->businessProcessId = $businessProcessId;
        return $this;
    }

    /**
     * Get the guideline identifier.
     */
    /**
     * Get the guideline identifier.
     * Business meaning: CDAR profile or use-case identifier.
     */
    public function getGuidelineId(): ?string
    {
        return $this->guidelineId;
    }

    /**
     * Set the guideline identifier.
     * Business meaning: CDAR profile or use-case identifier.
     */
    public function setGuidelineId(?string $guidelineId): self
    {
        $this->guidelineId = $guidelineId;
        return $this;
    }
}
