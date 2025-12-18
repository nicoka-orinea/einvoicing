<?php

namespace Einvoicing\Writers;

use Einvoicing\Invoice;
use Einvoicing\Party;
use UXML\UXML;

class CiiWriter extends AbstractWriter
{
    const NS_INVOICE = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /**
     * @inheritdoc
     */
    public function export(Invoice $invoice): string
    {

        $xml = UXML::newInstance("rsm:CrossIndustryInvoice", null, [
            'xmlns:rsm' => self::NS_INVOICE,
            'xmlns:ram' => self::NS_RAM,
            'xmlns:udt' => self::NS_UDT
        ]);

        $xml->add("rsm:ExchangedDocumentContext")
            ->add("ram:GuidelineSpecifiedDocumentContextParameter")
            ->add("ram:ID", "urn:cen.eu:en16931:2017");

        // Document ref
        $exchanged_document = $xml->add("rsm:ExchangedDocument");
        $exchanged_document->add("ram:ID", $invoice->getNumber());
        $exchanged_document->add("ram:TypeCode", $invoice->getType());
        $exchanged_document->add("ram:IssueDateTime")
            ->add("udt:DateTimeString", $invoice->getIssueDate()?->format("Ymd"), [
                "format" => "102"
            ]);

        $transaction = $xml->add("rsm:SupplyChainTradeTransaction");

        $this->getLineItems($transaction, $invoice);
        $tradeAgreement = $xml->add("ram:ApplicableHeaderTradeAgreement");
        $this->partyBody($tradeAgreement->add("ram:SellerTradeParty"), $invoice->getSeller());
        $this->partyBody($tradeAgreement->add("ram:BuyerTradeParty"), $invoice->getBuyer());

        $xml->add("ram:ApplicableHeaderTradeDelivery");

        $tradeSettlement = $xml->add("ram:ApplicableHeaderTradeSettlement");
        $tradeSettlement->add("ram:InvoiceCurrencyCode", $invoice->getCurrency());

        $tradeTax = $tradeSettlement->add("ram:ApplicableTradeTax");
        $tradeTax->add("ram:TypeCode", "VAT");
        $tradeTax->add("ram:CategoryCode", "S");
        $tradeTax->add("ram:RateApplicablePercent", "20");

        $monetarySummation = $tradeSettlement->add("ram:SpecifiedTradeSettlementHeaderMonetarySummation");
        $totals = $invoice->getTotals();
        $monetarySummation->add("ram:LineTotalAmount", $totals->taxExclusiveAmount);
        $monetarySummation->add("ram:TaxBasisTotalAmount", $totals->taxExclusiveAmount);
        $monetarySummation->add("ram:TaxTotalAmount", $totals->vatAmount);
        $monetarySummation->add("ram:TaxTotalAmount", $totals->taxInclusiveAmount);
        $monetarySummation->add("ram:DuePayableAmount", $totals->payableAmount);


        return $xml->asXML();
    }


    private function getLineItems(UXML $parent, Invoice $invoice)
    {
        foreach ($invoice->getLines() as $line) {
            $lineBlock = $parent->add("ram:IncludedSupplyChainTradeLineItem");
            $lineBlock
                ->add("ram:AssociatedDocumentLineDocument")
                ->add("ram:LineID", $line->getId());

            $product = $lineBlock->add("ram:SpecifiedTradeProduct");
            $product->add("ram:SellerAssignedID", $line->getSellerIdentifier());
            $product->add("ram:Name", $line->getName());

            $tradeAgreement = $lineBlock->add("ram:SpecifiedLineTradeAgreement");
            $tradeAgreement->add("ram:GrossPriceProductTradePrice")
                ->add("ram:ChargeAmount", $line->getPrice());
            $tradeAgreement->add("ram:NetPriceProductTradePrice")
                ->add("ram:ChargeAmount", $line->getNetAmount());

            $lineBlock->add("ram:SpecifiedLineTradeDelivery")->add("ram:BilledQuantity", $line->getQuantity(), [
                "unitCode" => $line->getUnit()
            ]);

            $settlement = $lineBlock->add("ram:SpecifiedLineTradeSettlement");
            $applicableTradeTax = $settlement->add("ram:ApplicableTradeTax");
            $applicableTradeTax->add("ram:TypeCode", "VAT");
            $applicableTradeTax->add("ram:CategoryCode", $line->getVatCategory());
            $applicableTradeTax->add("ram:RateApplicablePercent", $line->getVatRate());

            if (!is_null($line->getPeriodStartDate()) && !is_null($line->getPeriodEndDate())) {
                $billingPeriod = $settlement->add("ram:BillingSpecifiedPeriod");
                $billingPeriod->add("ram:StartDateTime")->add("udt:DateTimeString", $line->getPeriodStartDate()?->format("Ymd"), [
                    "format" => "102"
                ]);
                $billingPeriod->add("ram:EndDateTime")->add("udt:DateTimeString", $line->getPeriodEndDate()?->format("Ymd"), [
                    "format" => "102"
                ]);
            }
            $lineBlock
                ->add("ram:SpecifiedTradeSettlementLineMonetarySummation")
                ->add("ram:LineTotalAmount", $line->getNetAmount());


        }
    }

    private function partyBody(UXML $parent, Party $party)
    {
        $electronicAddress = $party->getElectronicAddress();
        $companyId = $party->getCompanyId();
        $parent->add("ram:GlobalID", $electronicAddress->getValue(), [
            "schemeID" => $electronicAddress->getScheme()
        ]);
        $parent->add("ram:Name", $party->getName());
        $parent
            ->add("ram:SpecifiedLegalOrganization")
            ->add("ram:ID", $companyId->getValue(), [
                "schemeID" => $companyId->getScheme()
            ]);
        $parent
            ->add("ram:SpecifiedTaxRegistration")
            ->add("ram:ID", $party->getVatNumber());
    }

}
