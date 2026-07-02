<?php
namespace Einvoicing\Cdar;

use DateTime;

/**
 * Detailed CDAR status information including reasons and characteristics.
 */
class SpecifiedDocumentStatus
{
    private ?DateTime $referenceDateTime = null;
    private ?string $processConditionCode = null;
    private ?string $processCondition = null;
    private ?string $reasonCode = null;
    private ?string $reason = null;
    private ?string $requestedActionCode = null;
    private ?string $requestedAction = null;
    private ?int $sequenceNumeric = null;
    private ?string $includedNoteContentCode = null;
    /** @var array<int, array{content: string, languageId: ?string}> */
    private array $includedNotes = [];
    /** @var SpecifiedDocumentCharacteristic[] */
    private array $characteristics = [];

    /**
     * Get the status reference date-time.
     * Business meaning: timestamp when this lifecycle event occurred.
     */
    public function getReferenceDateTime(): ?DateTime
    {
        return $this->referenceDateTime;
    }

    /**
     * Set the status reference date-time.
     * Business meaning: timestamp when this lifecycle event occurred.
     */
    public function setReferenceDateTime(?DateTime $referenceDateTime): self
    {
        $this->referenceDateTime = $referenceDateTime;
        return $this;
    }

    /**
     * Get the process condition code.
     * Business meaning: detailed lifecycle status code.
     */
    public function getProcessConditionCode(): ?string
    {
        return $this->processConditionCode;
    }

    /**
     * Set the process condition code.
     * Business meaning: detailed lifecycle status code.
     */
    public function setProcessConditionCode(?string $processConditionCode): self
    {
        $this->processConditionCode = $processConditionCode;
        return $this;
    }

    /**
     * Get the process condition label.
     * Business meaning: lifecycle status label as exchanged in CDAR.
     */
    public function getProcessCondition(): ?string
    {
        return $this->processCondition;
    }

    /**
     * Set the process condition label.
     * Business meaning: lifecycle status label as exchanged in CDAR.
     */
    public function setProcessCondition(?string $processCondition): self
    {
        $this->processCondition = $processCondition;
        return $this;
    }

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

    public function getIncludedNoteContentCode(): ?string
    {
        return $this->includedNoteContentCode;
    }

    public function setIncludedNoteContentCode(?string $includedNoteContentCode): self
    {
        $this->includedNoteContentCode = $includedNoteContentCode;
        return $this;
    }

    /**
     * @return array<int, array{content: string, languageId: ?string}>
     */
    public function getIncludedNotes(): array
    {
        return $this->includedNotes;
    }

    /**
     * @param array<int, array{content: string, languageId?: ?string}> $includedNotes
     */
    public function setIncludedNotes(array $includedNotes): self
    {
        $this->includedNotes = [];
        foreach ($includedNotes as $note) {
            if (!isset($note['content'])) {
                continue;
            }
            $this->addIncludedNote((string) $note['content'], $note['languageId'] ?? null);
        }
        return $this;
    }

    public function addIncludedNote(string $content, ?string $languageId = null): self
    {
        $content = trim($content);
        if ($content === '') {
            return $this;
        }
        $this->includedNotes[] = [
            'content' => $content,
            'languageId' => $languageId,
        ];
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
