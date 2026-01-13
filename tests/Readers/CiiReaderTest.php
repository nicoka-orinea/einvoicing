<?php
namespace Tests\Readers;

use Einvoicing\Invoice;
use Einvoicing\Readers\CiiReader;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;

final class CiiReaderTest extends TestCase {
    public function testCanReadCiiInvoice(): void {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" 
                          xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" 
                          xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>urn:cen.eu:en16931:2017</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>
    <rsm:ExchangedDocument>
        <ram:ID>INV-123</ram:ID>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">20260113</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:IncludedSupplyChainTradeLineItem>
            <ram:AssociatedDocumentLineDocument>
                <ram:LineID>1</ram:LineID>
            </ram:AssociatedDocumentLineDocument>
            <ram:SpecifiedTradeProduct>
                <ram:SellerAssignedID>PROD-1</ram:SellerAssignedID>
                <ram:Name>Product 1</ram:Name>
            </ram:SpecifiedTradeProduct>
            <ram:SpecifiedLineTradeAgreement>
                <ram:NetPriceProductTradePrice>
                    <ram:ChargeAmount>10.00</ram:ChargeAmount>
                </ram:NetPriceProductTradePrice>
            </ram:SpecifiedLineTradeAgreement>
            <ram:SpecifiedLineTradeDelivery>
                <ram:BilledQuantity unitCode="HUR">2</ram:BilledQuantity>
            </ram:SpecifiedLineTradeDelivery>
            <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
                <ram:SpecifiedTradeSettlementLineMonetarySummation>
                    <ram:LineTotalAmount>20.00</ram:LineTotalAmount>
                </ram:SpecifiedTradeSettlementLineMonetarySummation>
            </ram:SpecifiedLineTradeSettlement>
        </ram:IncludedSupplyChainTradeLineItem>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>Seller Name</ram:Name>
                <ram:PostalTradeAddress>
                    <ram:PostcodeCode>75001</ram:PostcodeCode>
                    <ram:CityName>Paris</ram:CityName>
                    <ram:CountryID>FR</ram:CountryID>
                </ram:PostalTradeAddress>
                <ram:SpecifiedTaxRegistration>
                    <ram:ID schemeID="VA">FR123456789</ram:ID>
                </ram:SpecifiedTaxRegistration>
            </ram:SellerTradeParty>
            <ram:BuyerTradeParty>
                <ram:Name>Buyer Name</ram:Name>
                <ram:PostalTradeAddress>
                    <ram:PostcodeCode>69001</ram:PostcodeCode>
                    <ram:CityName>Lyon</ram:CityName>
                    <ram:CountryID>FR</ram:CountryID>
                </ram:PostalTradeAddress>
            </ram:BuyerTradeParty>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeDelivery>
            <ram:ActualDeliverySupplyChainEvent>
                <ram:OccurrenceDateTime>
                    <udt:DateTimeString format="102">20260113</udt:DateTimeString>
                </ram:OccurrenceDateTime>
            </ram:ActualDeliverySupplyChainEvent>
        </ram:ApplicableHeaderTradeDelivery>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:ApplicableTradeTax>
                <ram:CalculatedAmount>4.00</ram:CalculatedAmount>
                <ram:TypeCode>VAT</ram:TypeCode>
                <ram:BasisAmount>20.00</ram:BasisAmount>
                <ram:CategoryCode>S</ram:CategoryCode>
                <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
            </ram:ApplicableTradeTax>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>20.00</ram:LineTotalAmount>
                <ram:TaxBasisTotalAmount>20.00</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="EUR">4.00</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>24.00</ram:GrandTotalAmount>
                <ram:DuePayableAmount>24.00</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;

        $reader = new CiiReader();
        $invoice = $reader->import($xml);

        $this->assertEquals('INV-123', $invoice->getNumber());
        $this->assertEquals(Invoice::TYPE_COMMERCIAL_INVOICE, $invoice->getType());
        $this->assertEquals('2026-01-13', $invoice->getIssueDate()->format('Y-m-d'));
        $this->assertEquals('EUR', $invoice->getCurrency());

        $this->assertEquals('Seller Name', $invoice->getSeller()->getName());
        $this->assertEquals('FR123456789', $invoice->getSeller()->getVatNumber());
        $this->assertEquals('FR', $invoice->getSeller()->getCountry());

        $this->assertEquals('Buyer Name', $invoice->getBuyer()->getName());

        $lines = $invoice->getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals('1', $lines[0]->getId());
        $this->assertEquals('Product 1', $lines[0]->getName());
        $this->assertEquals('PROD-1', $lines[0]->getSellerIdentifier());
        $this->assertEquals(10.0, $lines[0]->getPrice());
        $this->assertEquals(2.0, $lines[0]->getQuantity());
        $this->assertEquals('HUR', $lines[0]->getUnit());
        $this->assertEquals('S', $lines[0]->getVatCategory());
        $this->assertEquals(20.0, $lines[0]->getVatRate());

        $totals = $invoice->getTotals();
        $this->assertEquals(20.0, $totals->netAmount);
        $this->assertEquals(4.0, $totals->vatAmount);
        $this->assertEquals(24.0, $totals->payableAmount);
    }

