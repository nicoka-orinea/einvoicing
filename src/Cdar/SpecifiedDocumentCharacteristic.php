<?php
namespace Einvoicing\Cdar;

use DateTime;

/**
 * CDAR characteristic describing a value or change.
 */
class SpecifiedDocumentCharacteristic
{
    private ?string $id = null;
    private ?string $typeCode = null;
    private ?bool $valueChangedIndicator = null;
    private ?string $description = null;
    private ?string $location = null;
    private ?float $valuePercent = null;
    private ?ValueAmount $valueAmount = null;
    private ?DateTime $valueDateTime = null;
    private ?string $value = null;

    /**
     * Get the characteristic identifier.
     * Business meaning: business term identifier (e.g. BT-152).
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Set the characteristic identifier.
     * Business meaning: business term identifier (e.g. BT-152).
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the characteristic type code.
     * Business meaning: data type code for the value (e.g. DIV, DVA).
     */
    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    /**
     * Set the characteristic type code.
     * Business meaning: data type code for the value (e.g. DIV, DVA).
     */
    public function setTypeCode(?string $typeCode): self
    {
        $this->typeCode = $typeCode;
        return $this;
    }

    /**
     * Get whether the value changed.
     * Business meaning: indicates whether a data value differs.
     */
    public function getValueChangedIndicator(): ?bool
    {
        return $this->valueChangedIndicator;
    }

    /**
     * Set whether the value changed.
     * Business meaning: indicates whether a data value differs.
     */
    public function setValueChangedIndicator(?bool $valueChangedIndicator): self
    {
        $this->valueChangedIndicator = $valueChangedIndicator;
        return $this;
    }

    /**
     * Get the characteristic name.
     * Business meaning: human-readable data point name.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set the characteristic name.
     * Business meaning: human-readable data point name.
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Legacy alias for getDescription().
     */
    public function getName(): ?string
    {
        return $this->getDescription();
    }

    /**
     * Legacy alias for setDescription().
     */
    public function setName(?string $name): self
    {
        return $this->setDescription($name);
    }

    /**
     * Get the XML path location.
     * Business meaning: XPath-like location of the data point.
     */
    public function getLocation(): ?string
    {
        return $this->location;
    }

    /**
     * Set the XML path location.
     * Business meaning: XPath-like location of the data point.
     */
    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    /**
     * Get the percent value.
     * Business meaning: percentage value for the data point.
     */
    public function getValuePercent(): ?float
    {
        return $this->valuePercent;
    }

    /**
     * Set the percent value.
     * Business meaning: percentage value for the data point.
     */
    public function setValuePercent(?float $valuePercent): self
    {
        $this->valuePercent = $valuePercent;
        return $this;
    }

    /**
     * Get the amount value.
     * Business meaning: monetary amount for the data point.
     */
    public function getValueAmount(): ?ValueAmount
    {
        return $this->valueAmount;
    }

    /**
     * Set the amount value.
     * Business meaning: monetary amount for the data point.
     */
    public function setValueAmount(?ValueAmount $valueAmount): self
    {
        $this->valueAmount = $valueAmount;
        return $this;
    }

    /**
     * Get the value date-time.
     * Business meaning: date associated with the data point.
     */
    public function getValueDateTime(): ?DateTime
    {
        return $this->valueDateTime;
    }

    /**
     * Set the value date-time.
     * Business meaning: date associated with the data point.
     */
    public function setValueDateTime(?DateTime $valueDateTime): self
    {
        $this->valueDateTime = $valueDateTime;
        return $this;
    }

    /**
     * Get the text value.
     * Business meaning: text value for the data point.
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * Set the text value.
     * Business meaning: text value for the data point.
     */
    public function setValue(?string $value): self
    {
        $this->value = $value;
        return $this;
    }

    /**
     * Legacy alias for getValue().
     */
    public function getValueText(): ?string
    {
        return $this->getValue();
    }

    /**
     * Legacy alias for setValue().
     */
    public function setValueText(?string $valueText): self
    {
        return $this->setValue($valueText);
    }
}
