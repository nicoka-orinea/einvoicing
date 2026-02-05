<?php

namespace Einvoicing\Flux10;

use DateTime;
use OutOfBoundsException;
use function array_splice;
use function count;

class Invoice
{
    /**
     * Invoice identifier.
     * @var string|null
     */
    protected $invoiceId = null;

    /**
     * Invoice issue date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $issueDate = null;

    /**
     * Invoice type code.
     * @var string|null
     */
    protected $typeCode = null;

    /**
     * Seller SIREN (FR only).
     * @var string|null
     */
    protected $sellerId = null;

    /**
     * Seller country code.
     * @var string|null
     */
    protected $sellerCountry = null;

    /**
     * Seller VAT ID (non-FR).
     * @var string|null
     */
    protected $sellerVatId = null;

    /**
     * Buyer SIREN (FR only).
     * @var string|null
     */
    protected $buyerId = null;

    /**
     * Buyer country code.
     * @var string|null
     */
    protected $buyerCountry = null;

    /**
     * Buyer VAT ID (non-FR).
     * @var string|null
     */
    protected $buyerVatId = null;

    /**
     * Amount without VAT.
     * @var float|string|null
     */
    protected float|string|null $taxExclusiveAmount = null;

    /**
     * VAT amount.
     * @var float|string|null
     */
    protected float|string|null $taxAmount = null;

    /**
     * VAT breakdown lines.
     * @var TaxBreakdown[]
     */
    protected $taxBreakdown = [];

    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(?string $invoiceId): self
    {
        $this->invoiceId = $invoiceId;
        return $this;
    }

    public function getIssueDate(): DateTime|string|null
    {
        return $this->issueDate;
    }

    /**
     * @param DateTime|string|null $issueDate
     */
    public function setIssueDate(DateTime|string|null $issueDate): self
    {
        $this->issueDate = $issueDate;
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

    public function getSellerId(): ?string
    {
        return $this->sellerId;
    }

    public function setSellerId(?string $sellerId): self
    {
        $this->sellerId = $sellerId;
        return $this;
    }

    public function getSellerCountry(): ?string
    {
        return $this->sellerCountry;
    }

    public function setSellerCountry(?string $sellerCountry): self
    {
        $this->sellerCountry = $sellerCountry;
        return $this;
    }

    public function getSellerVatId(): ?string
    {
        return $this->sellerVatId;
    }

    public function setSellerVatId(?string $sellerVatId): self
    {
        $this->sellerVatId = $sellerVatId;
        return $this;
    }

    public function getBuyerId(): ?string
    {
        return $this->buyerId;
    }

    public function setBuyerId(?string $buyerId): self
    {
        $this->buyerId = $buyerId;
        return $this;
    }

    public function getBuyerCountry(): ?string
    {
        return $this->buyerCountry;
    }

    public function setBuyerCountry(?string $buyerCountry): self
    {
        $this->buyerCountry = $buyerCountry;
        return $this;
    }

    public function getBuyerVatId(): ?string
    {
        return $this->buyerVatId;
    }

    public function setBuyerVatId(?string $buyerVatId): self
    {
        $this->buyerVatId = $buyerVatId;
        return $this;
    }

    public function getTaxExclusiveAmount(): float|string|null
    {
        return $this->taxExclusiveAmount;
    }

    /**
     * @param float|string|null $taxExclusiveAmount
     */
    public function setTaxExclusiveAmount(float|string|null $taxExclusiveAmount): self
    {
        $this->taxExclusiveAmount = $taxExclusiveAmount;
        return $this;
    }

    public function getTaxAmount(): float|string|null
    {
        return $this->taxAmount;
    }

    /**
     * @param float|string|null $taxAmount
     */
    public function setTaxAmount(float|string|null $taxAmount): self
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    /**
     * @return TaxBreakdown[]
     */
    public function getTaxBreakdown(): array
    {
        return $this->taxBreakdown;
    }

    public function addTaxBreakdownItem(TaxBreakdown $item): self
    {
        $this->taxBreakdown[] = $item;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeTaxBreakdownItem(int $index): self
    {
        if ($index < 0 || $index >= count($this->taxBreakdown)) {
            throw new OutOfBoundsException('Could not find taxBreakdown item by index');
        }

        array_splice($this->taxBreakdown, $index, 1);
        return $this;
    }

    public function clearTaxBreakdown(): self
    {
        $this->taxBreakdown = [];
        return $this;
    }
}
