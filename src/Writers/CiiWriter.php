<?php

namespace Einvoicing\Writers;

use Einvoicing\Invoice;
use Einvoicing\Party;
use UXML\UXML;

class CiiWriter extends AbstractWriter
{
    const NS_INVOICE = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM     = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_UDT     = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

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
            ->add("ram:ID", "urn:factur-x.eu:1p0:en16931");
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

            $agreement = $lineItem->add("ram:SpecifiedLineTradeAgreement");

            $vatRate = $line->getVatRate(); // ex: 20
            $netUnitPrice = $line->getPrice() / $line->getBaseQuantity();

            $grossUnitPrice = $netUnitPrice / (1 + $vatRate / 100);

            $agreement->add("ram:GrossPriceProductTradePrice")
                ->add("ram:ChargeAmount", $grossUnitPrice);

            $agreement->add("ram:NetPriceProductTradePrice")
                ->add("ram:ChargeAmount", $netUnitPrice);

            $lineItem->add("ram:SpecifiedLineTradeDelivery")
                ->add("ram:BilledQuantity", $line->getQuantity(), [
                    "unitCode" => $line->getUnit()
                ]);
            foreach ($line->getCharges() as $charge) {
                //TODO: traiter les cas de présence de majoration
            }

            foreach ($line->getAllowances() as $charge) {
                //TODO: traiter les cas de présence de remises
            }

            $settlement = $lineItem->add("ram:SpecifiedLineTradeSettlement");

            $this->addLineTradeTax($settlement, $line);

            if ($line->getPeriodStartDate() && $line->getPeriodEndDate()) {
                $this->addBillingPeriod($settlement, $line);
            }

            $settlement
                ->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
                ->add("ram:LineTotalAmount", $line->getNetAmount());
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

        foreach ($invoice->getTotals()->vatBreakdown as $item) {
            $this->addHeaderTradeTax($settlement, $item);
        }

        $this->addPaymentTerms($settlement, $invoice);
        $this->addMonetarySummation($settlement, $invoice);
    }

    private function addHeaderTradeTax(UXML $parent, $item): void
    {
        $tax = $parent->add("ram:ApplicableTradeTax");
        $tax->add("ram:CalculatedAmount", $item->taxAmount);
        $tax->add("ram:TypeCode", "VAT");
        $tax->add("ram:BasisAmount", $item->taxableAmount);
        $tax->add("ram:CategoryCode", $item->category);
        $tax->add("ram:RateApplicablePercent", $item->rate);
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