    public function testCanReadCiiAdvancedFields(): void {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" 
                          xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" 
                          xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocument>
        <ram:ID>INV-ADV</ram:ID>
        <ram:IncludedNote>
            <ram:Content>General note 1</ram:Content>
        </ram:IncludedNote>
        <ram:IncludedNote>
            <ram:Content>General note 2</ram:Content>
        </ram:IncludedNote>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:IncludedSupplyChainTradeLineItem>
            <ram:AssociatedDocumentLineDocument>
                <ram:LineID>1</ram:LineID>
                <ram:IncludedNote>
                    <ram:Content>Line note</ram:Content>
                </ram:IncludedNote>
            </ram:AssociatedDocumentLineDocument>
            <ram:SpecifiedTradeProduct>
                <ram:GlobalID schemeID="0160">1234567890123</ram:GlobalID>
                <ram:SellerAssignedID>S-ID</ram:SellerAssignedID>
                <ram:BuyerAssignedID>B-ID</ram:BuyerAssignedID>
                <ram:Name>Product Name</ram:Name>
                <ram:Description>Product Description</ram:Description>
                <ram:OriginTradeCountry>
                    <ram:ID>DE</ram:ID>
                </ram:OriginTradeCountry>
            </ram:SpecifiedTradeProduct>
            <ram:SpecifiedLineTradeAgreement>
                <ram:NetPriceProductTradePrice>
                    <ram:ChargeAmount>100</ram:ChargeAmount>
                    <ram:BasisQuantity>1</ram:BasisQuantity>
                </ram:NetPriceProductTradePrice>
                <ram:BuyerOrderReferencedDocument>
                    <ram:LineID>ORDER-LINE-1</ram:LineID>
                </ram:BuyerOrderReferencedDocument>
            </ram:SpecifiedLineTradeAgreement>
            <ram:SpecifiedLineTradeDelivery>
                <ram:BilledQuantity unitCode="HUR">1</ram:BilledQuantity>
            </ram:SpecifiedLineTradeDelivery>
            <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>19</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
                <ram:ReceivableSpecifiedTradeAccountingAccount>
                    <ram:ID>LINE-ACCOUNT</ram:ID>
                </ram:ReceivableSpecifiedTradeAccountingAccount>
            </ram:SpecifiedLineTradeSettlement>
        </ram:IncludedSupplyChainTradeLineItem>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>Seller</ram:Name>
                <ram:SpecifiedLegalOrganization>
                    <ram:TradingBusinessName>Seller Trading Name</ram:TradingBusinessName>
                </ram:SpecifiedLegalOrganization>
                <ram:DefinedTradeContact>
                    <ram:PersonName>John Doe</ram:PersonName>
                    <ram:TelephoneUniversalCommunication>
                        <ram:CompleteNumber>+33123456789</ram:CompleteNumber>
                    </ram:TelephoneUniversalCommunication>
                    <ram:EmailURIUniversalCommunication>
                        <ram:URIID>john@example.com</ram:URIID>
                    </ram:EmailURIUniversalCommunication>
                </ram:DefinedTradeContact>
                <ram:PostalTradeAddress><ram:CountryID>FR</ram:CountryID></ram:PostalTradeAddress>
            </ram:SellerTradeParty>
            <ram:BuyerTradeParty>
                <ram:Name>Buyer</ram:Name>
                <ram:PostalTradeAddress><ram:CountryID>FR</ram:CountryID></ram:PostalTradeAddress>
            </ram:BuyerTradeParty>
            <ram:SellerOrderReferencedDocument>
                <ram:IssuerAssignedID>SO-123</ram:IssuerAssignedID>
            </ram:SellerOrderReferencedDocument>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:TaxApplicableTradeCurrencyExchange>
                <ram:DateString format="102">20260114</ram:DateString>
            </ram:TaxApplicableTradeCurrencyExchange>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>100.00</ram:LineTotalAmount>
                <ram:TaxBasisTotalAmount>100.00</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="EUR">19.00</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>119.00</ram:GrandTotalAmount>
                <ram:DuePayableAmount>119.00</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
        $reader = new CiiReader();
        $invoice = $reader->import($xml);

        $this->assertEquals(['General note 1', 'General note 2'], $invoice->getNotes());
        $this->assertEquals('SO-123', $invoice->getSalesOrderReference());
        $this->assertEquals('2026-01-14', $invoice->getTaxPointDate()->format('Y-m-d'));

        $seller = $invoice->getSeller();
        $this->assertEquals('Seller Trading Name', $seller->getTradingName());
        $this->assertEquals('John Doe', $seller->getContactName());
        $this->assertEquals('+33123456789', $seller->getContactPhone());
        $this->assertEquals('john@example.com', $seller->getContactEmail());

        $line = $invoice->getLines()[0];
        $this->assertEquals('Line note', $line->getNote());
        $this->assertEquals('Product Description', $line->getDescription());
        $this->assertEquals('B-ID', $line->getBuyerIdentifier());
        $this->assertEquals('1234567890123', $line->getStandardIdentifier()->getValue());
        $this->assertEquals('0160', $line->getStandardIdentifier()->getScheme());
        $this->assertEquals('DE', $line->getOriginCountry());
        $this->assertEquals('ORDER-LINE-1', $line->getOrderLineReference());
        $this->assertEquals('LINE-ACCOUNT', $line->getBuyerAccountingReference());
    }

