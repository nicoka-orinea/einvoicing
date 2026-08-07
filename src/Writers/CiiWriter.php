<?php

namespace Einvoicing\Writers;

use Einvoicing\AllowanceOrCharge;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Models\InvoiceTotals;
use Einvoicing\Party;
use InvalidArgumentException;
use UXML\UXML;

/**
 * Writes an invoice as a UN/CEFACT Cross Industry Invoice (D22B) document.
 *
 * Every monetary value comes from InvoiceTotals::fromInvoice(); this writer
 * performs no calculation of its own, so that CII and UBL exports of the same
 * invoice always agree. Element order follows the Factur-X EN 16931 sequences
 * (see tests/fixtures/xsd/FACTUR-X_EN16931).
 */
class CiiWriter extends AbstractWriter
{
    const NS_INVOICE = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    /**
     * Format a monetary amount: always 2 decimals (EN 16931 / French rule G1.14)
     */
    private function formatAmount(float $amount): string
    {
        return number_format(round($amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    /**
     * Format a quantity, a unit price or a percentage: up to $maxDecimals,
     * trailing zeros trimmed
     */
    private function formatDecimal(float $value, int $maxDecimals = 4): string
    {
        $s = number_format($value, $maxDecimals, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    /**
     * Export an invoice to a CII XML document.
     * @throws InvalidArgumentException if a line has a non-positive base quantity
     */
    public function export(Invoice $invoice): string
    {
        $totals = $invoice->getTotals();

        $xml = $this->createRoot();
        $this->addContext($xml, $invoice);
        $this->addExchangedDocument($xml, $invoice);

        $transaction = $xml->add("rsm:SupplyChainTradeTransaction");

        $this->addLineItems($transaction, $invoice);
        $this->addHeaderAgreement($transaction, $invoice);
        $this->addHeaderDelivery($transaction, $invoice);
        $this->addHeaderSettlement($transaction, $invoice, $totals);

        return $xml->asXML();
    }

    /* ================= ROOT & CONTEXT ================= */

    private function createRoot(): UXML
    {
        return UXML::newInstance("rsm:CrossIndustryInvoice", null, [
            'xmlns:rsm' => self::NS_INVOICE,
            'xmlns:ram' => self::NS_RAM,
            'xmlns:qdt' => self::NS_QDT,
            'xmlns:udt' => self::NS_UDT
        ]);
    }

    /**
     * BT-23 (business process) is 1..1 for the French PPF; enforcing that
     * obligation belongs to the applicable preset, not to this writer. Empty
     * elements are never written.
     */
    private function addContext(UXML $xml, Invoice $invoice): void
    {
        $context = $xml->add("rsm:ExchangedDocumentContext");
        if ($invoice->getBusinessProcess() !== null) {
            $context->add("ram:BusinessProcessSpecifiedDocumentContextParameter")
                ->add("ram:ID", $invoice->getBusinessProcess());
        }
        $context->add("ram:GuidelineSpecifiedDocumentContextParameter")
            ->add("ram:ID", $invoice->getSpecification() ?? "urn:cen.eu:en16931:2017");
    }

    private function addExchangedDocument(UXML $xml, Invoice $invoice): void
    {
        $doc = $xml->add("rsm:ExchangedDocument");
        $doc->add("ram:ID", $invoice->getNumber());
        $doc->add("ram:TypeCode", (string) $invoice->getType());

        $doc->add("ram:IssueDateTime")
            ->add("udt:DateTimeString", $invoice->getIssueDate()?->format("Ymd"), [
                "format" => "102"
            ]);

        foreach ($invoice->getDocumentNotes() as $note) {
            $includedNote = $doc->add("ram:IncludedNote");
            $includedNote->add("ram:Content", $note->getContent());
            if ($note->getSubjectCode() !== null) {
                $includedNote->add("ram:SubjectCode", $note->getSubjectCode());
            }
        }
    }

    /* ================= LINE ITEMS ================= */

    /**
     * @throws InvalidArgumentException if a line has a non-positive base quantity
     */
    private function addLineItems(UXML $parent, Invoice $invoice): void
    {
        $lines = $invoice->getLines();
        foreach ($lines as $index => $line) {
            $lineItem = $parent->add("ram:IncludedSupplyChainTradeLineItem");

            $lineDocument = $lineItem->add("ram:AssociatedDocumentLineDocument");
            $lineDocument->add("ram:LineID", $line->getId() ?? (string) ($index + 1));
            if ($line->getNote() !== null) {
                $lineDocument->add("ram:IncludedNote")
                    ->add("ram:Content", $line->getNote());
            }

            $this->addLineProduct($lineItem, $line);
            $this->addLineAgreement($lineItem, $line);

            $lineItem->add("ram:SpecifiedLineTradeDelivery")
                ->add("ram:BilledQuantity", $this->formatDecimal((float) $line->getQuantity()), [
                    "unitCode" => $line->getUnit()
                ]);

            $this->addLineSettlement($lineItem, $line, $invoice);
        }
    }

    private function addLineProduct(UXML $lineItem, InvoiceLine $line): void
    {
        $product = $lineItem->add("ram:SpecifiedTradeProduct");
        $standardIdentifier = $line->getStandardIdentifier();
        if ($standardIdentifier !== null) {
            $scheme = $standardIdentifier->getScheme();
            $product->add("ram:GlobalID", $standardIdentifier->getValue(), ($scheme !== null) ? [
                "schemeID" => $scheme
            ] : []);
        }
        if ($this->hasValue($line->getSellerIdentifier())) {
            $product->add("ram:SellerAssignedID", $line->getSellerIdentifier());
        }
        if ($this->hasValue($line->getBuyerIdentifier())) {
            $product->add("ram:BuyerAssignedID", $line->getBuyerIdentifier());
        }
        $product->add("ram:Name", $line->getName());
        if ($this->hasValue($line->getDescription())) {
            $product->add("ram:Description", $line->getDescription());
        }
        if ($line->getOriginCountry() !== null) {
            $product->add("ram:OriginTradeCountry")
                ->add("ram:ID", $line->getOriginCountry());
        }
    }

    /**
     * Children of LineTradeAgreementType, in schema order:
     * BuyerOrderReferencedDocument, GrossPriceProductTradePrice, NetPriceProductTradePrice
     * @throws InvalidArgumentException if the base quantity is not positive
     */
    private function addLineAgreement(UXML $lineItem, InvoiceLine $line): void
    {
        $agreement = $lineItem->add("ram:SpecifiedLineTradeAgreement");

        if ($line->getOrderLineReference() !== null) {
            $agreement->add("ram:BuyerOrderReferencedDocument")
                ->add("ram:LineID", $line->getOrderLineReference());
        }

        $baseQty = (float) $line->getBaseQuantity();
        if ($baseQty <= 0) {
            throw new InvalidArgumentException("Line base quantity must be positive");
        }
        // BT-146: the net price already applies to BT-149 units, so no division
        $netUnitPrice = (float) $line->getPrice();

        // BT-148/BT-147: gross price and item price discount, only when known
        $grossPrice = $line->getGrossPrice();
        if ($grossPrice !== null) {
            $grossPriceNode = $agreement->add("ram:GrossPriceProductTradePrice");
            $grossPriceNode->add("ram:ChargeAmount", $this->formatDecimal($grossPrice, 4));
            if (abs($grossPrice - $netUnitPrice) > 0.000005) {
                $applied = $grossPriceNode->add("ram:AppliedTradeAllowanceCharge");
                $applied->add("ram:ChargeIndicator")->add("udt:Indicator", 'false');
                $applied->add("ram:ActualAmount", $this->formatAmount($grossPrice - $netUnitPrice));
            }
        }

        $netPriceNode = $agreement->add("ram:NetPriceProductTradePrice");
        $netPriceNode->add("ram:ChargeAmount", $this->formatDecimal($netUnitPrice, 4));
        if ($baseQty !== 1.0) {
            $netPriceNode->add("ram:BasisQuantity", $this->formatDecimal($baseQty), [
                "unitCode" => $line->getUnit()
            ]);
        }
    }

    /**
     * Children of LineTradeSettlementType, in schema order: ApplicableTradeTax,
     * BillingSpecifiedPeriod, SpecifiedTradeAllowanceCharge,
     * SpecifiedTradeSettlementLineMonetarySummation,
     * ReceivableSpecifiedTradeAccountingAccount
     */
    private function addLineSettlement(UXML $lineItem, InvoiceLine $line, Invoice $invoice): void
    {
        $settlement = $lineItem->add("ram:SpecifiedLineTradeSettlement");

        $this->addLineTradeTax($settlement, $line);

        if ($line->getPeriodStartDate() !== null || $line->getPeriodEndDate() !== null) {
            $this->addBillingPeriod($settlement, $line->getPeriodStartDate(), $line->getPeriodEndDate());
        }

        $baseAmountForPercent = (float) $line->getNetAmountBeforeAllowancesCharges();
        foreach ($line->getCharges() as $charge) {
            $this->addAllowanceOrCharge(
                $settlement,
                $charge,
                true,
                $baseAmountForPercent,
                $invoice,
                $line->getVatCategory(),
                $line->getVatRate()
            );
        }
        foreach ($line->getAllowances() as $allowance) {
            $this->addAllowanceOrCharge(
                $settlement,
                $allowance,
                false,
                $baseAmountForPercent,
                $invoice,
                $line->getVatCategory(),
                $line->getVatRate()
            );
        }

        // BT-131: line net amount, rounded with the invoice rounding matrix
        $settlement->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
            ->add("ram:LineTotalAmount", $this->formatAmount(
                $invoice->round((float) $line->getNetAmount(), 'line/netAmount')
            ));

        if ($line->getBuyerAccountingReference() !== null) {
            $settlement->add("ram:ReceivableSpecifiedTradeAccountingAccount")
                ->add("ram:ID", $line->getBuyerAccountingReference());
        }
    }

    /**
     * Children of TradeAllowanceChargeType, in schema order: ChargeIndicator,
     * CalculationPercent, BasisAmount, ActualAmount, ReasonCode, Reason,
     * CategoryTradeTax. Used for both line and document level items.
     */
    private function addAllowanceOrCharge(
        UXML              $parent,
        AllowanceOrCharge $item,
        bool              $isCharge,
        float             $baseAmount,
        Invoice           $invoice,
        ?string           $fallbackVatCategory = null,
        ?float            $fallbackVatRate = null
    ): void
    {
        $ac = $parent->add("ram:SpecifiedTradeAllowanceCharge");

        $ac->add("ram:ChargeIndicator")
            ->add("udt:Indicator", $isCharge ? 'true' : 'false');

        if ($item->isPercentage()) {
            $ac->add("ram:CalculationPercent", $this->formatDecimal((float) $item->getAmount(), 2));
            $ac->add("ram:BasisAmount", $this->formatAmount($baseAmount));
        }

        $ac->add("ram:ActualAmount", $this->formatAmount(
            $invoice->round($item->getEffectiveAmount($baseAmount), 'line/allowanceChargeAmount')
        ));

        if ($item->getReasonCode() !== null) {
            $ac->add("ram:ReasonCode", $item->getReasonCode());
        }
        if ($item->getReason() !== null) {
            $ac->add("ram:Reason", $item->getReason());
        }

        $vatCategory = $item->getVatCategory() ?: $fallbackVatCategory;
        $vatRate = $item->getVatRate() ?? $fallbackVatRate;
        if ($vatCategory !== null || $vatRate !== null) {
            $tax = $ac->add("ram:CategoryTradeTax");
            $tax->add("ram:TypeCode", "VAT");
            if ($vatCategory !== null) {
                $tax->add("ram:CategoryCode", $vatCategory);
            }
            if ($vatRate !== null) {
                $tax->add("ram:RateApplicablePercent", $this->formatDecimal($vatRate, 2));
            }
        }
    }

    private function addLineTradeTax(UXML $parent, InvoiceLine $line): void
    {
        $tax = $parent->add("ram:ApplicableTradeTax");
        $tax->add("ram:TypeCode", "VAT");
        $tax->add("ram:CategoryCode", $line->getVatCategory());
        // Category O and other rateless categories must not carry an empty rate
        $vatRate = $line->getVatRate();
        if ($vatRate !== null) {
            $tax->add("ram:RateApplicablePercent", $this->formatDecimal($vatRate, 2));
        }
    }

    private function addBillingPeriod(UXML $parent, ?\DateTime $startDate, ?\DateTime $endDate): void
    {
        $period = $parent->add("ram:BillingSpecifiedPeriod");

        if ($startDate !== null) {
            $period->add("ram:StartDateTime")
                ->add("udt:DateTimeString", $startDate->format("Ymd"), [
                    "format" => "102"
                ]);
        }
        if ($endDate !== null) {
            $period->add("ram:EndDateTime")
                ->add("udt:DateTimeString", $endDate->format("Ymd"), [
                    "format" => "102"
                ]);
        }
    }

    /* ================= HEADER ================= */

    /**
     * Children of HeaderTradeAgreementType, in schema order: BuyerReference,
     * SellerTradeParty, BuyerTradeParty, SellerTaxRepresentativeTradeParty,
     * SellerOrderReferencedDocument, BuyerOrderReferencedDocument,
     * ContractReferencedDocument, AdditionalReferencedDocument
     */
    private function addHeaderAgreement(UXML $parent, Invoice $invoice): void
    {
        $agreement = $parent->add("ram:ApplicableHeaderTradeAgreement");

        if ($invoice->getBuyerReference() !== null) {
            $agreement->add("ram:BuyerReference", $invoice->getBuyerReference());
        }

        $this->addParty($agreement->add("ram:SellerTradeParty"), $invoice->getSeller());
        $this->addParty($agreement->add("ram:BuyerTradeParty"), $invoice->getBuyer());

        // BG-11: seller tax representative
        $taxRepresentative = $invoice->getTaxRepresentative();
        if ($taxRepresentative !== null) {
            $this->addParty($agreement->add("ram:SellerTaxRepresentativeTradeParty"), $taxRepresentative);
        }

        // BT-14 comes before BT-13 in the schema sequence
        if ($invoice->getSalesOrderReference() !== null) {
            $agreement->add("ram:SellerOrderReferencedDocument")
                ->add("ram:IssuerAssignedID", $invoice->getSalesOrderReference());
        }
        if ($invoice->getPurchaseOrderReference() !== null) {
            $agreement->add("ram:BuyerOrderReferencedDocument")
                ->add("ram:IssuerAssignedID", $invoice->getPurchaseOrderReference());
        }
        if ($invoice->getContractReference() !== null) {
            $agreement->add("ram:ContractReferencedDocument")
                ->add("ram:IssuerAssignedID", $invoice->getContractReference());
        }

        // BT-18: invoiced object identifier
        $invoicedObjectIdentifier = $invoice->getInvoicedObjectIdentifier();
        if ($invoicedObjectIdentifier !== null) {
            $reference = $agreement->add("ram:AdditionalReferencedDocument");
            $reference->add("ram:IssuerAssignedID", $invoicedObjectIdentifier->getValue());
            $reference->add("ram:TypeCode", "130");
            $scheme = $invoicedObjectIdentifier->getScheme();
            if ($scheme !== null) {
                $reference->add("ram:ReferenceTypeCode", $scheme);
            }
        }
    }

    /**
     * The element itself is mandatory (1..1) in the CII schema, but its content
     * is not: a delivery date is never fabricated from the issue date.
     */
    private function addHeaderDelivery(UXML $parent, Invoice $invoice): void
    {
        $delivery = $parent->add("ram:ApplicableHeaderTradeDelivery");

        $date = $invoice->getDelivery()?->getDate();
        if ($date !== null) {
            $delivery->add("ram:ActualDeliverySupplyChainEvent")
                ->add("ram:OccurrenceDateTime")
                ->add("udt:DateTimeString", $date->format("Ymd"), [
                    "format" => "102"
                ]);
        }

        // BT-16: despatch advice reference
        if ($invoice->getDespatchAdviceReference() !== null) {
            $delivery->add("ram:DespatchAdviceReferencedDocument")
                ->add("ram:IssuerAssignedID", $invoice->getDespatchAdviceReference());
        }
    }

    /**
     * Children of HeaderTradeSettlementType, in schema order: CreditorReferenceID,
     * PaymentReference, TaxCurrencyCode, InvoiceCurrencyCode, PayeeTradeParty,
     * SpecifiedTradeSettlementPaymentMeans, ApplicableTradeTax,
     * BillingSpecifiedPeriod, SpecifiedTradeAllowanceCharge,
     * SpecifiedTradePaymentTerms, SpecifiedTradeSettlementHeaderMonetarySummation,
     * InvoiceReferencedDocument, ReceivableSpecifiedTradeAccountingAccount
     */
    private function addHeaderSettlement(UXML $parent, Invoice $invoice, InvoiceTotals $totals): void
    {
        $settlement = $parent->add("ram:ApplicableHeaderTradeSettlement");

        // BT-90: bank assigned creditor identifier
        foreach ($invoice->getPayments() as $payment) {
            $creditorIdentifier = $payment->getMandate()?->getCreditorIdentifier();
            if ($creditorIdentifier !== null) {
                $settlement->add("ram:CreditorReferenceID", $creditorIdentifier);
                break;
            }
        }

        // BT-83: remittance information
        foreach ($invoice->getPayments() as $payment) {
            if ($payment->getId() !== null) {
                $settlement->add("ram:PaymentReference", $payment->getId());
                break;
            }
        }

        // BT-6 comes before BT-5 in the schema sequence
        if ($invoice->getVatCurrency() !== null) {
            $settlement->add("ram:TaxCurrencyCode", $invoice->getVatCurrency());
        }
        $settlement->add("ram:InvoiceCurrencyCode", $invoice->getCurrency());

        // BG-10: payee
        $payee = $invoice->getPayee();
        if ($payee !== null) {
            $this->addParty($settlement->add("ram:PayeeTradeParty"), $payee);
        }

        $this->addPaymentMeans($settlement, $invoice);
        $this->addHeaderTradeTaxes($settlement, $invoice, $totals);

        // BG-14: invoicing period
        if ($invoice->getPeriodStartDate() !== null || $invoice->getPeriodEndDate() !== null) {
            $this->addBillingPeriod($settlement, $invoice->getPeriodStartDate(), $invoice->getPeriodEndDate());
        }

        // BG-20 and BG-21: one element per model item, never split by VAT rate
        foreach ($invoice->getAllowances() as $allowance) {
            $this->addAllowanceOrCharge($settlement, $allowance, false, $totals->netAmount, $invoice);
        }
        foreach ($invoice->getCharges() as $charge) {
            $this->addAllowanceOrCharge($settlement, $charge, true, $totals->netAmount, $invoice);
        }

        $this->addPaymentTerms($settlement, $invoice);
        $this->addMonetarySummation($settlement, $totals);

        // BT-25 and BT-26: preceding invoice references
        foreach ($invoice->getPrecedingInvoiceReferences() as $ref) {
            $doc = $settlement->add("ram:InvoiceReferencedDocument");
            $doc->add("ram:IssuerAssignedID", $ref->getValue());
            $referenceIssueDate = $ref->getIssueDate();
            if ($referenceIssueDate !== null) {
                $doc->add("ram:FormattedIssueDateTime")
                    ->add("qdt:DateTimeString", $referenceIssueDate->format("Ymd"), [
                        "format" => "102"
                    ]);
            }
        }

        // BT-19: buyer accounting reference, last child of the sequence
        if ($invoice->getBuyerAccountingReference() !== null) {
            $settlement->add("ram:ReceivableSpecifiedTradeAccountingAccount")
                ->add("ram:ID", $invoice->getBuyerAccountingReference());
        }
    }

    /**
     * BG-23: VAT breakdown, written from the invoice totals. Children of
     * TradeTaxType, in schema order: CalculatedAmount, TypeCode,
     * ExemptionReason, BasisAmount, CategoryCode, ExemptionReasonCode,
     * TaxPointDate, DueDateTypeCode, RateApplicablePercent.
     *
     * BT-7 and BT-8 are mutually exclusive (BR-CO-3); this writer emits what it
     * is given and leaves the check to validation.
     */
    private function addHeaderTradeTaxes(UXML $settlement, Invoice $invoice, InvoiceTotals $totals): void
    {
        $taxPointDate = $invoice->getTaxPointDate();
        $vatPointDateCode = $invoice->getVatPointDateCode();

        foreach ($totals->vatBreakdown as $breakdown) {
            $tax = $settlement->add("ram:ApplicableTradeTax");
            $tax->add("ram:CalculatedAmount", $this->formatAmount((float) $breakdown->taxAmount));
            $tax->add("ram:TypeCode", "VAT");
            // BT-120
            if ($breakdown->exemptionReason !== null) {
                $tax->add("ram:ExemptionReason", $breakdown->exemptionReason);
            }
            $tax->add("ram:BasisAmount", $this->formatAmount((float) $breakdown->taxableAmount));
            $tax->add("ram:CategoryCode", $breakdown->category);
            // BT-121
            if ($breakdown->exemptionReasonCode !== null) {
                $tax->add("ram:ExemptionReasonCode", $breakdown->exemptionReasonCode);
            }
            // BT-7
            if ($taxPointDate !== null) {
                $tax->add("ram:TaxPointDate")
                    ->add("udt:DateString", $taxPointDate->format("Ymd"), [
                        "format" => "102"
                    ]);
            }
            // BT-8
            if ($vatPointDateCode !== null) {
                $tax->add("ram:DueDateTypeCode", $vatPointDateCode);
            }
            if ($breakdown->rate !== null) {
                $tax->add("ram:RateApplicablePercent", $this->formatDecimal($breakdown->rate, 2));
            }
        }
    }

    private function addPaymentTerms(UXML $parent, Invoice $invoice): void
    {
        $paymentTerms = $invoice->getPaymentTerms();
        $dueDate = $invoice->getDueDate();

        if ($paymentTerms === null && $dueDate === null) {
            return;
        }

        $terms = $parent->add("ram:SpecifiedTradePaymentTerms");
        if ($paymentTerms !== null) {
            $terms->add("ram:Description", $paymentTerms);
        }
        if ($dueDate !== null) {
            $terms->add("ram:DueDateDateTime")
                ->add("udt:DateTimeString", $dueDate->format("Ymd"), [
                    "format" => "102"
                ]);
        }
    }

    /**
     * BG-16: one ram:SpecifiedTradeSettlementPaymentMeans per credit transfer,
     * and a single one when a payment carries no transfer. Children of
     * TradeSettlementPaymentMeansType, in schema order: TypeCode, Information,
     * ApplicableTradeSettlementFinancialCard, PayerPartyDebtorFinancialAccount,
     * PayeePartyCreditorFinancialAccount, PayeeSpecifiedCreditorFinancialInstitution
     */
    private function addPaymentMeans(UXML $parent, Invoice $invoice): void
    {
        foreach ($invoice->getPayments() as $payment) {
            $meansCode = $payment->getMeansCode();
            $meansText = $payment->getMeansText();
            $card = $payment->getCard();
            $debtorAccount = $payment->getMandate()?->getAccount();
            $transfers = array_values(array_filter(
                $payment->getTransfers(),
                fn ($transfer) => $this->hasValue($transfer->getAccountId()),
            ));

            // A bank transfer without a beneficiary account is invalid under EN 16931.
            if ($meansCode === '58' && empty($transfers)) {
                continue;
            }

            if ($meansCode === null && $meansText === null && empty($transfers)) {
                continue;
            }

            // One element per transfer, or a single one when there is none
            $count = max(1, count($transfers));
            for ($i = 0; $i < $count; $i++) {
                $transfer = $transfers[$i] ?? null;

                $xml = $parent->add("ram:SpecifiedTradeSettlementPaymentMeans");
                if ($meansCode !== null) {
                    $xml->add("ram:TypeCode", $meansCode);
                }
                if ($meansText !== null) {
                    $xml->add("ram:Information", $meansText);
                }

                // BG-18: payment card information
                if ($card !== null) {
                    $cardXml = $xml->add("ram:ApplicableTradeSettlementFinancialCard");
                    if ($this->hasValue($card->getPan())) {
                        $cardXml->add("ram:ID", $card->getPan());
                    }
                    if ($this->hasValue($card->getHolder())) {
                        $cardXml->add("ram:CardholderName", $card->getHolder());
                    }
                }

                // BG-19: direct debit
                if ($this->hasValue($debtorAccount)) {
                    $xml->add("ram:PayerPartyDebtorFinancialAccount")
                        ->add("ram:IBANID", $debtorAccount);
                }

                if ($transfer === null) {
                    continue;
                }

                // BG-17: credit transfer
                $accountId = $transfer->getAccountId();
                $accountName = $transfer->getAccountName();
                $provider = $transfer->getProvider();

                if ($this->hasValue($accountId) || $this->hasValue($accountName)) {
                    $account = $xml->add("ram:PayeePartyCreditorFinancialAccount");
                    if ($this->hasValue($accountId)) {
                        $account->add("ram:IBANID", $accountId);
                    }
                    if ($this->hasValue($accountName)) {
                        $account->add("ram:AccountName", $accountName);
                    }
                }

                if ($this->hasValue($provider)) {
                    $xml->add("ram:PayeeSpecifiedCreditorFinancialInstitution")
                        ->add("ram:BICID", $provider);
                }
            }
        }
    }

    /**
     * BG-22: document totals, taken as-is from the invoice totals. Children of
     * TradeSettlementHeaderMonetarySummationType, in schema order:
     * LineTotalAmount, ChargeTotalAmount, AllowanceTotalAmount,
     * TaxBasisTotalAmount, TaxTotalAmount, RoundingAmount, GrandTotalAmount,
     * TotalPrepaidAmount, DuePayableAmount
     */
    private function addMonetarySummation(UXML $parent, InvoiceTotals $totals): void
    {
        $sum = $parent->add("ram:SpecifiedTradeSettlementHeaderMonetarySummation");

        // BT-106
        $sum->add("ram:LineTotalAmount", $this->formatAmount((float) $totals->netAmount));
        // BT-108
        if ((float) $totals->chargesAmount != 0.0) {
            $sum->add("ram:ChargeTotalAmount", $this->formatAmount((float) $totals->chargesAmount));
        }
        // BT-107
        if ((float) $totals->allowancesAmount != 0.0) {
            $sum->add("ram:AllowanceTotalAmount", $this->formatAmount((float) $totals->allowancesAmount));
        }
        // BT-109
        $sum->add("ram:TaxBasisTotalAmount", $this->formatAmount((float) $totals->taxExclusiveAmount));
        // BT-110
        $sum->add("ram:TaxTotalAmount", $this->formatAmount((float) $totals->vatAmount), [
            "currencyID" => $totals->currency
        ]);
        // BT-111: VAT total in the accounting currency
        if ($totals->vatCurrency !== null && $totals->customVatAmount !== null) {
            $sum->add("ram:TaxTotalAmount", $this->formatAmount((float) $totals->customVatAmount), [
                "currencyID" => $totals->vatCurrency
            ]);
        }
        // BT-114
        if ((float) $totals->roundingAmount != 0.0) {
            $sum->add("ram:RoundingAmount", $this->formatAmount((float) $totals->roundingAmount));
        }
        // BT-112
        $sum->add("ram:GrandTotalAmount", $this->formatAmount((float) $totals->taxInclusiveAmount));
        // BT-113
        if ((float) $totals->paidAmount != 0.0) {
            $sum->add("ram:TotalPrepaidAmount", $this->formatAmount((float) $totals->paidAmount));
        }
        // BT-115
        $sum->add("ram:DuePayableAmount", $this->formatAmount((float) $totals->payableAmount));
    }

    /* ================= PARTIES ================= */

    /**
     * Children of TradePartyType, in schema order: ID, GlobalID, Name,
     * SpecifiedLegalOrganization, DefinedTradeContact, PostalTradeAddress,
     * URIUniversalCommunication, SpecifiedTaxRegistration
     */
    private function addParty(UXML $parent, ?Party $party): void
    {
        if ($party === null) {
            return;
        }

        // BT-29 and BT-46: party identifiers. An identifier with a scheme is a
        // GlobalID; the company identifier (BT-30/BT-47) is not duplicated here.
        $identifiers = $party->getIdentifiers();
        foreach ($identifiers as $identifier) {
            if ($identifier->getScheme() === null) {
                $parent->add("ram:ID", $identifier->getValue());
            }
        }
        foreach ($identifiers as $identifier) {
            $scheme = $identifier->getScheme();
            if ($scheme !== null) {
                $parent->add("ram:GlobalID", $identifier->getValue(), ["schemeID" => $scheme]);
            }
        }

        $name = $party->getName();
        if ($name !== null) {
            $parent->add("ram:Name", $name);
        }

        $this->addLegalOrganization($parent, $party);
        $this->addTradeContact($parent, $party);
        $this->addPostalAddress($parent, $party);
        $this->addElectronicAddress($parent, $party);
        $this->addVatRegistration($parent, $party);
    }

    private function addLegalOrganization(UXML $parent, Party $party): void
    {
        $identifier = $party->getCompanyId();
        if ($identifier === null) {
            foreach ($party->getIdentifiers() as $candidate) {
                if ($candidate->getScheme() === '0002') {
                    $identifier = $candidate;
                    break;
                }
            }
        }
        $tradingName = $party->getTradingName();
        if ($identifier === null && $tradingName === null) {
            return; // BT-30 is optional under EN 16931
        }

        $organization = $parent->add("ram:SpecifiedLegalOrganization");
        if ($identifier !== null) {
            $scheme = $identifier->getScheme();
            $attrs = ($scheme !== null) ? ["schemeID" => $scheme] : [];
            $organization->add("ram:ID", $identifier->getValue(), $attrs);
        }
        // BT-28: trading name
        if ($tradingName !== null) {
            $organization->add("ram:TradingBusinessName", $tradingName);
        }
    }

    /**
     * BG-6 and BG-9: contact point
     */
    private function addTradeContact(UXML $parent, Party $party): void
    {
        $contactName = $party->getContactName();
        $contactPhone = $party->getContactPhone();
        $contactEmail = $party->getContactEmail();

        if ($contactName === null && $contactPhone === null && $contactEmail === null) {
            return;
        }

        $contact = $parent->add("ram:DefinedTradeContact");
        if ($contactName !== null) {
            $contact->add("ram:PersonName", $contactName);
        }
        if ($contactPhone !== null) {
            $contact->add("ram:TelephoneUniversalCommunication")
                ->add("ram:CompleteNumber", $contactPhone);
        }
        if ($contactEmail !== null) {
            $contact->add("ram:EmailURIUniversalCommunication")
                ->add("ram:URIID", $contactEmail);
        }
    }

    private function addPostalAddress(UXML $parent, Party $party): void
    {
        $addr = $parent->add("ram:PostalTradeAddress");
        if ($this->hasValue($party->getPostalCode())) {
            $addr->add("ram:PostcodeCode", $party->getPostalCode());
        }
        $address = $party->getAddress();
        if ($this->hasValue($address[0] ?? null)) {
            $addr->add("ram:LineOne", $address[0]);
        }
        if ($this->hasValue($address[1] ?? null)) {
            $addr->add("ram:LineTwo", $address[1]);
        }
        if ($this->hasValue($address[2] ?? null)) {
            $addr->add("ram:LineThree", $address[2]);
        }
        if ($this->hasValue($party->getCity())) {
            $addr->add("ram:CityName", $party->getCity());
        }
        if ($this->hasValue($party->getCountry())) {
            $addr->add("ram:CountryID", $party->getCountry());
        }
    }

    private function addElectronicAddress(UXML $parent, Party $party): void
    {
        $ea = $party->getElectronicAddress();
        if ($ea === null) {
            return;
        }

        $scheme = $ea->getScheme();
        $parent->add("ram:URIUniversalCommunication")
            ->add("ram:URIID", $ea->getValue(), ($scheme !== null) ? [
                "schemeID" => $scheme
            ] : []);
    }

    private function addVatRegistration(UXML $parent, Party $party): void
    {
        $vatNumber = $party->getVatNumber();
        if (!$this->hasValue($vatNumber)) {
            return;
        }

        $parent->add("ram:SpecifiedTaxRegistration")
            ->add("ram:ID", $vatNumber, [
                "schemeID" => "VA"
            ]);
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
