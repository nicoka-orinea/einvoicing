<?php

namespace Einvoicing\Flux10;

use DateTime;
use Einvoicing\Flux10\Enums\BusinessProcessCode;
use Einvoicing\Flux10\Enums\IcdSchemeId;
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
     * Invoice currency code.
     * @var string|null
     */
    protected $currencyCode = null;

    /**
     * Invoice due date.
     * @var DateTime|string|null
     */
    protected DateTime|string|null $dueDate = null;

    /**
     * VAT due date type code.
     * @var string|null
     */
    protected $taxDueDateTypeCode = null;

    /**
     * Invoicing framework (TT-28, G1.02).
     * @var BusinessProcessCode|null
     */
    protected ?BusinessProcessCode $businessProcessId = null;

    /**
     * Business process type ID (XSD Invoice/BusinessProcess/TypeID).
     * @var string|null
     */
    protected $businessProcessTypeId = null;

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
     * Seller identifier scheme (TT-33-1/TT-37, G2.19).
     * @var IcdSchemeId|null
     */
    protected ?IcdSchemeId $sellerSchemeId = null;

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
     * Buyer identifier scheme (TT-33-1/TT-37, G2.19).
     * @var IcdSchemeId|null
     */
    protected ?IcdSchemeId $buyerSchemeId = null;

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
     * VAT amount, in the invoice currency.
     * @var float|string|null
     */
    protected float|string|null $taxAmount = null;

    /**
     * Total VAT amount converted to euros (TT-52).
     *
     * The PPF requires this total in euros whatever the invoice currency (G6.23), so a
     * non-EUR invoice must carry the converted value here. Conversion is a business
     * decision and is never performed by the library.
     *
     * @var float|string|null
     */
    protected float|string|null $vatAmountEur = null;

    /**
     * VAT breakdown lines.
     * @var TaxBreakdown[]
     */
    protected $taxBreakdown = [];

    /**
     * Invoice notes (TG-9).
     * @var Note[]
     */
    protected array $notes = [];

    /**
     * References to earlier invoices (TG-11).
     *
     * Mandatory for a corrective invoice or a credit note (G1.32).
     *
     * @var ReferencedDocument[]
     */
    protected array $referencedDocuments = [];

    /**
     * VAT identifier of the seller's tax representative (TT-122).
     * @var string|null
     */
    protected ?string $sellerTaxRepresentativeVatId = null;

    /**
     * Identifier scheme of the tax representative VAT identifier (TT-40).
     * @var string|null
     */
    protected ?string $sellerTaxRepresentativeSchemeId = null;

    /**
     * Delivery information (TG-17).
     * @var Delivery[]
     */
    protected array $deliveries = [];

    /**
     * Invoicing period (TG-18).
     * @var Period|null
     */
    protected ?Period $invoicePeriod = null;

    /**
     * Document-level allowances and charges (TG-20, TG-21).
     * @var AllowanceCharge[]
     */
    protected array $allowancesCharges = [];

    /**
     * Invoice lines (TG-24).
     * @var Line[]
     */
    protected array $lines = [];

    /**
     * Get invoice identifier.
     */
    public function getInvoiceId(): ?string
    {
        return $this->invoiceId;
    }

    /**
     * Set invoice identifier.
     */
    public function setInvoiceId(?string $invoiceId): self
    {
        $this->invoiceId = $invoiceId;
        return $this;
    }

    /**
     * Get invoice issue date.
     */
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

    /**
     * Get invoice currency code.
     */
    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    /**
     * Set invoice currency code.
     */
    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    /**
     * Get invoice due date.
     */
    public function getDueDate(): DateTime|string|null
    {
        return $this->dueDate;
    }

    /**
     * @param DateTime|string|null $dueDate
     */
    public function setDueDate(DateTime|string|null $dueDate): self
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    /**
     * Get VAT due date type code.
     */
    public function getTaxDueDateTypeCode(): ?string
    {
        return $this->taxDueDateTypeCode;
    }

    /**
     * Set VAT due date type code.
     */
    public function setTaxDueDateTypeCode(?string $taxDueDateTypeCode): self
    {
        $this->taxDueDateTypeCode = $taxDueDateTypeCode;
        return $this;
    }

    /**
     * Get invoicing framework (TT-28).
     */
    public function getBusinessProcessId(): ?BusinessProcessCode
    {
        return $this->businessProcessId;
    }

    /**
     * Set invoicing framework (TT-28, G1.02).
     *
     * @param BusinessProcessCode|string|null $businessProcessId `B1`, `S1`, `M1`, … when a string
     */
    public function setBusinessProcessId(BusinessProcessCode|string|null $businessProcessId): self
    {
        $this->businessProcessId = is_string($businessProcessId)
            ? BusinessProcessCode::from($businessProcessId)
            : $businessProcessId;
        return $this;
    }

    /**
     * Get business process type ID.
     */
    public function getBusinessProcessTypeId(): ?string
    {
        return $this->businessProcessTypeId;
    }

    /**
     * Set business process type ID.
     */
    public function setBusinessProcessTypeId(?string $businessProcessTypeId): self
    {
        $this->businessProcessTypeId = $businessProcessTypeId;
        return $this;
    }

    /**
     * Get invoice type code.
     */
    public function getTypeCode(): ?string
    {
        return $this->typeCode;
    }

    /**
     * Set invoice type code.
     */
    public function setTypeCode(?string $typeCode): self
    {
        $this->typeCode = $typeCode;
        return $this;
    }

    /**
     * Get seller identifier.
     */
    public function getSellerId(): ?string
    {
        return $this->sellerId;
    }

    /**
     * Set seller identifier.
     */
    public function setSellerId(?string $sellerId): self
    {
        $this->sellerId = $sellerId;
        return $this;
    }

    /**
     * Get seller identifier scheme (G2.19).
     */
    public function getSellerSchemeId(): ?IcdSchemeId
    {
        return $this->sellerSchemeId;
    }

    /**
     * Set seller identifier scheme (G2.19).
     *
     * @param IcdSchemeId|string|null $sellerSchemeId An ISO 6523 code when a string
     */
    public function setSellerSchemeId(IcdSchemeId|string|null $sellerSchemeId): self
    {
        $this->sellerSchemeId = is_string($sellerSchemeId)
            ? IcdSchemeId::from($sellerSchemeId)
            : $sellerSchemeId;
        return $this;
    }

    /**
     * Get seller country code.
     */
    public function getSellerCountry(): ?string
    {
        return $this->sellerCountry;
    }

    /**
     * Set seller country code.
     */
    public function setSellerCountry(?string $sellerCountry): self
    {
        $this->sellerCountry = $sellerCountry;
        return $this;
    }

    /**
     * Get seller VAT ID.
     */
    public function getSellerVatId(): ?string
    {
        return $this->sellerVatId;
    }

    /**
     * Set seller VAT ID.
     */
    public function setSellerVatId(?string $sellerVatId): self
    {
        $this->sellerVatId = $sellerVatId;
        return $this;
    }

    /**
     * Get buyer identifier.
     */
    public function getBuyerId(): ?string
    {
        return $this->buyerId;
    }

    /**
     * Set buyer identifier.
     */
    public function setBuyerId(?string $buyerId): self
    {
        $this->buyerId = $buyerId;
        return $this;
    }

    /**
     * Get buyer identifier scheme (G2.19).
     */
    public function getBuyerSchemeId(): ?IcdSchemeId
    {
        return $this->buyerSchemeId;
    }

    /**
     * Set buyer identifier scheme (G2.19).
     *
     * @param IcdSchemeId|string|null $buyerSchemeId An ISO 6523 code when a string
     */
    public function setBuyerSchemeId(IcdSchemeId|string|null $buyerSchemeId): self
    {
        $this->buyerSchemeId = is_string($buyerSchemeId)
            ? IcdSchemeId::from($buyerSchemeId)
            : $buyerSchemeId;
        return $this;
    }

    /**
     * Get buyer country code.
     */
    public function getBuyerCountry(): ?string
    {
        return $this->buyerCountry;
    }

    /**
     * Set buyer country code.
     */
    public function setBuyerCountry(?string $buyerCountry): self
    {
        $this->buyerCountry = $buyerCountry;
        return $this;
    }

    /**
     * Get buyer VAT ID.
     */
    public function getBuyerVatId(): ?string
    {
        return $this->buyerVatId;
    }

    /**
     * Set buyer VAT ID.
     */
    public function setBuyerVatId(?string $buyerVatId): self
    {
        $this->buyerVatId = $buyerVatId;
        return $this;
    }

    /**
     * Get amount without VAT.
     */
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

    /**
     * Get VAT amount.
     */
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
     * Get total VAT amount in euros (TT-52).
     */
    public function getVatAmountEur(): float|string|null
    {
        return $this->vatAmountEur;
    }

    /**
     * Set total VAT amount in euros (TT-52, G6.23).
     *
     * Required when the invoice currency is not EUR.
     *
     * @param float|string|null $vatAmountEur
     */
    public function setVatAmountEur(float|string|null $vatAmountEur): self
    {
        $this->vatAmountEur = $vatAmountEur;
        return $this;
    }

    /**
     * @return TaxBreakdown[]
     */
    public function getTaxBreakdown(): array
    {
        return $this->taxBreakdown;
    }

    /**
     * Add VAT breakdown item.
     */
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

    /**
     * Clear VAT breakdown items.
     */
    public function clearTaxBreakdown(): self
    {
        $this->taxBreakdown = [];
        return $this;
    }

    /**
     * @return Note[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * Add an invoice note (TG-9).
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
     * Clear all invoice notes.
     */
    public function clearNotes(): self
    {
        $this->notes = [];
        return $this;
    }

    /**
     * @return ReferencedDocument[]
     */
    public function getReferencedDocuments(): array
    {
        return $this->referencedDocuments;
    }

    /**
     * Add a reference to an earlier invoice (TG-11, G1.32).
     */
    public function addReferencedDocument(ReferencedDocument $referencedDocument): self
    {
        $this->referencedDocuments[] = $referencedDocument;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeReferencedDocument(int $index): self
    {
        if ($index < 0 || $index >= count($this->referencedDocuments)) {
            throw new OutOfBoundsException('Could not find referenced document by index');
        }
        array_splice($this->referencedDocuments, $index, 1);
        return $this;
    }

    /**
     * Clear all references to earlier invoices.
     */
    public function clearReferencedDocuments(): self
    {
        $this->referencedDocuments = [];
        return $this;
    }

    /**
     * Get the VAT identifier of the seller's tax representative (TT-122).
     */
    public function getSellerTaxRepresentativeVatId(): ?string
    {
        return $this->sellerTaxRepresentativeVatId;
    }

    /**
     * Set the VAT identifier of the seller's tax representative (TT-122).
     *
     * Satisfies G1.102 in place of the seller VAT identifier when the breakdown is exempt.
     */
    public function setSellerTaxRepresentativeVatId(?string $vatId): self
    {
        $this->sellerTaxRepresentativeVatId = $vatId;
        return $this;
    }

    /**
     * Get the scheme of the tax representative VAT identifier (TT-40).
     */
    public function getSellerTaxRepresentativeSchemeId(): ?string
    {
        return $this->sellerTaxRepresentativeSchemeId;
    }

    /**
     * Set the scheme of the tax representative VAT identifier (TT-40).
     */
    public function setSellerTaxRepresentativeSchemeId(?string $schemeId): self
    {
        $this->sellerTaxRepresentativeSchemeId = $schemeId;
        return $this;
    }

    /**
     * @return Delivery[]
     */
    public function getDeliveries(): array
    {
        return $this->deliveries;
    }

    /**
     * Add delivery information (TG-17).
     */
    public function addDelivery(Delivery $delivery): self
    {
        $this->deliveries[] = $delivery;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeDelivery(int $index): self
    {
        if ($index < 0 || $index >= count($this->deliveries)) {
            throw new OutOfBoundsException('Could not find delivery by index');
        }
        array_splice($this->deliveries, $index, 1);
        return $this;
    }

    /**
     * Clear all delivery blocks.
     */
    public function clearDeliveries(): self
    {
        $this->deliveries = [];
        return $this;
    }

    /**
     * Get the invoicing period (TG-18).
     */
    public function getInvoicePeriod(): ?Period
    {
        return $this->invoicePeriod;
    }

    /**
     * Set the invoicing period (TG-18, G6.20).
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
     * Add a document-level allowance or charge (TG-20, TG-21).
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
     * Clear all document-level allowances and charges.
     */
    public function clearAllowancesCharges(): self
    {
        $this->allowancesCharges = [];
        return $this;
    }

    /**
     * @return Line[]
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * Add an invoice line (TG-24).
     */
    public function addLine(Line $line): self
    {
        $this->lines[] = $line;
        return $this;
    }

    /**
     * @throws OutOfBoundsException if index is out of bounds
     */
    public function removeLine(int $index): self
    {
        if ($index < 0 || $index >= count($this->lines)) {
            throw new OutOfBoundsException('Could not find line by index');
        }
        array_splice($this->lines, $index, 1);
        return $this;
    }

    /**
     * Clear all invoice lines.
     */
    public function clearLines(): self
    {
        $this->lines = [];
        return $this;
    }
}
