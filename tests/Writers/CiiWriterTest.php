<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\Party;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;
use UXML\UXML;

final class CiiWriterTest extends TestCase {
    public function testCanExportInvoiceWithoutOptionalPartyIdentifiers(): void {
        // The legal organization identifier (0002) is the only mandatory party identifier
        $seller = (new Party)
            ->setName('Seller Name Ltd.')
            ->setCompanyId(new Identifier('518090733', '0002'))
            ->setAddress(['Fake Street 123'])
            ->setCity('Springfield')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setName('Buyer Name Ltd.')
            ->setCompanyId(new Identifier('850966391', '0002'))
            ->setAddress(['Main Avenue 12'])
            ->setCity('Paris')
            ->setCountry('FR');

        $invoice = (new Invoice)
            ->setNumber('INV-001')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('S')
                ->setVatRate(20));

        $writer = new CiiWriter();
        $xml = UXML::fromString($writer->export($invoice));

        $this->assertEquals('Seller Name Ltd.', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name')?->asText());
        $this->assertEquals('Buyer Name Ltd.', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name')?->asText());

        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:URIUniversalCommunication'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineTwo'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineThree'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedTradeProduct/ram:BuyerAssignedID'));
        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedTradeProduct/ram:Description'));
    }

    public function testDoesNotWriteBankTransferWithoutBeneficiaryAccount(): void {
        $invoice = (new Invoice)
            ->setNumber('INV-002')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller((new Party)->setName('Seller')->setCountry('FR')->setCompanyId(new Identifier('518090733', '0002')))
            ->setBuyer((new Party)->setName('Buyer')->setCountry('FR')->setCompanyId(new Identifier('850966391', '0002')))
            ->addPayment((new Payment())->setMeansCode('58')->addTransfer((new Transfer())->setAccountId(' ')))
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('S')
                ->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans'));
    }

    private function coherenceInvoice(): Invoice {
        return (new Invoice)
            ->setNumber('INV-COH')
            ->setIssueDate(new DateTime('2026-09-01'))
            ->setCurrency('EUR')
            ->setSeller((new Party)->setName('Seller')->setCountry('FR')->setCompanyId(new Identifier('518090733', '0002')))
            ->setBuyer((new Party)->setName('Buyer')->setCountry('FR')->setCompanyId(new Identifier('850966391', '0002')));
    }

    public function testSumsHeaderTotalsFromRoundedLineNets(): void {
        // BR-CO-10: Σ BT-131 as written must equal BT-106, even when every line falls on a half cent
        $invoice = $this->coherenceInvoice();
        foreach ([1, 2, 3] as $id) {
            $invoice->addLine((new InvoiceLine)->setId((string) $id)->setName("Line $id")->setPrice(10.005)->setQuantity(1)->setVatRate(20));
        }

        $xml = UXML::fromString((new CiiWriter())->export($invoice));
        $lineTotals = array_map(
            fn ($node) => (float) $node->asText(),
            $xml->getAll('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount')
        );
        $summation = $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation');

        $this->assertSame([10.01, 10.01, 10.01], $lineTotals);
        $this->assertSame('30.03', $summation->get('ram:LineTotalAmount')->asText());
        $this->assertSame('30.03', $summation->get('ram:TaxBasisTotalAmount')->asText());
        $this->assertSame('30.03', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:BasisAmount')->asText());
        $this->assertSame('6.01', $summation->get('ram:TaxTotalAmount')->asText());
        $this->assertSame('36.04', $summation->get('ram:GrandTotalAmount')->asText());
    }

    public function testKeepsUnitPriceDecimalsSoThatPriceTimesQuantityMatchesLineTotal(): void {
        $invoice = $this->coherenceInvoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('Line')->setPrice(33.3333)->setQuantity(3)->setVatRate(20))
            ->addLine((new InvoiceLine)->setId('2')->setName('Line')->setPrice(130)->setQuantity(1)->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));
        $prices = array_map(
            fn ($node) => $node->asText(),
            $xml->getAll('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount')
        );
        $lineTotals = array_map(
            fn ($node) => $node->asText(),
            $xml->getAll('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount')
        );

        $this->assertSame(['33.3333', '130.00'], $prices);
        $this->assertSame(['100.00', '130.00'], $lineTotals);
    }

    public function testWritesExemptionReasonForZeroRatedCategories(): void {
        $invoice = $this->coherenceInvoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('Export')->setPrice(100)->setQuantity(1)
                ->setVatCategory('G')->setVatRate(0)->setVatExemptionReasonCode('VATEX-EU-G')->setVatExemptionReason('Export outside the EU'))
            ->addLine((new InvoiceLine)->setId('2')->setName('Not subject')->setPrice(50)->setQuantity(1)
                ->setVatCategory('O')->setVatRate(null)->setVatExemptionReason('Not subject to VAT'));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));
        $taxes = $xml->getAll('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');

        $this->assertCount(2, $taxes);
        $byCategory = [];
        foreach ($taxes as $tax) {
            $byCategory[$tax->get('ram:CategoryCode')->asText()] = $tax;
        }
        $this->assertSame('Export outside the EU', $byCategory['G']->get('ram:ExemptionReason')->asText());
        $this->assertSame('VATEX-EU-G', $byCategory['G']->get('ram:ExemptionReasonCode')->asText());
        $this->assertSame('0', $byCategory['G']->get('ram:RateApplicablePercent')->asText());
        $this->assertSame('0.00', $byCategory['G']->get('ram:CalculatedAmount')->asText());
        $this->assertSame('Not subject to VAT', $byCategory['O']->get('ram:ExemptionReason')->asText());
        $this->assertNull($byCategory['O']->get('ram:RateApplicablePercent'));
        // XSD order inside ApplicableTradeTax
        $children = array_map(fn ($n) => $n->element()->localName, $byCategory['G']->getAll('*'));
        $this->assertSame(['CalculatedAmount', 'TypeCode', 'ExemptionReason', 'BasisAmount', 'CategoryCode', 'ExemptionReasonCode', 'RateApplicablePercent'], $children);
    }

    public function testCanGenerateDocumentNotesWithSubjectCode(): void {
        $invoice = new Invoice();
        $invoice->setNumber('INV-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-03-30'))
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatRate(20))
            ->addNote('Late payment penalties', 'PMD')
            ->addNote('Recovery fees', 'PMT');

        $xml = UXML::fromString((new CiiWriter())->export($invoice));
        $notes = $xml->getAll('rsm:ExchangedDocument/ram:IncludedNote');

        $this->assertCount(2, $notes);
        $this->assertSame('Late payment penalties', $notes[0]->get('ram:Content')->asText());
        $this->assertSame('PMD', $notes[0]->get('ram:SubjectCode')->asText());
        $this->assertSame('Recovery fees', $notes[1]->get('ram:Content')->asText());
        $this->assertSame('PMT', $notes[1]->get('ram:SubjectCode')->asText());
    }

    public function testWritesBusinessTermsAndConsistentMonetarySummation(): void {
        $invoice = new Invoice();
        $invoice->setSpecification('urn:test:cii:profile')
            ->setBusinessProcess('urn:test:process')
            ->setNumber('INV-002')
            ->setBuyerReference('BR-1')
            ->setPurchaseOrderReference('PO-1')
            ->setSalesOrderReference('SO-1')
            ->setContractReference('CT-1')
            ->setIssueDate(new DateTime('2026-03-30'))
            ->setDueDate(new DateTime('2026-04-15'))
            ->setVatCurrency('EUR')
            ->setBuyerAccountingReference('ACC-1')
            ->setPaidAmount(20)
            ->setRoundingAmount(0.01)
            ->setSeller((new Party())->addIdentifier(new Identifier('LEGAL-SELLER', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setAddress(['Line 1', 'Line 2'])->setPostalCode('75001')->setCity('Paris')->setCountry('FR')->setVatNumber('FR123'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('LEGAL-BUYER', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setAddress(['Buyer line 1', 'Buyer line 2'])->setPostalCode('69001')->setCity('Lyon')->setCountry('FR')->setVatNumber('FR456'))
            ->addAllowance((new AllowanceOrCharge())->setAmount(10)->setVatCategory('S')->setVatRate(20))
            ->addCharge((new AllowanceOrCharge())->setAmount(5)->setVatCategory('S')->setVatRate(20))
            ->addPayment((new Payment())->setId('PAY-1')->setMeansCode('30')->setMeansText('Credit transfer')->addTransfer((new Transfer())->setAccountId('FR761234')->setAccountName('Main')->setProvider('AGRIFRPP')))
            ->addLine((new InvoiceLine())->setId('1')->setName('Line')->setDescription('Desc')->setSellerIdentifier('SELL-1')->setBuyerIdentifier('BUY-1')->setStandardIdentifier(new Identifier('1234567890123', '0160'))->setOriginCountry('FR')->setOrderLineReference('OL-1')->setBuyerAccountingReference('L-ACC')->setPrice(100, 2)->setQuantity(2)->setBaseQuantity(2)->setVatRate(20)->setUnit('C62')->setNote('Line note'));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $this->assertSame('urn:test:process', $xml->get('rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID')->asText());
        $this->assertSame('urn:test:cii:profile', $xml->get('rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID')->asText());
        $this->assertSame('BR-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerReference')->asText());
        $this->assertSame('PO-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID')->asText());
        $this->assertSame('SO-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerOrderReferencedDocument/ram:IssuerAssignedID')->asText());
        $this->assertSame('CT-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID')->asText());

        // XSD sequence of HeaderTradeAgreementType: Seller order ref (BT-14) must precede buyer order ref (BT-13)
        $this->assertSame([
            'BuyerReference',
            'SellerTradeParty',
            'BuyerTradeParty',
            'SellerOrderReferencedDocument',
            'BuyerOrderReferencedDocument',
            'ContractReferencedDocument',
        ], $this->childLocalNames($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement')));

        // XSD sequence of HeaderTradeSettlementType
        $this->assertSame([
            'PaymentReference',
            'TaxCurrencyCode',
            'InvoiceCurrencyCode',
            'SpecifiedTradeSettlementPaymentMeans',
            'ApplicableTradeTax',
            'SpecifiedTradeAllowanceCharge',
            'SpecifiedTradeAllowanceCharge',
            'SpecifiedTradePaymentTerms',
            'SpecifiedTradeSettlementHeaderMonetarySummation',
            'ReceivableSpecifiedTradeAccountingAccount',
        ], $this->childLocalNames($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement')));

        // XSD sequence of TradeSettlementHeaderMonetarySummationType
        $this->assertSame([
            'LineTotalAmount',
            'ChargeTotalAmount',
            'AllowanceTotalAmount',
            'TaxBasisTotalAmount',
            'TaxTotalAmount',
            'RoundingAmount',
            'GrandTotalAmount',
            'TotalPrepaidAmount',
            'DuePayableAmount',
        ], $this->childLocalNames($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation')));

        // XSD sequence of LineTradeAgreementType: BuyerOrderReferencedDocument (BT-132) first
        $this->assertSame([
            'BuyerOrderReferencedDocument',
            'GrossPriceProductTradePrice',
            'NetPriceProductTradePrice',
        ], $this->childLocalNames($xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeAgreement')));

        // XSD sequence of LineTradeSettlementType: accounting account after monetary summation
        $this->assertSame([
            'ApplicableTradeTax',
            'SpecifiedTradeSettlementLineMonetarySummation',
            'ReceivableSpecifiedTradeAccountingAccount',
        ], $this->childLocalNames($xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement')));
        $this->assertSame('2.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity')->asText());
        $this->assertSame('ACC-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID')->asText());
        $this->assertSame('PAY-1', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:PaymentReference')->asText());
        $this->assertSame('10.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount')->asText());
        $this->assertSame('5.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:ChargeTotalAmount')->asText());
        $this->assertSame('20.00', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount')->asText());
        $this->assertSame('0.01', $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:RoundingAmount')->asText());
    }

    public function testWritesPrecedingInvoiceReferenceForCreditNote(): void {
        $invoice = new Invoice();
        $invoice->setNumber('AV-001')
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setIssueDate(new DateTime('2026-05-20'))
            ->setCurrency('EUR')
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-042', new DateTime('2026-04-10')))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $ref = $xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument');
        $this->assertNotNull($ref, 'BR-FR-CO-05: an InvoiceReferencedDocument must be present for a credit note');
        $this->assertSame('INV-042', $ref->get('ram:IssuerAssignedID')->asText());
        $date = $ref->get('ram:FormattedIssueDateTime/qdt:DateTimeString');
        $this->assertSame('20260410', $date->asText());
        $this->assertSame('102', $date->element()->getAttribute('format'));
    }

    public function testDoesNotWritePrecedingInvoiceReferenceForRegularInvoice(): void {
        $invoice = new Invoice();
        $invoice->setNumber('INV-003')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-05-20'))
            ->setCurrency('EUR')
            ->setSeller((new Party())->addIdentifier(new Identifier('SELLER-001', '0002'))->setElectronicAddress(new Identifier('seller@example.test', 'EM'))->setCompanyId(new Identifier('SELLER-001', '0002'))->setName('Seller')->setCountry('FR'))
            ->setBuyer((new Party())->addIdentifier(new Identifier('BUYER-001', '0002'))->setElectronicAddress(new Identifier('buyer@example.test', 'EM'))->setCompanyId(new Identifier('BUYER-001', '0002'))->setName('Buyer')->setCountry('FR'))
            ->addLine((new InvoiceLine())->setName('Line')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $xml = UXML::fromString((new CiiWriter())->export($invoice));

        $this->assertNull($xml->get('rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument'));
    }

    /** @return string[] element children local names, in document order */
    private function childLocalNames(UXML $node): array {
        $names = [];
        foreach ($node->element()->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $names[] = $child->localName;
            }
        }
        return $names;
    }
}
