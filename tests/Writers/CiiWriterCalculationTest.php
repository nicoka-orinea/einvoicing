<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Exceptions\ExportException;
use Einvoicing\Delivery;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Party;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;
use UXML\UXML;

/**
 * CiiWriter must never compute a monetary value of its own: everything comes
 * from InvoiceTotals. These tests pin the resulting figures and the element
 * order required by the Factur-X EN 16931 schema.
 */
final class CiiWriterCalculationTest extends TestCase {
    use ValidatesAgainstXsd;

    private const TX = 'rsm:SupplyChainTradeTransaction';
    private const SETTLEMENT = self::TX . '/ram:ApplicableHeaderTradeSettlement';
    private const SUMMATION = self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
    private const LINE = self::TX . '/ram:IncludedSupplyChainTradeLineItem';

    private function getInvoice(): Invoice {
        $seller = (new Party)
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setCompanyId(new Identifier('987654321', '0002'))
            ->setName('Buyer Name Ltd.')
            ->setPostalCode('69001')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = (new Invoice)->setRoundingMatrix(['' => 2]);
        return $invoice
            ->setSpecification('urn:cen.eu:en16931:2017')
            ->setBusinessProcess('B1')
            ->setNumber('INV-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer);
    }

    private function export(Invoice $invoice): UXML {
        return UXML::fromString((new CiiWriter())->export($invoice));
    }

    private function text(UXML $xml, string $path): ?string {
        return $xml->get($path)?->asText();
    }

    /* ================= AMOUNTS ================= */

    public function testHeaderAllowanceAppliedOnce(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20))
            ->addAllowance((new AllowanceOrCharge)->setAmount(10)->markAsPercentage()->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $allowances = $xml->getAll(self::SETTLEMENT . '/ram:SpecifiedTradeAllowanceCharge');
        $this->assertCount(1, $allowances);
        $this->assertSame('10.00', $allowances[0]->get('ram:ActualAmount')->asText());
        $this->assertSame('100.00', $allowances[0]->get('ram:BasisAmount')->asText());
        $this->assertSame('10', $allowances[0]->get('ram:CalculationPercent')->asText());

        $taxes = $xml->getAll(self::SETTLEMENT . '/ram:ApplicableTradeTax');
        $this->assertCount(1, $taxes);
        $this->assertSame('90.00', $taxes[0]->get('ram:BasisAmount')->asText());
        $this->assertSame('18.00', $taxes[0]->get('ram:CalculatedAmount')->asText());

        $this->assertSame('90.00', $this->text($xml, self::SUMMATION . '/ram:TaxBasisTotalAmount'));
        $this->assertSame('108.00', $this->text($xml, self::SUMMATION . '/ram:GrandTotalAmount'));
        $this->assertSame('10.00', $this->text($xml, self::SUMMATION . '/ram:AllowanceTotalAmount'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testBaseQuantityPrice(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100, 2)->setQuantity(2)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $price = $xml->get(self::LINE . '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice');

        // BT-146 is the price for BT-149 units, it is never divided
        $this->assertSame('100', $price->get('ram:ChargeAmount')->asText());
        $this->assertSame('2', $price->get('ram:BasisQuantity')->asText());
        $this->assertSame('100.00', $this->text($xml, self::SUMMATION . '/ram:LineTotalAmount'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testFractionalBaseQuantity(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(10, 0.5)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $price = $xml->get(self::LINE . '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice');

        $this->assertSame('0.5', $price->get('ram:BasisQuantity')->asText());
        $this->assertSame('20.00', $this->text($xml, self::SUMMATION . '/ram:LineTotalAmount'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testZeroBaseQuantityIsRejected(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(10)->setBaseQuantity(0)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $this->expectException(ExportException::class);
        (new CiiWriter())->export($invoice);
    }

    public function testCategoryOInvoice(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)
                ->setName('Line')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('O')
                ->setVatRate(null)
                ->setVatExemptionReason('Hors champ TVA'));

        $xml = $this->export($invoice);

        $taxes = $xml->getAll(self::SETTLEMENT . '/ram:ApplicableTradeTax');
        $this->assertCount(1, $taxes);
        $this->assertSame('O', $taxes[0]->get('ram:CategoryCode')->asText());
        $this->assertNull($taxes[0]->get('ram:RateApplicablePercent'));
        $this->assertSame('0.00', $taxes[0]->get('ram:CalculatedAmount')->asText());
        $this->assertSame('100.00', $taxes[0]->get('ram:BasisAmount')->asText());
        $this->assertSame('Hors champ TVA', $taxes[0]->get('ram:ExemptionReason')->asText());

        // A rateless line must not carry an empty percentage either
        $this->assertNull($xml->get(self::LINE . '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testMultiRateHeaderAllowance(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20))
            ->addLine((new InvoiceLine)->setName('L2')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(5.5))
            ->addAllowance((new AllowanceOrCharge)->setAmount(10)->markAsPercentage()->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        // One element per model item: the allowance is never split by VAT rate
        $allowances = $xml->getAll(self::SETTLEMENT . '/ram:SpecifiedTradeAllowanceCharge');
        $this->assertCount(1, $allowances);
        $this->assertSame('20.00', $allowances[0]->get('ram:ActualAmount')->asText());

        $taxes = $xml->getAll(self::SETTLEMENT . '/ram:ApplicableTradeTax');
        $this->assertCount(2, $taxes);
        $bases = [];
        foreach ($taxes as $tax) {
            $bases[$tax->get('ram:RateApplicablePercent')->asText()] = $tax->get('ram:BasisAmount')->asText();
        }
        $this->assertSame('80.00', $bases['20']);
        $this->assertSame('100.00', $bases['5.5']);
        $this->assertValidFacturX($xml->asXML());
    }

    public function testBt111DualCurrency(): void {
        $invoice = $this->getInvoice()
            ->setVatCurrency('USD')
            ->setCustomVatAmount(12.34)
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $taxTotals = $xml->getAll(self::SUMMATION . '/ram:TaxTotalAmount');
        $this->assertCount(2, $taxTotals);
        $this->assertSame('EUR', $taxTotals[0]->element()->getAttribute('currencyID'));
        $this->assertSame('20.00', $taxTotals[0]->asText());
        $this->assertSame('USD', $taxTotals[1]->element()->getAttribute('currencyID'));
        $this->assertSame('12.34', $taxTotals[1]->asText());
        $this->assertValidFacturX($xml->asXML());
    }

    /* ================= ELEMENT ORDER ================= */

    public function testSettlementChildOrder(): void {
        $invoice = $this->getInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setVatCurrency('USD')
            ->setCustomVatAmount(1)
            ->setBuyerAccountingReference('ACC-1')
            ->setDueDate(new DateTime('2026-02-15'))
            ->setPeriodStartDate(new DateTime('2026-01-01'))
            ->setPeriodEndDate(new DateTime('2026-01-31'))
            ->setPayee((new Party)->setName('Payee')->setCountry('FR'))
            ->addPayment((new Payment)->setId('PAY-1')->setMeansCode('30')->addTransfer((new Transfer)->setAccountId('FR7612345678901234567890123')))
            ->addAllowance((new AllowanceOrCharge)->setAmount(5)->setVatCategory('S')->setVatRate(20))
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-042', new DateTime('2026-01-10')))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $this->assertChildOrder($xml, self::SETTLEMENT, [
            'CreditorReferenceID', 'PaymentReference', 'TaxCurrencyCode', 'InvoiceCurrencyCode',
            'PayeeTradeParty', 'SpecifiedTradeSettlementPaymentMeans', 'ApplicableTradeTax',
            'BillingSpecifiedPeriod', 'SpecifiedTradeAllowanceCharge', 'SpecifiedTradePaymentTerms',
            'SpecifiedTradeSettlementHeaderMonetarySummation', 'InvoiceReferencedDocument',
            'ReceivableSpecifiedTradeAccountingAccount'
        ]);
        $this->assertValidFacturX($xml->asXML());
    }

    public function testCreditorReferenceIdIsFirstSettlementChild(): void {
        $mandate = (new \Einvoicing\Payments\Mandate)->setCreditorIdentifier('FR12ZZZ123456')->setAccount('FR761111');
        $invoice = $this->getInvoice()
            ->addPayment((new Payment)->setId('PAY-1')->setMeansCode('59')->setMandate($mandate))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $this->assertSame('FR12ZZZ123456', $this->text($xml, self::SETTLEMENT . '/ram:CreditorReferenceID'));
        $this->assertSame(
            'FR761111',
            $this->text($xml, self::SETTLEMENT . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayerPartyDebtorFinancialAccount/ram:IBANID')
        );
        $this->assertChildOrder($xml, self::SETTLEMENT, [
            'CreditorReferenceID', 'PaymentReference', 'InvoiceCurrencyCode',
            'SpecifiedTradeSettlementPaymentMeans', 'ApplicableTradeTax',
            'SpecifiedTradeSettlementHeaderMonetarySummation'
        ]);
        $this->assertValidFacturX($xml->asXML());
    }

    public function testMonetarySummationChildOrder(): void {
        $invoice = $this->getInvoice()
            ->setPaidAmount(20)
            ->setRoundingAmount(0.01)
            ->addAllowance((new AllowanceOrCharge)->setAmount(10)->setVatCategory('S')->setVatRate(20))
            ->addCharge((new AllowanceOrCharge)->setAmount(5)->setVatCategory('S')->setVatRate(20))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $this->assertChildOrder($xml, self::SUMMATION, [
            'LineTotalAmount', 'ChargeTotalAmount', 'AllowanceTotalAmount', 'TaxBasisTotalAmount',
            'TaxTotalAmount', 'RoundingAmount', 'GrandTotalAmount', 'TotalPrepaidAmount',
            'DuePayableAmount'
        ]);

        // Figures come straight from InvoiceTotals
        $totals = $invoice->getTotals();
        $this->assertSame('95.00', $this->text($xml, self::SUMMATION . '/ram:TaxBasisTotalAmount'));
        $this->assertSame('114.00', $this->text($xml, self::SUMMATION . '/ram:GrandTotalAmount'));
        $this->assertSame('94.01', $this->text($xml, self::SUMMATION . '/ram:DuePayableAmount'));
        $this->assertSame($totals->payableAmount, 94.01);
        $this->assertValidFacturX($xml->asXML());
    }

    public function testAgreementChildOrder(): void {
        $invoice = $this->getInvoice()
            ->setPurchaseOrderReference('PO-1')
            ->setSalesOrderReference('SO-1')
            ->setContractReference('CT-1')
            ->setInvoicedObjectIdentifier(new Identifier('OBJ-1', 'AWV'))
            ->setTaxRepresentative((new Party)->setName('Tax Rep')->setVatNumber('FR99988877766')->setCountry('FR'))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $agreement = self::TX . '/ram:ApplicableHeaderTradeAgreement';

        // BT-14 comes before BT-13
        $this->assertChildOrder($xml, $agreement, [
            'BuyerReference', 'SellerTradeParty', 'BuyerTradeParty', 'SellerTaxRepresentativeTradeParty',
            'SellerOrderReferencedDocument', 'BuyerOrderReferencedDocument', 'ContractReferencedDocument',
            'AdditionalReferencedDocument'
        ]);
        $this->assertSame('SO-1', $this->text($xml, $agreement . '/ram:SellerOrderReferencedDocument/ram:IssuerAssignedID'));
        $this->assertSame('PO-1', $this->text($xml, $agreement . '/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'));
        $this->assertSame('Tax Rep', $this->text($xml, $agreement . '/ram:SellerTaxRepresentativeTradeParty/ram:Name'));
        $this->assertSame('130', $this->text($xml, $agreement . '/ram:AdditionalReferencedDocument/ram:TypeCode'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testPartyChildOrder(): void {
        $seller = (new Party)
            ->addIdentifier(new Identifier('LEGACY-ID'))
            ->addIdentifier(new Identifier('GLOBAL-ID', '0088'))
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setTradingName('Seller')
            ->setContactName('Jane Doe')
            ->setContactPhone('+33123456789')
            ->setContactEmail('jane@example.test')
            ->setElectronicAddress(new Identifier('seller@example.test', 'EM'))
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setCountry('FR');

        $invoice = $this->getInvoice()
            ->setSeller($seller)
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $party = self::TX . '/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';

        $this->assertChildOrder($xml, $party, [
            'ID', 'GlobalID', 'Name', 'SpecifiedLegalOrganization', 'DefinedTradeContact',
            'PostalTradeAddress', 'URIUniversalCommunication', 'SpecifiedTaxRegistration'
        ]);

        // BT-29 identifiers, with the company identifier no longer duplicated
        $this->assertSame('LEGACY-ID', $this->text($xml, $party . '/ram:ID'));
        $globalIds = $xml->getAll($party . '/ram:GlobalID');
        $this->assertCount(1, $globalIds);
        $this->assertSame('GLOBAL-ID', $globalIds[0]->asText());
        $this->assertSame('123456789', $this->text($xml, $party . '/ram:SpecifiedLegalOrganization/ram:ID'));
        $this->assertSame('Seller', $this->text($xml, $party . '/ram:SpecifiedLegalOrganization/ram:TradingBusinessName'));
        $this->assertSame('Jane Doe', $this->text($xml, $party . '/ram:DefinedTradeContact/ram:PersonName'));
        $this->assertSame('+33123456789', $this->text($xml, $party . '/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber'));
        $this->assertSame('jane@example.test', $this->text($xml, $party . '/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testLineChildOrder(): void {
        $line = (new InvoiceLine)
            ->setName('Line')
            ->setPrice(90)
            ->setGrossPrice(100)
            ->setQuantity(1)
            ->setVatCategory('S')
            ->setVatRate(20)
            ->setOrderLineReference('OL-1')
            ->setBuyerAccountingReference('L-ACC')
            ->setPeriodStartDate(new DateTime('2026-01-01'))
            ->setPeriodEndDate(new DateTime('2026-01-31'))
            ->addAllowance((new AllowanceOrCharge)->setReason('Line discount')->setAmount(5));

        $invoice = $this->getInvoice()->addLine($line);
        $xml = $this->export($invoice);

        $this->assertChildOrder($xml, self::LINE . '/ram:SpecifiedLineTradeAgreement', [
            'BuyerOrderReferencedDocument', 'GrossPriceProductTradePrice', 'NetPriceProductTradePrice'
        ]);
        $this->assertChildOrder($xml, self::LINE . '/ram:SpecifiedLineTradeSettlement', [
            'ApplicableTradeTax', 'BillingSpecifiedPeriod', 'SpecifiedTradeAllowanceCharge',
            'SpecifiedTradeSettlementLineMonetarySummation', 'ReceivableSpecifiedTradeAccountingAccount'
        ]);

        // BT-148 and the derived BT-147
        $gross = $xml->get(self::LINE . '/ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice');
        $this->assertSame('100', $gross->get('ram:ChargeAmount')->asText());
        $this->assertSame('10.00', $gross->get('ram:AppliedTradeAllowanceCharge/ram:ActualAmount')->asText());
        $this->assertSame('false', $gross->get('ram:AppliedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator')->asText());
        $this->assertValidFacturX($xml->asXML());
    }

    public function testNoGrossPriceElementWhenUnknown(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);

        $this->assertNull($xml->get(self::LINE . '/ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice'));
        $this->assertValidFacturX($xml->asXML());
    }

    /* ================= OTHER FIXES ================= */

    public function testNoDeliveryDateFabricated(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $delivery = $xml->get(self::TX . '/ram:ApplicableHeaderTradeDelivery');

        $this->assertNotNull($delivery);
        $this->assertTrue($delivery->isEmpty());
        $this->assertValidFacturX($xml->asXML());
    }

    public function testDeliveryDateAndDespatchAdviceAreWritten(): void {
        $invoice = $this->getInvoice()
            ->setDelivery((new Delivery)->setDate(new DateTime('2026-01-10')))
            ->setDespatchAdviceReference('DESP-1')
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $delivery = self::TX . '/ram:ApplicableHeaderTradeDelivery';

        $this->assertSame('20260110', $this->text($xml, $delivery . '/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'));
        $this->assertSame('DESP-1', $this->text($xml, $delivery . '/ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID'));
        $this->assertValidFacturX($xml->asXML());
    }

    public function testPartyWithoutSiren(): void {
        $invoice = $this->getInvoice()
            ->setSeller((new Party)->setName('Foreign Seller GmbH')->setCity('Berlin')->setCountry('DE'))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $party = self::TX . '/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';

        $this->assertSame('Foreign Seller GmbH', $this->text($xml, $party . '/ram:Name'));
        $this->assertNull($xml->get($party . '/ram:SpecifiedLegalOrganization'));
        $this->assertValidFacturX($xml->asXML());
    }

    /**
     * One payment means per credit transfer, as UN/CEFACT D22B allows (0..n).
     * Not validated against Factur-X EN 16931: that profile caps
     * SpecifiedTradeSettlementPaymentMeans and PayeePartyCreditorFinancialAccount
     * at one occurrence each, so it cannot carry more than one credit transfer.
     */
    public function testMultipleTransfersProduceMultiplePaymentMeans(): void {
        $invoice = $this->getInvoice()
            ->addPayment((new Payment)
                ->setMeansCode('58')
                ->addTransfer((new Transfer)->setAccountId('FR7612345678901234567890123'))
                ->addTransfer((new Transfer)->setAccountId('FR7698765432109876543210987')))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $means = $xml->getAll(self::SETTLEMENT . '/ram:SpecifiedTradeSettlementPaymentMeans');

        $this->assertCount(2, $means);
        $this->assertSame('FR7612345678901234567890123', $means[0]->get('ram:PayeePartyCreditorFinancialAccount/ram:IBANID')->asText());
        $this->assertSame('FR7698765432109876543210987', $means[1]->get('ram:PayeePartyCreditorFinancialAccount/ram:IBANID')->asText());
        $this->assertSame('58', $means[1]->get('ram:TypeCode')->asText());
    }

    public function testTaxPointDateAndVatPointDateCode(): void {
        $invoice = $this->getInvoice()
            ->setTaxPointDate(new DateTime('2026-01-20'))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $tax = $xml->get(self::SETTLEMENT . '/ram:ApplicableTradeTax');

        $this->assertSame('20260120', $tax->get('ram:TaxPointDate/udt:DateString')->asText());
        $this->assertSame('102', $tax->get('ram:TaxPointDate/udt:DateString')->element()->getAttribute('format'));
        $this->assertValidFacturX($xml->asXML());

        $invoice->setTaxPointDate(null)->setVatPointDateCode('72');
        $xml = $this->export($invoice);
        $tax = $xml->get(self::SETTLEMENT . '/ram:ApplicableTradeTax');

        $this->assertNull($tax->get('ram:TaxPointDate'));
        $this->assertSame('72', $tax->get('ram:DueDateTypeCode')->asText());
        $this->assertValidFacturX($xml->asXML());
    }

    public function testHeaderBillingPeriodIsWritten(): void {
        $invoice = $this->getInvoice()
            ->setPeriodStartDate(new DateTime('2026-01-01'))
            ->setPeriodEndDate(new DateTime('2026-01-31'))
            ->addLine((new InvoiceLine)->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = $this->export($invoice);
        $period = self::SETTLEMENT . '/ram:BillingSpecifiedPeriod';

        $this->assertSame('20260101', $this->text($xml, $period . '/ram:StartDateTime/udt:DateTimeString'));
        $this->assertSame('20260131', $this->text($xml, $period . '/ram:EndDateTime/udt:DateTimeString'));
        $this->assertValidFacturX($xml->asXML());
    }
}