    public function testCanReadCiiInvoiceWithAllowancesAndCharges(): void {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" 
                          xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" 
                          xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>urn:cen.eu:en16931:2017</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>
    <rsm:ExchangedDocument>
        <ram:ID>INV-AC-123</ram:ID>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">20260113</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:IncludedSupplyChainTradeLineItem>
            <ram:AssociatedDocumentLineDocument>
                <ram:LineID>1</ram:LineID>
            </ram:AssociatedDocumentLineDocument>
            <ram:SpecifiedTradeProduct>
                <ram:Name>Product 1</ram:Name>
            </ram:SpecifiedTradeProduct>
            <ram:SpecifiedLineTradeAgreement>
                <ram:NetPriceProductTradePrice>
                    <ram:ChargeAmount>100.00</ram:ChargeAmount>
                </ram:NetPriceProductTradePrice>
            </ram:SpecifiedLineTradeAgreement>
            <ram:SpecifiedLineTradeDelivery>
                <ram:BilledQuantity unitCode="HUR">1</ram:BilledQuantity>
            </ram:SpecifiedLineTradeDelivery>
            <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
                <ram:SpecifiedTradeAllowanceCharge>
                    <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>
                    <ram:ActualAmount>10.00</ram:ActualAmount>
                    <ram:Reason>Line discount</ram:Reason>
                </ram:SpecifiedTradeAllowanceCharge>
                <ram:SpecifiedTradeSettlementLineMonetarySummation>
                    <ram:LineTotalAmount>90.00</ram:LineTotalAmount>
                </ram:SpecifiedTradeSettlementLineMonetarySummation>
            </ram:SpecifiedLineTradeSettlement>
        </ram:IncludedSupplyChainTradeLineItem>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty><ram:Name>Seller</ram:Name><ram:PostalTradeAddress><ram:CountryID>FR</ram:CountryID></ram:PostalTradeAddress></ram:SellerTradeParty>
            <ram:BuyerTradeParty><ram:Name>Buyer</ram:Name><ram:PostalTradeAddress><ram:CountryID>FR</ram:CountryID></ram:PostalTradeAddress></ram:BuyerTradeParty>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:SpecifiedTradeAllowanceCharge>
                <ram:ChargeIndicator><udt:Indicator>true</udt:Indicator></ram:ChargeIndicator>
                <ram:CalculationPercent>10</ram:CalculationPercent>
                <ram:ActualAmount>9.00</ram:ActualAmount>
                <ram:Reason>Handling</ram:Reason>
                <ram:CategoryTradeTax>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
                </ram:CategoryTradeTax>
            </ram:SpecifiedTradeAllowanceCharge>
            <ram:ApplicableTradeTax>
                <ram:CalculatedAmount>19.80</ram:CalculatedAmount>
                <ram:TypeCode>VAT</ram:TypeCode>
                <ram:BasisAmount>99.00</ram:BasisAmount>
                <ram:CategoryCode>S</ram:CategoryCode>
                <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
            </ram:ApplicableTradeTax>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>90.00</ram:LineTotalAmount>
                <ram:ChargeTotalAmount>9.00</ram:ChargeTotalAmount>
                <ram:TaxBasisTotalAmount>99.00</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="EUR">19.80</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>118.80</ram:GrandTotalAmount>
                <ram:DuePayableAmount>118.80</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
        $reader = new CiiReader();
        $invoice = $reader->import($xml);

        $line = $invoice->getLines()[0];
        $this->assertCount(1, $line->getAllowances());
        $this->assertEquals(10.0, $line->getAllowances()[0]->getAmount());
        $this->assertEquals('Line discount', $line->getAllowances()[0]->getReason());

        $this->assertCount(1, $invoice->getCharges());
        $this->assertEquals(10.0, $invoice->getCharges()[0]->getAmount());
        $this->assertTrue($invoice->getCharges()[0]->isPercentage());
        $this->assertEquals('Handling', $invoice->getCharges()[0]->getReason());
        $this->assertEquals('S', $invoice->getCharges()[0]->getVatCategory());
        $this->assertEquals(20.0, $invoice->getCharges()[0]->getVatRate());

        $totals = $invoice->getTotals();
        $this->assertEquals(90.0, $totals->netAmount);
        $this->assertEquals(9.0, $totals->chargesAmount);
        $this->assertEquals(99.0, $totals->taxExclusiveAmount);
        $this->assertEquals(19.80, $totals->vatAmount);
    }
}
