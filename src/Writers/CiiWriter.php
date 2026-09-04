<?php

namespace Einvoicing\Writers;

use Einvoicing\AllowanceOrCharge;
use Einvoicing\Invoice;
use Einvoicing\Models\VatBreakdown;
use Einvoicing\Party;
use UXML\UXML;

class CiiWriter extends AbstractWriter
{
    const NS_INVOICE = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    /**
     * Breakdown recalculé après application des remises/majorations header,
     * utilisé pour écrire les taxes et les totaux TVA.
     */
    private ?array $computedVatBreakdownAfterHeaderAC = null;

    /**
     * BT-107 : somme des BT-92 (ActualAmount) réellement écrits au niveau document.
     * On cumule au moment où l'on écrit les remises header pour éviter les divergences d'arrondi.
     */
    private float $headerAllowanceTotal = 0.0;
    private float $headerChargeTotal = 0.0;

    private function formatCurrency(float $amount): string
    {
        return number_format(round($amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    /**
     * BT-146 / BT-148 : EN16931 does not cap the decimals of a unit price. Writing it with two
     * decimals breaks the PA line check (BT-146 × BT-129 vs BT-131) for prices such as 33.3333.
     * Up to four decimals are kept, trailing zeros trimmed down to two.
     */
    private function formatPrice(float $amount): string
    {
        $formatted = number_format(round($amount, 4, PHP_ROUND_HALF_UP), 4, '.', '');
        return preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $formatted);
    }

    /**
     * Export an invoice to a CII XML document.
     */
    public function export(Invoice $invoice): string
    {
        $this->computedVatBreakdownAfterHeaderAC = null;
        $this->headerAllowanceTotal = 0.0;
        $this->headerChargeTotal = 0.0;

        // Every amount is written with two decimals (BR-DEC-*): the totals must be computed
        // from two-decimal line nets, or Σ BT-131 drifts from BT-106 (BR-CO-10).
        $invoice->setRoundingMatrix($invoice->getRoundingMatrix() + ['' => 2]);

        $xml = $this->createRoot();
        $this->addContext($xml, $invoice);
        $this->addExchangedDocument($xml, $invoice);

        $transaction = $xml->add("rsm:SupplyChainTradeTransaction");

        $this->addLineItems($transaction, $invoice);
        $this->addHeaderAgreement($transaction, $invoice);
        $this->addHeaderDelivery($transaction, $invoice);
        $this->addHeaderSettlement($transaction, $invoice);

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
        $doc->add("ram:TypeCode", $invoice->getType());

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

    private function addLineItems(UXML $parent, Invoice $invoice): void
    {
        foreach ($invoice->getLines() as $line) {

            $lineItem = $parent->add("ram:IncludedSupplyChainTradeLineItem");

            $lineItem
                ->add("ram:AssociatedDocumentLineDocument")
                ->add("ram:LineID", $line->getId() ?? (string) ($invoice->getLines() ? array_search($line, $invoice->getLines(), true) + 1 : 1));

            if ($line->getNote() !== null) {
                $lineItem->get("ram:AssociatedDocumentLineDocument")
                    ?->add("ram:IncludedNote")
                    ->add("ram:Content", $line->getNote());
            }

            $product = $lineItem->add("ram:SpecifiedTradeProduct");
            if ($line->getStandardIdentifier() !== null) {
                $product->add("ram:GlobalID", $line->getStandardIdentifier()->getValue(), [
                    "schemeID" => $line->getStandardIdentifier()->getScheme()
                ]);
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

            // EN16931/CII : les prix sont HT
            $agreement = $lineItem->add("ram:SpecifiedLineTradeAgreement");

            // NOTE: the LineTradeAgreementType XSD sequence puts BuyerOrderReferencedDocument
            // (BT-132) before the price elements
            if ($line->getOrderLineReference() !== null) {
                $agreement->add("ram:BuyerOrderReferencedDocument")
                    ->add("ram:LineID", $line->getOrderLineReference());
            }

            $baseQty = max(1.0, (float)$line->getBaseQuantity());
            $netUnitPrice = (float)$line->getPrice() / $baseQty;

            $agreement->add("ram:GrossPriceProductTradePrice")
                ->add("ram:ChargeAmount", $this->formatPrice($netUnitPrice));

            $netPriceNode = $agreement->add("ram:NetPriceProductTradePrice");
            $netPriceNode->add("ram:ChargeAmount", $this->formatPrice($netUnitPrice));
            if ($baseQty !== 1.0) {
                $netPriceNode->add("ram:BasisQuantity", $this->formatCurrency($baseQty), [
                    "unitCode" => $line->getUnit()
                ]);
            }
            $lineItem->add("ram:SpecifiedLineTradeDelivery")
                ->add("ram:BilledQuantity", $line->getQuantity(), [
                    "unitCode" => $line->getUnit()
                ]);

            $settlement = $lineItem->add("ram:SpecifiedLineTradeSettlement");

            $this->addLineTradeTax($settlement, $line);

            // NOTE: the LineTradeSettlementType XSD sequence is
            // ApplicableTradeTax -> BillingSpecifiedPeriod -> SpecifiedTradeAllowanceCharge ->
            // SpecifiedTradeSettlementLineMonetarySummation -> ... -> ReceivableSpecifiedTradeAccountingAccount
            if ($line->getPeriodStartDate() && $line->getPeriodEndDate()) {
                $this->addBillingPeriod($settlement, $line);
            }

            $baseAmountForPercent = (float)$line->getNetAmountBeforeAllowancesCharges();

            foreach ($line->getCharges() as $charge) {
                $this->addLineAllowanceOrCharge(
                    $settlement,
                    $charge,
                    true,
                    $baseAmountForPercent,
                    $line->getVatCategory(),
                    $line->getVatRate()
                );
            }

            foreach ($line->getAllowances() as $allowance) {
                $this->addLineAllowanceOrCharge(
                    $settlement,
                    $allowance,
                    false,
                    $baseAmountForPercent,
                    $line->getVatCategory(),
                    $line->getVatRate()
                );
            }

            // ✅ BT-131 (Invoice line net amount) = net de ligne APRÈS remises/charges de ligne
            $settlement
                ->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
                ->add("ram:LineTotalAmount", $this->formatCurrency((float)$line->getNetAmount()));

            if ($line->getBuyerAccountingReference() !== null) {
                $settlement->add("ram:ReceivableSpecifiedTradeAccountingAccount")
                    ->add("ram:ID", $line->getBuyerAccountingReference());
            }
        }
    }

    private function addLineAllowanceOrCharge(
        UXML              $parent,
        AllowanceOrCharge $item,
        bool              $isCharge,
        float             $baseAmount,
        ?string           $fallbackVatCategory,
        ?float            $fallbackVatRate
    ): void
    {
        $ac = $parent->add("ram:SpecifiedTradeAllowanceCharge");

        $ac->add("ram:ChargeIndicator")
            ->add("udt:Indicator", $isCharge ? 'true' : 'false');

        if ($item->isPercentage()) {
            // On garde 2 décimales (compatible validateurs), même si c'est un %
            $ac->add("ram:CalculationPercent", $this->formatCurrency((float)$item->getAmount()));
            $ac->add("ram:BasisAmount", $this->formatCurrency($baseAmount));
            $actualAmount = (float)$item->getEffectiveAmount($baseAmount);
        } else {
            $actualAmount = (float)$item->getAmount();
        }

        $ac->add("ram:ActualAmount", $this->formatCurrency($actualAmount));

        if ($item->getReasonCode()) {
            $ac->add("ram:ReasonCode", $item->getReasonCode());
        }
        if ($item->getReason()) {
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
                $tax->add("ram:RateApplicablePercent", $vatRate);
            }
        }
    }

    private function addLineTradeTax(UXML $parent, $line): void
    {
        $tax = $parent->add("ram:ApplicableTradeTax");
        $tax->add("ram:TypeCode", "VAT");
        $tax->add("ram:CategoryCode", $line->getVatCategory());
        if ($line->getVatRate() !== null) {
            $tax->add("ram:RateApplicablePercent", $line->getVatRate());
        }
    }

    private function addBillingPeriod(UXML $parent, $line): void
    {
        $period = $parent->add("ram:BillingSpecifiedPeriod");

        $period->add("ram:StartDateTime")
            ->add("udt:DateTimeString", $line->getPeriodStartDate()->format("Ymd"), [
                "format" => "102"
            ]);

        $period->add("ram:EndDateTime")
            ->add("udt:DateTimeString", $line->getPeriodEndDate()->format("Ymd"), [
                "format" => "102"
            ]);
    }

    /* ================= HEADER ================= */

    private function addHeaderAgreement(UXML $parent, Invoice $invoice): void
    {
        $agreement = $parent->add("ram:ApplicableHeaderTradeAgreement");
        if ($invoice->getBuyerReference() !== null) {
            $agreement->add("ram:BuyerReference", $invoice->getBuyerReference());
        }
        $this->addParty($agreement->add("ram:SellerTradeParty"), $invoice->getSeller());
        $this->addParty($agreement->add("ram:BuyerTradeParty"), $invoice->getBuyer());
        // NOTE: the XSD sequence requires SellerOrderReferencedDocument (BT-14)
        // before BuyerOrderReferencedDocument (BT-13)
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
    }

    private function addHeaderDelivery(UXML $parent, Invoice $invoice): void
    {
        $deliveryDate = $invoice->getDelivery()?->getDate() ?? $invoice->getIssueDate();
        $parent->add("ram:ApplicableHeaderTradeDelivery")
            ->add("ram:ActualDeliverySupplyChainEvent")
            ->add("ram:OccurrenceDateTime")
            ->add("udt:DateTimeString", $deliveryDate?->format("Ymd"), [
                "format" => "102"
            ]);
    }

    private function addHeaderSettlement(UXML $parent, Invoice $invoice): void
    {
        // NOTE: the HeaderTradeSettlementType XSD sequence is strict:
        // PaymentReference -> TaxCurrencyCode -> InvoiceCurrencyCode -> ... ->
        // InvoiceReferencedDocument -> ReceivableSpecifiedTradeAccountingAccount (last)
        $settlement = $parent->add("ram:ApplicableHeaderTradeSettlement");
        $firstPayment = $invoice->getPayments()[0] ?? null;
        if ($firstPayment?->getId() !== null) {
            $settlement->add("ram:PaymentReference", $firstPayment->getId());
        }
        if ($invoice->getVatCurrency() !== null) {
            $settlement->add("ram:TaxCurrencyCode", $invoice->getVatCurrency());
        }
        $settlement->add("ram:InvoiceCurrencyCode", $invoice->getCurrency());
        $this->addPaymentMeans($settlement, $invoice);

        $totals = $invoice->getTotals();

        /**
         * 1) On recalcule un breakdown TVA APRÈS application des remises/majorations header,
         *    en utilisant la même logique de split que pour écrire les allowances/charges.
         */
        $this->computedVatBreakdownAfterHeaderAC =
            $this->computeVatBreakdownAfterHeaderAdjustments($invoice, $totals->vatBreakdown);

        /**
         * 2) On écrit les ApplicableTradeTax à partir de ce breakdown recalculé
         *    (donc bases taxables et TVA cohérentes après remises/charges header).
         */
        // XSD order: CalculatedAmount, TypeCode, ExemptionReason, BasisAmount, CategoryCode,
        // ExemptionReasonCode, RateApplicablePercent. Category O carries no rate (BR-O-05).
        foreach ($this->computedVatBreakdownAfterHeaderAC as $b) {
            $tax = $settlement->add("ram:ApplicableTradeTax");
            $tax->add("ram:CalculatedAmount", $this->formatCurrency((float)$b['tax']));
            $tax->add("ram:TypeCode", "VAT");
            if ($this->hasValue($b['exemptionReason'])) {
                $tax->add("ram:ExemptionReason", $b['exemptionReason']);
            }
            $tax->add("ram:BasisAmount", $this->formatCurrency((float)$b['taxable']));
            $tax->add("ram:CategoryCode", $b['category']);
            if ($this->hasValue($b['exemptionReasonCode'])) {
                $tax->add("ram:ExemptionReasonCode", $b['exemptionReasonCode']);
            }
            if ($b['rate'] !== null) {
                $tax->add("ram:RateApplicablePercent", $b['rate']);
            }
        }

        /**
         * 3) On écrit les remises/majorations header (split par breakdown AVANT adjustments),
         *    car la base des % est la base taxable "pré-remise header".
         */
        // Invoice-level billing period (BT-73 / BT-74) — must sit between
        // ApplicableTradeTax and SpecifiedTradeAllowanceCharge in the XSD sequence
        if ($invoice->getPeriodStartDate() !== null && $invoice->getPeriodEndDate() !== null) {
            $this->addBillingPeriod($settlement, $invoice);
        }

        foreach ($invoice->getCharges() as $charge) {
            $this->addHeaderAllowanceOrChargeSplitByVat($settlement, $charge, true, $totals->vatBreakdown);
        }
        foreach ($invoice->getAllowances() as $allowance) {
            $this->addHeaderAllowanceOrChargeSplitByVat($settlement, $allowance, false, $totals->vatBreakdown);
        }

        $this->addPaymentTerms($settlement, $invoice);

        /**
         * 4) Totaux monétaires : on utilise la somme exacte des BT-92 écrits (headerAllowanceTotal)
         *    + la TVA recalculée.
         */
        $this->addMonetarySummation($settlement, $invoice);

        /**
         * 5) Référence(s) à la (aux) facture(s) antérieure(s) — BT-25 / BT-26.
         *    Obligatoire pour un avoir (BR-FR-CO-05). Positionné en dernier :
         *    dans HeaderTradeSettlementType, InvoiceReferencedDocument vient
         *    APRÈS SpecifiedTradeSettlementHeaderMonetarySummation.
         */
        foreach ($invoice->getPrecedingInvoiceReferences() as $ref) {
            $doc = $settlement->add("ram:InvoiceReferencedDocument");
            $doc->add("ram:IssuerAssignedID", $ref->getValue());
            if ($ref->getIssueDate() !== null) {
                $doc->add("ram:FormattedIssueDateTime")
                    ->add("qdt:DateTimeString", $ref->getIssueDate()->format("Ymd"), [
                        "format" => "102"
                    ]);
            }
        }

        // BT-19 — last element of the XSD sequence, after InvoiceReferencedDocument
        if ($invoice->getBuyerAccountingReference() !== null) {
            $settlement->add("ram:ReceivableSpecifiedTradeAccountingAccount")
                ->add("ram:ID", $invoice->getBuyerAccountingReference());
        }
    }

    /**
     * Recalcule le breakdown TVA après application des remises/majorations header,
     * en calculant les montants effectifs par taux/catégorie (y compris pour les %).
     *
     * @param VatBreakdown[] $vatBreakdownBefore
     * @return array<int, array{category:string, rate:float|null, taxable:float, tax:float}>
     */
    private function computeVatBreakdownAfterHeaderAdjustments(Invoice $invoice, array $vatBreakdownBefore): array
    {
        $rows = [];
        foreach ($vatBreakdownBefore as $b) {
            // A missing rate is only legitimate for "not subject to VAT" (BR-O-05 forbids one)
            if ($b->rate === null && $b->category !== 'O') {
                continue;
            }
            $key = $b->category . '|' . $b->rate;
            $rows[$key] = [
                'category' => $b->category,
                'rate' => $b->rate,
                'exemptionReason' => $b->exemptionReason,
                'exemptionReasonCode' => $b->exemptionReasonCode,
                'taxable' => (float)$b->taxableAmount,
                'tax' => 0.0,
            ];
        }

        if (empty($rows)) {
            return [];
        }

        foreach ($invoice->getCharges() as $charge) {
            $split = $this->splitHeaderAllowanceOrChargeByVat($charge, $vatBreakdownBefore);
            foreach ($split as $key => $actual) {
                if (isset($rows[$key])) {
                    $rows[$key]['taxable'] += $actual;
                }
            }
        }

        foreach ($invoice->getAllowances() as $allowance) {
            $split = $this->splitHeaderAllowanceOrChargeByVat($allowance, $vatBreakdownBefore);
            foreach ($split as $key => $actual) {
                if (isset($rows[$key])) {
                    $rows[$key]['taxable'] -= $actual;
                }
            }
        }

        foreach ($rows as &$r) {
            $r['taxable'] = max(0.0, round($r['taxable'], 2));
            $r['tax'] = round($r['taxable'] * ((float)$r['rate'] / 100), 2);
        }

        return array_values($rows);
    }

    /**
     * Retourne la ventilation d'une allowance/charge header en montants effectifs par (category|rate),
     * en reproduisant EXACTEMENT la logique de addHeaderAllowanceOrChargeSplitByVat().
     *
     * @param VatBreakdown[] $vatBreakdown
     * @return array<string, float> map "category|rate" => actualAmount
     */
    private function splitHeaderAllowanceOrChargeByVat(AllowanceOrCharge $item, array $vatBreakdown): array
    {
        $lines = array_values(array_filter($vatBreakdown, function ($b) {
            return isset($b->taxableAmount) && (float)$b->taxableAmount > 0 && $b->rate !== null;
        }));

        if (empty($lines)) {
            return [];
        }

        $totalTaxable = 0.0;
        foreach ($lines as $b) {
            $totalTaxable += (float)$b->taxableAmount;
        }
        if ($totalTaxable <= 0) {
            return [];
        }

        $out = [];

        if ($item->isPercentage()) {
            $percent = (float)$item->getAmount();
            foreach ($lines as $b) {
                $basis = (float)$b->taxableAmount;
                $actual = round($basis * ($percent / 100), 2);
                $key = $b->category . '|' . $b->rate;
                $out[$key] = ($out[$key] ?? 0.0) + $actual;
            }
            return $out;
        }

        $fixedTotal = (float)$item->getAmount();
        $acc = 0.0;

        foreach ($lines as $idx => $b) {
            $basis = (float)$b->taxableAmount;
            $ratio = $basis / $totalTaxable;

            if ($idx < count($lines) - 1) {
                $actual = round($fixedTotal * $ratio, 2);
                $acc += $actual;
            } else {
                $actual = round($fixedTotal - $acc, 2);
            }

            $key = $b->category . '|' . $b->rate;
            $out[$key] = ($out[$key] ?? 0.0) + $actual;
        }

        return $out;
    }

    private function addHeaderTradeTax(UXML $parent, VatBreakdown $item): void
    {
        if ($item->rate !== null) {
            $tax = $parent->add("ram:ApplicableTradeTax");
            $tax->add("ram:CalculatedAmount", $this->formatCurrency($item->taxAmount));
            $tax->add("ram:TypeCode", "VAT");
            $tax->add("ram:BasisAmount", $this->formatCurrency($item->taxableAmount));
            $tax->add("ram:CategoryCode", $item->category);
            $tax->add("ram:RateApplicablePercent", $item->rate);
        }
    }

    private function addHeaderAllowanceOrChargeSplitByVat(
        UXML              $parent,
        AllowanceOrCharge $item,
        bool              $isCharge,
        array             $vatBreakdown
    ): void
    {
        $lines = array_values(array_filter($vatBreakdown, function ($b) {
            return isset($b->taxableAmount) && (float)$b->taxableAmount > 0;
        }));

        // ✅ fallback robuste : base = somme des taxables, pour pouvoir calculer un % même si pas de lignes valides
        $fallbackBasisTotal = 0.0;
        foreach ($lines as $b) {
            $fallbackBasisTotal += (float)$b->taxableAmount;
        }

        if (empty($lines) || $fallbackBasisTotal <= 0) {
            $basis = $fallbackBasisTotal > 0 ? $fallbackBasisTotal : null;
            $actual = null;
            if ($item->isPercentage() && $basis !== null) {
                $actual = (float)$item->getEffectiveAmount($basis);
            } elseif (!$item->isPercentage()) {
                $actual = (float)$item->getAmount();
            }

            $this->addHeaderAllowanceOrChargeSingle($parent, $item, $isCharge, $basis, $actual, null);
            return;
        }

        $totalTaxable = $fallbackBasisTotal;

        if ($item->isPercentage()) {
            $percent = (float)$item->getAmount();

            foreach ($lines as $b) {
                $basis = (float)$b->taxableAmount;
                $actual = $basis * ($percent / 100);

                $this->addHeaderAllowanceOrChargeSingle(
                    $parent,
                    $item,
                    $isCharge,
                    $basis,
                    $actual,
                    $b
                );
            }
            return;
        }

        $fixedTotal = (float)$item->getAmount();

        $acc = 0.0;
        $count = count($lines);

        foreach ($lines as $idx => $b) {
            $basis = (float)$b->taxableAmount;
            $ratio = $basis / $totalTaxable;

            if ($idx < $count - 1) {
                $actual = round($fixedTotal * $ratio, 2);
                $acc += $actual;
            } else {
                $actual = round($fixedTotal - $acc, 2);
            }

            $this->addHeaderAllowanceOrChargeSingle(
                $parent,
                $item,
                $isCharge,
                null,
                $actual,
                $b
            );
        }
    }

    private function addHeaderAllowanceOrChargeSingle(
        UXML              $parent,
        AllowanceOrCharge $item,
        bool              $isCharge,
        ?float            $basisAmount,
        ?float            $actualAmount,
                          $vatLine
    ): void
    {
        $ac = $parent->add("ram:SpecifiedTradeAllowanceCharge");

        $ac->add("ram:ChargeIndicator")
            ->add("udt:Indicator", $isCharge ? 'true' : 'false');

        if ($item->isPercentage()) {
            $ac->add("ram:CalculationPercent", (float)$item->getAmount());
            if ($basisAmount !== null) {
                $ac->add("ram:BasisAmount", $this->formatCurrency($basisAmount));
            }
        }

        // ✅ BT-92 : montant EFFECTIF, arrondi comme dans le XML, puis cumul pour BT-107
        $numericActual = $actualAmount;
        if ($numericActual === null) {
            if ($item->isPercentage() && $basisAmount !== null) {
                $numericActual = (float)$item->getEffectiveAmount($basisAmount);
            } else {
                $numericActual = (float)$item->getAmount();
            }
        }
        $numericActual = round((float)$numericActual, 2);

        $ac->add("ram:ActualAmount", $this->formatCurrency($numericActual));

        if ($item->getReasonCode()) {
            $ac->add("ram:ReasonCode", $item->getReasonCode());
        }
        if ($item->getReason()) {
            $ac->add("ram:Reason", $item->getReason());
        }

        $tax = $ac->add("ram:CategoryTradeTax");
        $tax->add("ram:TypeCode", "VAT");

        if ($vatLine !== null) {
            if (!empty($vatLine->category)) {
                $tax->add("ram:CategoryCode", $vatLine->category);
            }
            if ($vatLine->rate !== null) {
                $tax->add("ram:RateApplicablePercent", $vatLine->rate);
            }
        } else {
            if ($item->getVatCategory()) {
                $tax->add("ram:CategoryCode", $item->getVatCategory());
            }
            if ($item->getVatRate() !== null) {
                $tax->add("ram:RateApplicablePercent", $item->getVatRate());
            }
        }

        // ✅ BT-107 = Σ BT-92 (uniquement pour les ALLOWANCES document)
        if ($isCharge) {
            $this->headerChargeTotal += $numericActual;
        } else {
            $this->headerAllowanceTotal += $numericActual;
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

    private function addPaymentMeans(UXML $parent, Invoice $invoice): void
    {
        foreach ($invoice->getPayments() as $payment) {
            $meansCode = $payment->getMeansCode();
            $meansText = $payment->getMeansText();
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

            $xml = $parent->add("ram:SpecifiedTradeSettlementPaymentMeans");
            if ($meansCode !== null) {
                $xml->add("ram:TypeCode", $meansCode);
            }
            if ($meansText !== null) {
                $xml->add("ram:Information", $meansText);
            }

            foreach ($transfers as $transfer) {
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

                if ($provider !== null) {
                    $xml->add("ram:PayeeSpecifiedCreditorFinancialInstitution")
                        ->add("ram:BICID", $provider);
                }
            }
        }
    }

    private function addMonetarySummation(UXML $parent, Invoice $invoice): void
    {
        $totals = $invoice->getTotals();
        $currency = $totals->currency;

        $sum = $parent->add("ram:SpecifiedTradeSettlementHeaderMonetarySummation");

        // BT-106 = Σ BT-131 (donc Σ LineTotalAmount des lignes)
        $lineTotal = round((float) $totals->netAmount, 2);
        $sum->add("ram:LineTotalAmount", $this->formatCurrency($lineTotal));

        // NOTE: XSD order — ChargeTotalAmount (BT-108) before AllowanceTotalAmount (BT-107)
        $chargeTotal = round($this->headerChargeTotal, 2);
        if ($chargeTotal > 0) {
            $sum->add("ram:ChargeTotalAmount", $this->formatCurrency($chargeTotal));
        }
        // BT-107 = Σ BT-92 (exactement ce qui a été écrit)
        $allowanceTotal = round($this->headerAllowanceTotal, 2);
        if ($allowanceTotal > 0) {
            $sum->add("ram:AllowanceTotalAmount", $this->formatCurrency($allowanceTotal));
        }

        // BT-109 = base taxable
        $taxBasis = round($lineTotal - $allowanceTotal + $chargeTotal, 2);
        $sum->add("ram:TaxBasisTotalAmount", $this->formatCurrency($taxBasis));

        // TVA recalculée
        $vatTotal = 0.0;
        foreach ($this->computedVatBreakdownAfterHeaderAC as $b) {
            $vatTotal += (float)$b['tax'];
        }
        $vatTotal = round($vatTotal, 2);
        $sum->add("ram:TaxTotalAmount", $this->formatCurrency($vatTotal), [
            "currencyID" => $currency
        ]);

        // NOTE: XSD order — RoundingAmount (BT-114) before GrandTotalAmount (BT-112)
        if ((float) $totals->roundingAmount !== 0.0) {
            $sum->add("ram:RoundingAmount", $this->formatCurrency((float) $totals->roundingAmount));
        }
        // BT-112
        $grandTotal = round($taxBasis + $vatTotal, 2);
        $sum->add("ram:GrandTotalAmount", $this->formatCurrency($grandTotal));
        if ((float) $totals->paidAmount > 0) {
            $sum->add("ram:TotalPrepaidAmount", $this->formatCurrency((float) $totals->paidAmount));
        }
        $duePayable = round($grandTotal - (float) $totals->paidAmount + (float) $totals->roundingAmount, 2);
        $sum->add("ram:DuePayableAmount", $this->formatCurrency($duePayable));
    }

    /* ================= PARTIES ================= */

    private function addParty(UXML $parent, Party $party): void
    {
        $companyId = $party->getCompanyId();
        if ($companyId !== null) {
            $parent->add("ram:GlobalID", $companyId->getValue(), [
                "schemeID" => $companyId->getScheme()
            ]);
        }

        $name = $party->getName();
        if ($name !== null) {
            $parent->add("ram:Name", $name);
        }

        $this->addLegalOrganization($parent, $party);
        $this->addPostalAddress($parent, $party);
        $this->addElectronicAddress($parent, $party);
        $this->addVatRegistration($parent, $party);
    }

    private function addLegalOrganization(UXML $parent, Party $party): void
    {
        $organizationIdentifier = null;
        foreach ($party->getIdentifiers() as $identifier) {
            if ($identifier->getScheme() === '0002') {
                $organizationIdentifier = $identifier;
                break;
            }
        }

        if ($party->getCompanyId()?->getScheme() === '0002') {
            $org = $parent->add("ram:SpecifiedLegalOrganization");
            $org->add("ram:ID", $party->getCompanyId()->getValue(), [
                "schemeID" => "0002"
            ]);
            return;
        }

        throw new \Exception("Missing legal organization identifier (0002)");
    }

    private function addPostalAddress(UXML $parent, Party $party): void
    {
        $addr = $parent->add("ram:PostalTradeAddress");
        $addr->add("ram:PostcodeCode", $party->getPostalCode());
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
        $addr->add("ram:CityName", $party->getCity());
        $addr->add("ram:CountryID", $party->getCountry());
    }

    private function addElectronicAddress(UXML $parent, Party $party): void
    {
        $ea = $party->getElectronicAddress();
        if ($ea === null) {
            return;
        }

        $parent->add("ram:URIUniversalCommunication")
            ->add("ram:URIID", $ea->getValue(), [
                "schemeID" => $ea->getScheme()
            ]);
    }

    private function addVatRegistration(UXML $parent, Party $party): void
    {
        $vatNumber = $party->getVatNumber();
        if ($vatNumber === null || $vatNumber === '') {
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
