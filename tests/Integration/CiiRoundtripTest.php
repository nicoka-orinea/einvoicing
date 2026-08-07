<?php
namespace Tests\Integration;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Delivery;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Party;
use Einvoicing\Payments\Mandate;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\Presets\Peppol;
use Einvoicing\Readers\CiiReader;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;

/**
 * CiiReader must be the exact mirror of CiiWriter: exporting then importing an
 * invoice has to preserve both the figures and the business term mapping.
 */
final class CiiRoundtripTest extends TestCase {
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
            ->setNumber('INV-RT-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer);
    }

    private function roundtrip(Invoice $invoice): Invoice {
        $imported = (new CiiReader())->import((new CiiWriter())->export($invoice));
        return $imported->setRoundingMatrix(['' => 2]);
    }

    public function testAmountRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20))
            ->addLine((new InvoiceLine)->setName('L2')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(5.5))
            ->addAllowance((new AllowanceOrCharge)->setAmount(10)->markAsPercentage()->setVatCategory('S')->setVatRate(20))
            ->addCharge((new AllowanceOrCharge)->setAmount(5)->setVatCategory('S')->setVatRate(20));

        $before = $invoice->getTotals();
        $after = $this->roundtrip($invoice)->getTotals();

        foreach ([
            'netAmount', 'allowancesAmount', 'chargesAmount', 'vatAmount',
            'taxExclusiveAmount', 'taxInclusiveAmount', 'payableAmount'
        ] as $field) {
            $this->assertLessThanOrEqual(
                0.005,
                abs($before->$field - $after->$field),
                "$field diverged: {$before->$field} vs {$after->$field}"
            );
        }
    }

    public function testBaseQuantityRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100, 2)->setQuantity(2)->setVatCategory('S')->setVatRate(20));

        $line = $this->roundtrip($invoice)->getLines()[0];

        $this->assertEquals(100.0, $line->getPrice());
        $this->assertEquals(2.0, $line->getBaseQuantity());
    }

    public function testFractionalBaseQuantityRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(10, 0.5)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $line = $this->roundtrip($invoice)->getLines()[0];

        $this->assertEquals(10.0, $line->getPrice());
        $this->assertEquals(0.5, $line->getBaseQuantity());
        $this->assertEqualsWithDelta(20.0, $this->roundtrip($invoice)->getTotals()->netAmount, 0.005);
    }

    public function testCreditNoteReferencesRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-042', new DateTime('2026-01-10')))
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-043'))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $references = $this->roundtrip($invoice)->getPrecedingInvoiceReferences();

        $this->assertCount(2, $references);
        $this->assertSame('INV-042', $references[0]->getValue());
        $this->assertSame('2026-01-10', $references[0]->getIssueDate()?->format('Y-m-d'));
        $this->assertSame('INV-043', $references[1]->getValue());
        $this->assertNull($references[1]->getIssueDate());
    }

    public function testBusinessProcessPreservedWithPreset(): void {
        $specification = (new Peppol)->getSpecification();
        $invoice = $this->getInvoice()
            ->setSpecification($specification)
            ->setBusinessProcess('B1')
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $imported = (new CiiReader())->import((new CiiWriter())->export($invoice));

        // The preset is applied, but the document values are not overwritten
        $this->assertSame('B1', $imported->getBusinessProcess());
        $this->assertSame($specification, $imported->getSpecification());
    }

    public function testCompanyIdRoundtrip(): void {
        $seller = (new Party)
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->addIdentifier(new Identifier('12345678901234', '0009'))
            ->setName('Seller Name Ltd.')
            ->setCountry('FR');

        $invoice = $this->getInvoice()
            ->setSeller($seller)
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $imported = $this->roundtrip($invoice)->getSeller();

        // BT-30 stays the company identifier, BT-29 stays a party identifier
        $this->assertSame('123456789', $imported->getCompanyId()?->getValue());
        $this->assertSame('0002', $imported->getCompanyId()?->getScheme());
        $this->assertCount(1, $imported->getIdentifiers());
        $this->assertSame('12345678901234', $imported->getIdentifiers()[0]->getValue());
        $this->assertSame('0009', $imported->getIdentifiers()[0]->getScheme());
    }

    public function testHeaderAllowancePercentageRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20))
            ->addAllowance((new AllowanceOrCharge)->setReason('Discount')->setAmount(10)->markAsPercentage()->setVatCategory('S')->setVatRate(20));

        $imported = $this->roundtrip($invoice);
        $allowances = $imported->getAllowances();

        $this->assertCount(1, $allowances);
        $this->assertTrue($allowances[0]->isPercentage());
        $this->assertEquals(10.0, $allowances[0]->getAmount());
        $this->assertSame('Discount', $allowances[0]->getReason());
        $this->assertEqualsWithDelta(10.0, $imported->getTotals()->allowancesAmount, 0.005);
    }

    public function testTaxPointDateRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->setTaxPointDate(new DateTime('2026-01-20'))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $this->assertSame('2026-01-20', $this->roundtrip($invoice)->getTaxPointDate()?->format('Y-m-d'));
    }

    public function testVatPointDateCodeRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->setVatPointDateCode('72')
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $imported = $this->roundtrip($invoice);

        $this->assertSame('72', $imported->getVatPointDateCode());
        $this->assertNull($imported->getTaxPointDate());
    }

    public function testMultiplePaymentMeansRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addPayment((new Payment)
                ->setId('PAY-1')
                ->setMeansCode('58')
                ->addTransfer((new Transfer)->setAccountId('FR7612345678901234567890123')->setAccountName('Main')->setProvider('AGRIFRPP'))
                ->addTransfer((new Transfer)->setAccountId('FR7698765432109876543210987')))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $payments = $this->roundtrip($invoice)->getPayments();

        // The writer emits one payment means per transfer, the reader mirrors it
        $this->assertCount(2, $payments);
        $this->assertSame('PAY-1', $payments[0]->getId());
        $this->assertSame('58', $payments[0]->getMeansCode());
        $this->assertSame('FR7612345678901234567890123', $payments[0]->getTransfers()[0]->getAccountId());
        $this->assertSame('Main', $payments[0]->getTransfers()[0]->getAccountName());
        $this->assertSame('AGRIFRPP', $payments[0]->getTransfers()[0]->getProvider());
        $this->assertSame('FR7698765432109876543210987', $payments[1]->getTransfers()[0]->getAccountId());
    }

    public function testCardAndMandateRoundtrip(): void {
        $mandate = (new Mandate)->setAccount('FR7611112222333344445555666')->setCreditorIdentifier('FR12ZZZ123456');
        $invoice = $this->getInvoice()
            ->addPayment((new Payment)
                ->setMeansCode('59')
                ->setMandate($mandate)
                ->setCard((new \Einvoicing\Payments\Card)->setPan('1234********5678')->setHolder('Jane Doe')))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $payment = $this->roundtrip($invoice)->getPayments()[0];

        $this->assertSame('1234********5678', $payment->getCard()?->getPan());
        $this->assertSame('Jane Doe', $payment->getCard()?->getHolder());
        $this->assertSame('FR7611112222333344445555666', $payment->getMandate()?->getAccount());
        $this->assertSame('FR12ZZZ123456', $payment->getMandate()?->getCreditorIdentifier());
    }

    public function testHeaderPeriodAndTaxRepresentativeRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->setPeriodStartDate(new DateTime('2026-01-01'))
            ->setPeriodEndDate(new DateTime('2026-01-31'))
            ->setTaxRepresentative((new Party)->setName('Tax Rep')->setVatNumber('FR99988877766')->setCountry('FR'))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $imported = $this->roundtrip($invoice);

        $this->assertSame('2026-01-01', $imported->getPeriodStartDate()?->format('Y-m-d'));
        $this->assertSame('2026-01-31', $imported->getPeriodEndDate()?->format('Y-m-d'));
        $this->assertSame('Tax Rep', $imported->getTaxRepresentative()?->getName());
        $this->assertSame('FR99988877766', $imported->getTaxRepresentative()?->getVatNumber());
        $this->assertSame('FR', $imported->getTaxRepresentative()?->getCountry());
    }

    public function testDeliveryAndReferencesRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->setDelivery((new Delivery)->setDate(new DateTime('2026-01-10')))
            ->setDespatchAdviceReference('DESP-1')
            ->setInvoicedObjectIdentifier(new Identifier('OBJ-1', 'AWV'))
            ->setPayee((new Party)->setName('Payee Ltd.')->setCountry('FR'))
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $imported = $this->roundtrip($invoice);

        $this->assertSame('2026-01-10', $imported->getDelivery()?->getDate()?->format('Y-m-d'));
        $this->assertSame('DESP-1', $imported->getDespatchAdviceReference());
        $this->assertSame('OBJ-1', $imported->getInvoicedObjectIdentifier()?->getValue());
        $this->assertSame('AWV', $imported->getInvoicedObjectIdentifier()?->getScheme());
        $this->assertSame('Payee Ltd.', $imported->getPayee()?->getName());
    }

    public function testNoDeliveryDateIsNotInvented(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $this->assertNull($this->roundtrip($invoice)->getDelivery()?->getDate());
    }

    public function testGrossPriceRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)->setName('L1')->setPrice(90)->setGrossPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        $line = $this->roundtrip($invoice)->getLines()[0];

        $this->assertEquals(100.0, $line->getGrossPrice());
        $this->assertEquals(90.0, $line->getPrice());
    }

    public function testExemptionReasonRoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)
                ->setName('L1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('E')
                ->setVatRate(0)
                ->setVatExemptionReason('Exonération article 262 ter')
                ->setVatExemptionReasonCode('VATEX-EU-IC'));

        $line = $this->roundtrip($invoice)->getLines()[0];

        $this->assertSame('Exonération article 262 ter', $line->getVatExemptionReason());
        $this->assertSame('VATEX-EU-IC', $line->getVatExemptionReasonCode());
    }

    public function testCategoryORoundtrip(): void {
        $invoice = $this->getInvoice()
            ->addLine((new InvoiceLine)
                ->setName('L1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('O')
                ->setVatRate(null)
                ->setVatExemptionReason('Hors champ TVA'));

        $imported = $this->roundtrip($invoice);
        $line = $imported->getLines()[0];

        $this->assertSame('O', $line->getVatCategory());
        $this->assertNull($line->getVatRate());
        $this->assertSame('Hors champ TVA', $line->getVatExemptionReason());
        $this->assertEqualsWithDelta(0.0, $imported->getTotals()->vatAmount, 0.005);
        $this->assertEqualsWithDelta(100.0, $imported->getTotals()->taxInclusiveAmount, 0.005);
    }
}
