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

    public function export(Invoice $invoice): string
    {
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

            // EN16931/CII : les prix sont HT (pas de conversion via TVA).
            $agreement = $lineItem->add("ram:SpecifiedLineTradeAgreement");

            $baseQty = max(1.0, (float)$line->getBaseQuantity());
            $netUnitPrice = (float)$line->getPrice() / $baseQty;

            // Si vous ne gérez pas de "gross price" distinct (avant remises), gardez-les égaux.
            $agreement->add("ram:GrossPriceProductTradePrice")
                ->add("ram:ChargeAmount", $netUnitPrice);

            $agreement->add("ram:NetPriceProductTradePrice")
                ->add("ram:ChargeAmount", $netUnitPrice);

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

            $settlement
                ->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
                ->add("ram:LineTotalAmount", $line->getNetAmount());
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

        if ($item->getReasonCode()) {
            $ac->add("ram:ReasonCode", $item->getReasonCode());
        }
        if ($item->getReason()) {
            $ac->add("ram:Reason", $item->getReason());
        }

        if ($item->isPercentage()) {
            $ac->add("ram:CalculationPercent", $item->getAmount());
            $ac->add("ram:BasisAmount", $baseAmount);
            $actualAmount = $item->getEffectiveAmount($baseAmount);
        } else {
            $actualAmount = $item->getAmount();
        }

        // EN16931: ActualAmount est un montant positif, le sens est donné par ChargeIndicator
        $ac->add("ram:ActualAmount", $actualAmount);

        // TVA : si l'objet ne porte pas l'info, on hérite de la ligne
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

        // Taxes header (par taux/catégorie)
        foreach ($totals->vatBreakdown as $item) {
            $this->addHeaderTradeTax($settlement, $item);
        }

        /**
         * Remises/majorations "facture" (header):
         * En cas de multi-taux, il faut SPLITTER par VAT breakdown.
         * Base EN16931 = HT (taxExclusive / taxable amounts), pas TTC.
         */
        foreach ($invoice->getCharges() as $charge) {
            $this->addHeaderAllowanceOrChargeSplitByVat($settlement, $charge, true, $totals->vatBreakdown);
        }
        foreach ($invoice->getAllowances() as $allowance) {
            $this->addHeaderAllowanceOrChargeSplitByVat($settlement, $allowance, false, $totals->vatBreakdown);
        }

        $this->addPaymentTerms($settlement, $invoice);
        $this->addMonetarySummation($settlement, $invoice);
    }

    private function addHeaderTradeTax(UXML $parent, VatBreakdown $item): void
    {
        if ($item->rate !== null) {
            $tax = $parent->add("ram:ApplicableTradeTax");
            $tax->add("ram:CalculatedAmount", $item->taxAmount);
            $tax->add("ram:TypeCode", "VAT");
            $tax->add("ram:BasisAmount", $item->taxableAmount);
            $tax->add("ram:CategoryCode", $item->category);
            $tax->add("ram:RateApplicablePercent", $item->rate);
        }
    }

    /**
     * Split d’une remise/majoration header sur plusieurs taux de TVA.
     *
     * - Si % : amount appliqué sur chaque taxableAmount.
     * - Si montant fixe : répartition au prorata des taxableAmount.
     */
    private function addHeaderAllowanceOrChargeSplitByVat(
        UXML              $parent,
        AllowanceOrCharge $item,
        bool              $isCharge,
        array             $vatBreakdown
    ): void
    {
        // Filtrer les lignes de breakdown significatives (base taxable > 0)
        $lines = array_values(array_filter($vatBreakdown, function ($b) {
            return isset($b->taxableAmount) && (float)$b->taxableAmount > 0;
        }));

        if (empty($lines)) {
            // Si aucun breakdown exploitable : on ne peut pas splitter proprement
            // -> on génère quand même une allowance/charge sans RateApplicablePercent
            $this->addHeaderAllowanceOrChargeSingle($parent, $item, $isCharge, null, null, null);
            return;
        }

        $totalTaxable = 0.0;
        foreach ($lines as $b) {
            $totalTaxable += (float)$b->taxableAmount;
        }
        if ($totalTaxable <= 0) {
            $this->addHeaderAllowanceOrChargeSingle($parent, $item, $isCharge, null, null, null);
            return;
        }

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

        // Montant fixe : répartition proportionnelle
        $fixedTotal = (float)$item->getAmount();

        foreach ($lines as $b) {
            $basis = (float)$b->taxableAmount;
            $ratio = $basis / $totalTaxable;
            $actual = $fixedTotal * $ratio;

            $this->addHeaderAllowanceOrChargeSingle(
                $parent,
                $item,
                $isCharge,
                null,      // pas de BasisAmount nécessaire si montant fixe
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
                          $vatLine // VatBreakdown|null
    ): void
    {
        $ac = $parent->add("ram:SpecifiedTradeAllowanceCharge");

        $ac->add("ram:ChargeIndicator")
            ->add("udt:Indicator", $isCharge ? 'true' : 'false');

        if ($item->getReasonCode()) {
            $ac->add("ram:ReasonCode", $item->getReasonCode());
        }
        if ($item->getReason()) {
            $ac->add("ram:Reason", $item->getReason());
        }

        if ($item->isPercentage()) {
            $ac->add("ram:CalculationPercent", (float)$item->getAmount());
            if ($basisAmount !== null) {
                $ac->add("ram:BasisAmount", $basisAmount);
            }
        }

        // ActualAmount requis pour porter le montant effectif
        $ac->add("ram:ActualAmount", $actualAmount ?? (float)$item->getAmount());

        // TVA associée : au header, on la met par split (catégorie + taux)
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
            // fallback minimal : pas de rate
            if ($item->getVatCategory()) {
                $tax->add("ram:CategoryCode", $item->getVatCategory());
            }
            if ($item->getVatRate() !== null) {
                $tax->add("ram:RateApplicablePercent", $item->getVatRate());
            }
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
        $totals = $invoice->getTotals();
        $currency = $invoice->getCurrency();

        $sum = $parent->add("ram:SpecifiedTradeSettlementHeaderMonetarySummation");

        $sum->add("ram:LineTotalAmount", $totals->taxExclusiveAmount);
        $sum->add("ram:TaxBasisTotalAmount", $totals->taxExclusiveAmount);

        $sum->add("ram:TaxTotalAmount", $totals->vatAmount, [
            "currencyID" => $currency
        ]);

        $sum->add("ram:GrandTotalAmount", $totals->taxInclusiveAmount);
        $sum->add("ram:DuePayableAmount", $totals->payableAmount);
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
