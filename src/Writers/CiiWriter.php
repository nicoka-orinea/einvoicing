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

    private function formatCurrency(float $amount): string
    {
        return number_format(round($amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    public function export(Invoice $invoice): string
    {
        $this->computedVatBreakdownAfterHeaderAC = null;
        $this->headerAllowanceTotal = 0.0;

        $xml = $this->createRoot();
        $this->addContext($xml);
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
            'xmlns:udt' => self::NS_UDT
        ]);
    }

    private function addContext(UXML $xml): void
    {
        $xml->add("rsm:ExchangedDocumentContext")
            ->add("ram:GuidelineSpecifiedDocumentContextParameter")
            ->add("ram:ID", "urn:cen.eu:en16931:2017");
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
    }

    /* ================= LINE ITEMS ================= */

    private function addLineItems(UXML $parent, Invoice $invoice): void
    {
        foreach ($invoice->getLines() as $line) {

            $lineItem = $parent->add("ram:IncludedSupplyChainTradeLineItem");

            $lineItem
                ->add("ram:AssociatedDocumentLineDocument")
                ->add("ram:LineID", $line->getId());

            $product = $lineItem->add("ram:SpecifiedTradeProduct");
            $product->add("ram:SellerAssignedID", $line->getSellerIdentifier());
            $product->add("ram:Name", $line->getName());

            // EN16931/CII : les prix sont HT
            $agreement = $lineItem->add("ram:SpecifiedLineTradeAgreement");

            $baseQty = max(1.0, (float)$line->getBaseQuantity());
            $netUnitPrice = (float)$line->getPrice() / $baseQty;

            $agreement->add("ram:GrossPriceProductTradePrice")
                ->add("ram:ChargeAmount", $this->formatCurrency($netUnitPrice));

            $agreement->add("ram:NetPriceProductTradePrice")
                ->add("ram:ChargeAmount", $this->formatCurrency($netUnitPrice));

            $lineItem->add("ram:SpecifiedLineTradeDelivery")
                ->add("ram:BilledQuantity", $line->getQuantity(), [
                    "unitCode" => $line->getUnit()
                ]);

            $settlement = $lineItem->add("ram:SpecifiedLineTradeSettlement");

            $this->addLineTradeTax($settlement, $line);

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

            if ($line->getPeriodStartDate() && $line->getPeriodEndDate()) {
                $this->addBillingPeriod($settlement, $line);
            }

            // ✅ BT-131 (Invoice line net amount) = net de ligne APRÈS remises/charges de ligne
            $settlement
                ->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
                ->add("ram:LineTotalAmount", $this->formatCurrency((float)$line->getNetAmount()));
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
        $tax->add("ram:RateApplicablePercent", $line->getVatRate());
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
        $this->addParty($agreement->add("ram:SellerTradeParty"), $invoice->getSeller());
        $this->addParty($agreement->add("ram:BuyerTradeParty"), $invoice->getBuyer());
    }

    private function addHeaderDelivery(UXML $parent, Invoice $invoice): void
    {
        $parent->add("ram:ApplicableHeaderTradeDelivery")
            ->add("ram:ActualDeliverySupplyChainEvent")
            ->add("ram:OccurrenceDateTime")
            ->add("udt:DateTimeString", $invoice->getIssueDate()?->format("Ymd"), [
                "format" => "102"
            ]);
    }

    private function addHeaderSettlement(UXML $parent, Invoice $invoice): void
    {
        $settlement = $parent->add("ram:ApplicableHeaderTradeSettlement");
        $settlement->add("ram:InvoiceCurrencyCode", $invoice->getCurrency());

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
        foreach ($this->computedVatBreakdownAfterHeaderAC as $b) {
            if ($b['rate'] === null) {
                continue;
            }
            $tax = $settlement->add("ram:ApplicableTradeTax");
            $tax->add("ram:CalculatedAmount", $this->formatCurrency((float)$b['tax']));
            $tax->add("ram:TypeCode", "VAT");
            $tax->add("ram:BasisAmount", $this->formatCurrency((float)$b['taxable']));
            $tax->add("ram:CategoryCode", $b['category']);
            $tax->add("ram:RateApplicablePercent", $b['rate']);
        }

        /**
         * 3) On écrit les remises/majorations header (split par breakdown AVANT adjustments),
         *    car la base des % est la base taxable "pré-remise header".
         */
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
            if ($b->rate === null) {
                continue;
            }
            $key = $b->category . '|' . $b->rate;
            $rows[$key] = [
                'category' => $b->category,
                'rate' => $b->rate,
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
        if (!$isCharge) {
            $this->headerAllowanceTotal += $numericActual;
        }
    }

    private function addPaymentTerms(UXML $parent, Invoice $invoice): void
    {
        $parent->add("ram:SpecifiedTradePaymentTerms")
            ->add("ram:DueDateDateTime")
            ->add("udt:DateTimeString", $invoice->getIssueDate()?->format("Ymd"), [
                "format" => "102"
            ]);
    }

    private function addMonetarySummation(UXML $parent, Invoice $invoice): void
    {
        $currency = $invoice->getCurrency();

        $sum = $parent->add("ram:SpecifiedTradeSettlementHeaderMonetarySummation");

        // BT-106 = Σ BT-131 (donc Σ LineTotalAmount des lignes)
        $lineTotal = 0.0;
        foreach ($invoice->getLines() as $line) {
            $lineTotal += (float)$line->getNetAmount();
        }
        $lineTotal = round($lineTotal, 2);
        $sum->add("ram:LineTotalAmount", $this->formatCurrency($lineTotal));

        // BT-107 = Σ BT-92 (exactement ce qui a été écrit)
        $allowanceTotal = round($this->headerAllowanceTotal, 2);
        if ($allowanceTotal > 0) {
            $sum->add("ram:AllowanceTotalAmount", $this->formatCurrency($allowanceTotal));
        }

        // BT-109 = base taxable
        $taxBasis = round($lineTotal - $allowanceTotal, 2);
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

        // BT-112
        $grandTotal = round($taxBasis + $vatTotal, 2);
        $sum->add("ram:GrandTotalAmount", $this->formatCurrency($grandTotal));
        $sum->add("ram:DuePayableAmount", $this->formatCurrency($grandTotal));
    }

    /* ================= PARTIES ================= */

    private function addParty(UXML $parent, Party $party): void
    {
        $companyId = $party->getCompanyId();

        $parent->add("ram:GlobalID", $companyId->getValue(), [
            "schemeID" => $companyId->getScheme()
        ]);

        $parent->add("ram:Name", $party->getName());

        $this->addLegalOrganization($parent, $party);
        $this->addPostalAddress($parent, $party);
        $this->addElectronicAddress($parent, $party);
        $this->addVatRegistration($parent, $party);
    }

    private function addLegalOrganization(UXML $parent, Party $party): void
    {
        foreach ($party->getIdentifiers() as $identifier) {
            if ($identifier->getScheme() === '0002') {
                $org = $parent->add("ram:SpecifiedLegalOrganization");
                $org->add("ram:ID", $identifier->getValue(), [
                    "schemeID" => "0002"
                ]);
                return;
            }
        }

        throw new \Exception("Missing legal organization identifier (0002)");
    }

    private function addPostalAddress(UXML $parent, Party $party): void
    {
        $addr = $parent->add("ram:PostalTradeAddress");
        $addr->add("ram:PostcodeCode", $party->getPostalCode());
        $addr->add("ram:LineOne", implode("\n", $party->getAddress()));
        $addr->add("ram:CityName", $party->getCity());
        $addr->add("ram:CountryID", $party->getCountry());
    }

    private function addElectronicAddress(UXML $parent, Party $party): void
    {
        $ea = $party->getElectronicAddress();

        $parent->add("ram:URIUniversalCommunication")
            ->add("ram:URIID", $ea->getValue(), [
                "schemeID" => $ea->getScheme()
            ]);
    }

    private function addVatRegistration(UXML $parent, Party $party): void
    {
        $parent->add("ram:SpecifiedTaxRegistration")
            ->add("ram:ID", $party->getVatNumber(), [
                "schemeID" => "VA"
            ]);
    }
}
