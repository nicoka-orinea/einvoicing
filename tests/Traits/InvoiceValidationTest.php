<?php
namespace Tests\Traits;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Delivery;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Payments\Payment;
use PHPUnit\Framework\TestCase;

/**
 * EN 16931 validation rules: one test per family, each pinning the rule
 * identifier that must be reported.
 */
final class InvoiceValidationTest extends TestCase {
    private function getValidInvoice(): Invoice {
        $seller = (new Party)
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setName('Buyer Name Ltd.')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = (new Invoice)->setRoundingMatrix(['' => 2]);
        return $invoice
            ->setSpecification('urn:cen.eu:en16931:2017')
            ->setNumber('INV-001')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setDueDate(new DateTime('2026-02-15'))
            ->setCurrency('EUR')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setVatCategory('S')
                ->setVatRate(20));
    }

    private function assertFailsRule(string $expectedRuleId, Invoice $invoice): void {
        try {
            $invoice->validate();
        } catch (ValidationException $e) {
            $this->assertSame(
                $expectedRuleId,
                $e->getBusinessRuleId(),
                "Reported: {$e->getBusinessRuleId()} - {$e->getMessage()}"
            );
            return;
        }
        $this->fail("Expected validation to fail with $expectedRuleId");
    }

    public function testNominalInvoicePasses(): void {
        $this->getValidInvoice()->validate();
        $this->assertTrue(true);
    }

    /* ================= VAT CATEGORY RULES ================= */

    public function testExemptLineWithoutReasonFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatCategory('E')->setVatRate(0);

        $this->assertFailsRule('BR-E-10', $invoice);
    }

    public function testExemptLineWithReasonPasses(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatCategory('E')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-132-1P');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testStandardRatedLineWithoutRateFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatRate(null);

        $this->assertFailsRule('BR-S-5', $invoice);
    }

    public function testStandardRatedLineWithZeroRateFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatRate(0);

        $this->assertFailsRule('BR-S-5', $invoice);
    }

    public function testStandardRatedAllowanceWithoutRateFails(): void {
        $invoice = $this->getValidInvoice()
            ->addAllowance((new AllowanceOrCharge)->setReason('Discount')->setAmount(5)->setVatCategory('S'));

        $this->assertFailsRule('BR-S-6', $invoice);
    }

    public function testStandardRatedChargeWithoutRateFails(): void {
        $invoice = $this->getValidInvoice()
            ->addCharge((new AllowanceOrCharge)->setReason('Shipping')->setAmount(5)->setVatCategory('S'));

        $this->assertFailsRule('BR-S-7', $invoice);
    }

    public function testStandardRatedBreakdownWithExemptionReasonFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatExemptionReason('Should not be here');

        $this->assertFailsRule('BR-S-10', $invoice);
    }

    public function testZeroRatedLineWithNonZeroRateFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatCategory('Z')->setVatRate(20);

        $this->assertFailsRule('BR-Z-5', $invoice);
    }

    public function testCategoryOLineWithRateFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->setSeller((new Party)->setName('Seller')->setCity('Paris')->setCountry('FR')->setCompanyId(new Identifier('123456789', '0002')));
        $invoice->getLines()[0]->setVatCategory('O')->setVatRate(0)->setVatExemptionReason('Hors champ');

        $this->assertFailsRule('BR-O-5', $invoice);
    }

    public function testCategoryOWithSellerVatNumberFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatCategory('O')->setVatRate(null)->setVatExemptionReason('Hors champ');

        $this->assertFailsRule('BR-O-2', $invoice);
    }

    public function testCategoryOMixedWithStandardRatedFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->setSeller((new Party)->setName('Seller')->setCity('Paris')->setCountry('FR')->setCompanyId(new Identifier('123456789', '0002')));
        $invoice->getLines()[0]->setVatCategory('O')->setVatRate(null)->setVatExemptionReason('Hors champ');
        $invoice->addLine((new InvoiceLine)->setName('L2')->setPrice(50)->setQuantity(1)->setVatCategory('S')->setVatRate(20));

        // A standard rated line without a seller VAT identifier trips BR-S-2 first
        $this->assertFailsRule('BR-S-2', $invoice);
    }

    public function testCategoryOAloneWithoutVatIdentifiersPasses(): void {
        $invoice = $this->getValidInvoice();
        $invoice->setSeller((new Party)->setName('Seller')->setCity('Paris')->setCountry('FR')->setCompanyId(new Identifier('123456789', '0002')));
        $invoice->getLines()[0]->setVatCategory('O')->setVatRate(null)->setVatExemptionReason('Hors champ TVA');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testReverseChargeWithoutBuyerVatFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setVatCategory('AE')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-AE');

        $this->assertFailsRule('BR-AE-2', $invoice);
    }

    public function testReverseChargeWithBuyerVatPasses(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getBuyer()->setVatNumber('FR98765432109');
        $invoice->getLines()[0]->setVatCategory('AE')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-AE');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testIntraCommunitySupplyWithoutBuyerVatFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getBuyer()->setCompanyId(new Identifier('987654321', '0002'));
        $invoice->getLines()[0]->setVatCategory('K')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-IC');

        // Unlike BR-AE-2, BR-IC-2 does not accept the buyer legal registration identifier
        $this->assertFailsRule('BR-IC-2', $invoice);
    }

    public function testSellerTaxRegistrationSatisfiesStandardRated(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getSeller()->setVatNumber(null)->setTaxRegistrationId(new Identifier('12345678', 'GST'));

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testTaxRepresentativeVatSatisfiesStandardRated(): void {
        $invoice = $this->getValidInvoice()
            ->setTaxRepresentative((new Party)->setName('Rep')->setCountry('FR')->setVatNumber('FR99988877766'));
        $invoice->getSeller()->setVatNumber(null);

        $invoice->validate();
        $this->assertTrue(true);
    }

    /* ================= COHERENCE RULES ================= */

    public function testTaxPointDateAndCodeAreMutuallyExclusive(): void {
        $invoice = $this->getValidInvoice()
            ->setTaxPointDate(new DateTime('2026-01-15'))
            ->setVatPointDateCode('72');

        $this->assertFailsRule('BR-CO-3', $invoice);
    }

    public function testPositivePayableAmountRequiresDueDateOrTerms(): void {
        $invoice = $this->getValidInvoice()->setDueDate(null);

        $this->assertFailsRule('BR-CO-25', $invoice);
    }

    public function testPositivePayableAmountAcceptsPaymentTerms(): void {
        $invoice = $this->getValidInvoice()->setDueDate(null)->setPaymentTerms('30 days net');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testSellerNeedsAnIdentifier(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getSeller()->setCompanyId(null)->setVatNumber(null)->setTaxRegistrationId(new Identifier('1', 'GST'));

        $this->assertFailsRule('BR-CO-26', $invoice);
    }

    public function testInvoicingPeriodStartMustNotFollowEnd(): void {
        $invoice = $this->getValidInvoice()
            ->setPeriodStartDate(new DateTime('2026-02-01'))
            ->setPeriodEndDate(new DateTime('2026-01-01'));

        $this->assertFailsRule('BR-CO-19', $invoice);
    }

    public function testLinePeriodStartMustNotFollowEnd(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]
            ->setPeriodStartDate(new DateTime('2026-02-01'))
            ->setPeriodEndDate(new DateTime('2026-01-01'));

        $this->assertFailsRule('BR-CO-20', $invoice);
    }

    /* ================= OTHER RULES ================= */

    public function testPaidAmountWithThreeDecimalsFails(): void {
        $invoice = $this->getValidInvoice()->setRoundingMatrix([])->setPaidAmount(10.123);

        $this->assertFailsRule('BR-DEC-16', $invoice);
    }

    public function testAmountRoundedByTheMatrixDoesNotBreachBrDec(): void {
        // The matrix keeps 2 decimals, so the written amount stays valid
        $invoice = $this->getValidInvoice()
            ->addCharge((new AllowanceOrCharge)->setReason('Shipping')->setAmount(10.1234)->setVatCategory('S')->setVatRate(20));

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testPaymentWithoutMeansCodeFailsEvenWithPaymentTerms(): void {
        $invoice = $this->getValidInvoice()
            ->setPaymentTerms('30 days net')
            ->addPayment(new Payment());

        $this->assertFailsRule('BR-49', $invoice);
    }

    public function testGrossPriceBelowNetPriceFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setGrossPrice(90);

        $this->assertFailsRule('BR-28', $invoice);
    }

    public function testNegativeGrossPriceFails(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getLines()[0]->setPrice(-10)->setGrossPrice(-5);

        $this->assertFailsRule('BR-27', $invoice);
    }

    public function testTaxRepresentativeNeedsAName(): void {
        $invoice = $this->getValidInvoice()
            ->setTaxRepresentative((new Party)->setCountry('FR')->setVatNumber('FR99988877766'));

        $this->assertFailsRule('BR-18', $invoice);
    }

    public function testTaxRepresentativeNeedsACountry(): void {
        $invoice = $this->getValidInvoice()
            ->setTaxRepresentative((new Party)->setName('Rep')->setVatNumber('FR99988877766'));

        $this->assertFailsRule('BR-20', $invoice);
    }

    public function testDeliveryAddressNeedsACountry(): void {
        $invoice = $this->getValidInvoice()
            ->setDelivery((new Delivery)->setDate(new DateTime('2026-01-10'))->setCity('Lille'));

        $this->assertFailsRule('BR-57', $invoice);
    }

    public function testElectronicAddressNeedsAScheme(): void {
        $invoice = $this->getValidInvoice();
        $invoice->getSeller()->setElectronicAddress(new Identifier('seller@example.test'));

        $this->assertFailsRule('BR-62', $invoice);
    }

    public function testDocumentAllowanceNeedsAVatCategory(): void {
        $invoice = $this->getValidInvoice()
            ->addAllowance((new AllowanceOrCharge)->setReason('Discount')->setAmount(5)->setVatCategory('')->setVatRate(20));

        $this->assertFailsRule('BR-32', $invoice);
    }

    /* ================= ROUNDING ================= */

    public function testLineNetAmountsAreRoundedBeforeBeingSummed(): void {
        $invoice = (new Invoice)->setRoundingMatrix(['' => 2]);
        $invoice->setSpecification('urn:cen.eu:en16931:2017')
            ->setNumber('INV-002')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setDueDate(new DateTime('2026-02-15'))
            ->setSeller((new Party)->setName('S')->setCountry('FR')->setVatNumber('FR1')->setCompanyId(new Identifier('123456789', '0002')))
            ->setBuyer((new Party)->setName('B')->setCountry('FR'));
        for ($i = 0; $i < 3; $i++) {
            $invoice->addLine((new InvoiceLine)->setName("L$i")->setPrice(10.005)->setQuantity(1)->setVatCategory('S')->setVatRate(20));
        }

        // Each BT-131 is rounded to 10.01, so BT-106 is 30.03 and not 30.02 (BR-CO-10)
        $this->assertEquals(30.03, $invoice->getTotals()->netAmount);
        $invoice->validate();
    }
}
