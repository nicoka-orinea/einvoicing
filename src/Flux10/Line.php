<?php

namespace Einvoicing\Flux10;

use OutOfBoundsException;
use function array_splice;
use function count;

/**
 * Invoice line — TG-24.
 *
 * Beyond the "CIBLE" trajectory (G6.15), lines are what makes a mixed invoicing
 * framework (M1, M2, M4) reportable at all: the Correspondance sheet of Annexe 6
 * requires goods and services to be told apart, and only lines carry that.
 */
class Line
{
    /**
     * Line notes (TT-61).
     * @var Note[]
     */
    protected array $notes = [];

    /**
     * Invoiced quantity (TT-62).
     * @var float|string|null
     */
    protected float|string|null $billedQuantity = null;

    /**
     * Unit of measure of the invoiced quantity (TT-63).
     * @var string|null
     */
    protected ?string $unitCode = null;

    /**
     * Reference to an earlier invoice (TG-40).
     * @var ReferencedDocument|null
     */
    protected ?ReferencedDocument $referencedDocument = null;

    /**
     * Line delivery detail (TG-41).
     * @var Delivery|null
     */
    protected ?Delivery $delivery = null;

    /**
     * Line invoicing period (TG-25).
     * @var Period|null
     */
    protected ?Period $invoicePeriod = null;

    /**
     * Line allowances and charges (TG-26, TG-27).
     * @var AllowanceCharge[]
     */
    protected array $allowancesCharges = [];

    /**
     * Price detail (TG-28).
     * @var Price|null
     */
    protected ?Price $price = null;

    /**
     * Item name (TT-76).
     * @var string|null
     */
    protected ?string $productName = null;

    /**
     * @return Note[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * Add a line note (TT-61).
     */
    public function addNote(Note $note): self
    {
        $this->notes[] = $note;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeNote(int $index): self
    {
        if ($index < 0 || $index >= count($this->notes)) {
            throw new OutOfBoundsException('Could not find note by index');
        }
        array_splice($this->notes, $index, 1);
        return $this;
    }

    /**
     * Clear all line notes.
     */
    public function clearNotes(): self
    {
        $this->notes = [];
        return $this;
    }

    /**
     * Get the invoiced quantity (TT-62).
     */
    public function getBilledQuantity(): float|string|null
    {
        return $this->billedQuantity;
    }

    /**
     * Set the invoiced quantity (TT-62, G1.15: up to 4 decimals).
     *
     * @param float|string|null $billedQuantity
     */
    public function setBilledQuantity(float|string|null $billedQuantity): self
    {
        $this->billedQuantity = $billedQuantity;
        return $this;
    }

    /**
     * Get the unit of measure (TT-63).
     */
    public function getUnitCode(): ?string
    {
        return $this->unitCode;
    }

    /**
     * Set the unit of measure (TT-63), from the EN 16931 code list.
     */
    public function setUnitCode(?string $unitCode): self
    {
        $this->unitCode = $unitCode;
        return $this;
    }

    /**
     * Get the reference to an earlier invoice (TG-40).
     */
    public function getReferencedDocument(): ?ReferencedDocument
    {
        return $this->referencedDocument;
    }

    /**
     * Set the reference to an earlier invoice (TG-40).
     *
     * A credit note may carry its references here instead of in the header (G1.32).
     */
    public function setReferencedDocument(?ReferencedDocument $referencedDocument): self
    {
        $this->referencedDocument = $referencedDocument;
        return $this;
    }

    /**
     * Get the line delivery detail (TG-41).
     */
    public function getDelivery(): ?Delivery
    {
        return $this->delivery;
    }

    /**
     * Set the line delivery detail (TG-41).
     */
    public function setDelivery(?Delivery $delivery): self
    {
        $this->delivery = $delivery;
        return $this;
    }

    /**
     * Get the line invoicing period (TG-25).
     */
    public function getInvoicePeriod(): ?Period
    {
        return $this->invoicePeriod;
    }

    /**
     * Set the line invoicing period (TG-25, G6.20).
     */
    public function setInvoicePeriod(?Period $invoicePeriod): self
    {
        $this->invoicePeriod = $invoicePeriod;
        return $this;
    }

    /**
     * @return AllowanceCharge[]
     */
    public function getAllowancesCharges(): array
    {
        return $this->allowancesCharges;
    }

    /**
     * Add a line allowance or charge (TG-26, TG-27).
     */
    public function addAllowanceCharge(AllowanceCharge $allowanceCharge): self
    {
        $this->allowancesCharges[] = $allowanceCharge;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeAllowanceCharge(int $index): self
    {
        if ($index < 0 || $index >= count($this->allowancesCharges)) {
            throw new OutOfBoundsException('Could not find allowance or charge by index');
        }
        array_splice($this->allowancesCharges, $index, 1);
        return $this;
    }

    /**
     * Clear all line allowances and charges.
     */
    public function clearAllowancesCharges(): self
    {
        $this->allowancesCharges = [];
        return $this;
    }

    /**
     * Get the price detail (TG-28).
     */
    public function getPrice(): ?Price
    {
        return $this->price;
    }

    /**
     * Set the price detail (TG-28).
     */
    public function setPrice(?Price $price): self
    {
        $this->price = $price;
        return $this;
    }

    /**
     * Get the item name (TT-76).
     */
    public function getProductName(): ?string
    {
        return $this->productName;
    }

    /**
     * Set the item name (TT-76), the only mandatory element of TG-30.
     */
    public function setProductName(?string $productName): self
    {
        $this->productName = $productName;
        return $this;
    }
}
