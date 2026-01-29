<?php
namespace Einvoicing\Cdar;

/**
 * Detailed CDAR status information including reasons and characteristics.
 */
class SpecifiedDocumentStatus
{
    private ?string $reasonCode = null;
    private ?string $reason = null;
    private ?string $requestedActionCode = null;
    private ?string $requestedAction = null;
    private ?int $sequenceNumeric = null;
    /** @var SpecifiedDocumentCharacteristic[] */
    private array $characteristics = [];

    /**
     * Get the reason code.
     * Business meaning: rejection or dispute reason code.
     */
    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    /**
     * Set the reason code.
     * Business meaning: rejection or dispute reason code.
     */
    public function setReasonCode(?string $reasonCode): self
    {
        $this->reasonCode = $reasonCode;
        return $this;
    }

    /**
     * Get the reason label.
     * Business meaning: human-readable rejection or dispute reason.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Set the reason label.
     * Business meaning: human-readable rejection or dispute reason.
     */
    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * Get the requested action code.
     * Business meaning: action expected from the sender.
     */
    public function getRequestedActionCode(): ?string
    {
        return $this->requestedActionCode;
    }

    /**
     * Set the requested action code.
     * Business meaning: action expected from the sender.
     */
    public function setRequestedActionCode(?string $requestedActionCode): self
    {
        $this->requestedActionCode = $requestedActionCode;
        return $this;
    }

    /**
     * Get the requested action label.
     * Business meaning: description of the expected action.
     */
    public function getRequestedAction(): ?string
    {
        return $this->requestedAction;
    }

    /**
     * Set the requested action label.
     * Business meaning: description of the expected action.
     */
    public function setRequestedAction(?string $requestedAction): self
    {
        $this->requestedAction = $requestedAction;
        return $this;
    }

    /**
     * Get the sequence number.
     * Business meaning: sequence of the reason/action entry.
     */
    public function getSequenceNumeric(): ?int
    {
        return $this->sequenceNumeric;
    }

    /**
     * Set the sequence number.
     * Business meaning: sequence of the reason/action entry.
     */
    public function setSequenceNumeric(?int $sequenceNumeric): self
    {
        $this->sequenceNumeric = $sequenceNumeric;
        return $this;
    }

    /**
     * @return SpecifiedDocumentCharacteristic[]
     * Business meaning: list of affected data points.
     */
    public function getCharacteristics(): array
    {
        return $this->characteristics;
    }

    /**
     * @param SpecifiedDocumentCharacteristic[] $characteristics
     * Business meaning: list of affected data points.
     */
    public function setCharacteristics(array $characteristics): self
    {
        $this->characteristics = $characteristics;
        return $this;
    }

    /**
     * Add a characteristic.
     * Business meaning: add one affected data point.
     */
    public function addCharacteristic(SpecifiedDocumentCharacteristic $characteristic): self
    {
        $this->characteristics[] = $characteristic;
        return $this;
    }
}
