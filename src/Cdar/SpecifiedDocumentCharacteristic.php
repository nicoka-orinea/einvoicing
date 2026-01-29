<?php
namespace Einvoicing\Cdar;

use DateTime;

class SpecifiedDocumentCharacteristic
{
    private ?string $id = null;
    private ?string $typeCode = null;
    private ?bool $valueChangedIndicator = null;
    private ?string $name = null;
    private ?string $location = null;
    private ?float $valuePercent = null;
    private ?ValueAmount $valueAmount = null;
    private ?DateTime $valueDateTime = null;
    private ?string $valueText = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
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

    public function getValueChangedIndicator(): ?bool
    {
        return $this->valueChangedIndicator;
    }

    public function setValueChangedIndicator(?bool $valueChangedIndicator): self
    {
        $this->valueChangedIndicator = $valueChangedIndicator;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    public function getValuePercent(): ?float
    {
        return $this->valuePercent;
    }

    public function setValuePercent(?float $valuePercent): self
    {
        $this->valuePercent = $valuePercent;
        return $this;
    }

    public function getValueAmount(): ?ValueAmount
    {
        return $this->valueAmount;
    }

    public function setValueAmount(?ValueAmount $valueAmount): self
    {
        $this->valueAmount = $valueAmount;
        return $this;
    }

    public function getValueDateTime(): ?DateTime
    {
        return $this->valueDateTime;
    }

    public function setValueDateTime(?DateTime $valueDateTime): self
    {
        $this->valueDateTime = $valueDateTime;
        return $this;
    }

    public function getValueText(): ?string
    {
        return $this->valueText;
    }

    public function setValueText(?string $valueText): self
    {
        $this->valueText = $valueText;
        return $this;
    }
}
