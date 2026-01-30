<?php
namespace Einvoicing\Cdar;

class SpecifiedDocumentStatus
{
    private ?string $reasonCode = null;
    private ?string $reason = null;
    private ?string $requestedActionCode = null;
    private ?string $requestedAction = null;
    private ?int $sequenceNumeric = null;
    /** @var SpecifiedDocumentCharacteristic[] */
    private array $characteristics = [];

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(?string $reasonCode): self
    {
        $this->reasonCode = $reasonCode;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getRequestedActionCode(): ?string
    {
        return $this->requestedActionCode;
    }

    public function setRequestedActionCode(?string $requestedActionCode): self
    {
        $this->requestedActionCode = $requestedActionCode;
        return $this;
    }

    public function getRequestedAction(): ?string
    {
        return $this->requestedAction;
    }

    public function setRequestedAction(?string $requestedAction): self
    {
        $this->requestedAction = $requestedAction;
        return $this;
    }

    public function getSequenceNumeric(): ?int
    {
        return $this->sequenceNumeric;
    }

    public function setSequenceNumeric(?int $sequenceNumeric): self
    {
        $this->sequenceNumeric = $sequenceNumeric;
        return $this;
    }

    /**
     * @return SpecifiedDocumentCharacteristic[]
     */
    public function getCharacteristics(): array
    {
        return $this->characteristics;
    }

    /**
     * @param SpecifiedDocumentCharacteristic[] $characteristics
     */
    public function setCharacteristics(array $characteristics): self
    {
        $this->characteristics = $characteristics;
        return $this;
    }

    public function addCharacteristic(SpecifiedDocumentCharacteristic $characteristic): self
    {
        $this->characteristics[] = $characteristic;
        return $this;
    }
}
