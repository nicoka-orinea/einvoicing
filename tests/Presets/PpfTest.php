<?php
namespace Tests\Presets;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Party;
use Einvoicing\Presets\Ppf;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;

/**
 * French PPF preset: rules G x.xx of "Spécifications externes FE v3.2",
 * Annexe 7 v1.9.
 */
final class PpfTest extends TestCase {
    use ValidatesAgainstXsd;

    private function getFrenchInvoice(): Invoice {
        $seller = (new Party)
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Vendeur SARL')
            ->setVatNumber('FR12345678901')
            ->setAddress(['1 rue de la Paix'])
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setCompanyId(new Identifier('987654321', '0002'))
            ->setName('Acheteur SAS')
            ->setAddress(['2 avenue des Champs'])
            ->setPostalCode('69001')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = new Invoice(Ppf::class);
        return $invoice
            ->setNumber('FA-2026-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setBusinessProcess('B1')
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setDueDate(new DateTime('2026-02-15'))
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine((new InvoiceLine)
                ->setName('Prestation')
                ->setPrice(1000)
                ->setQuantity(1)
                ->setUnit('C62')
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

    public function testPresetDefaults(): void {
        $invoice = new Invoice(Ppf::class);

        $this->assertSame(2, $invoice->getDecimals(''));
        $this->assertSame('EUR', $invoice->getCurrency());
        $this->assertSame(
            'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931',
            $invoice->getSpecification()
        );
    }

    public function testNominalFrenchInvoicePasses(): void {
        $invoice = $this->getFrenchInvoice();

        $invoice->validate();
        $this->assertValidFacturX((new CiiWriter())->export($invoice));
    }

    public function testFrenchCreditNotePasses(): void {
        $invoice = $this->getFrenchInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->addPrecedingInvoiceReference(new InvoiceReference('FA-2026-000', new DateTime('2026-01-02')));

        $invoice->validate();
        $this->assertValidFacturX((new CiiWriter())->export($invoice));
    }

    /* ================= NEGATIVE CASES ================= */

    public function testForbiddenInvoiceTypeFails(): void {
        $invoice = $this->getFrenchInvoice()->setType(Invoice::TYPE_TAX_INVOICE); // 388

        $this->assertFailsRule('G1.01', $invoice);
    }

    public function testMissingBusinessProcessFails(): void {
        $invoice = $this->getFrenchInvoice()->setBusinessProcess(null);

        $this->assertFailsRule('G1.02', $invoice);
    }

    public function testUnknownBusinessProcessFails(): void {
        $invoice = $this->getFrenchInvoice()->setBusinessProcess('X9');

        $this->assertFailsRule('G1.02', $invoice);
    }

    public function testInvoiceNumberWithConsecutiveSpacesFails(): void {
        $invoice = $this->getFrenchInvoice()->setNumber('FA  01');

        $this->assertFailsRule('G1.05', $invoice);
    }

    public function testInvoiceNumberWithForbiddenCharacterFails(): void {
        $invoice = $this->getFrenchInvoice()->setNumber('FA#01');

        $this->assertFailsRule('G1.05', $invoice);
    }

    public function testTooLongInvoiceNumberFails(): void {
        $invoice = $this->getFrenchInvoice()->setNumber(str_repeat('A', 36));

        $this->assertFailsRule('G1.05', $invoice);
    }

    public function testUnknownVatRateFails(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getLines()[0]->setVatRate(19);

        $this->assertFailsRule('G1.24', $invoice);
    }

    public function testHistoricVatRateIsAccepted(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getLines()[0]->setVatRate(19.6);

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testCreditNoteWithoutPrecedingReferenceFails(): void {
        $invoice = $this->getFrenchInvoice()->setType(Invoice::TYPE_CREDIT_NOTE);

        $this->assertFailsRule('G1.31', $invoice);
    }

    public function testCorrectiveInvoiceWithTwoPrecedingReferencesFails(): void {
        $invoice = $this->getFrenchInvoice()
            ->setType(Invoice::TYPE_CORRECTIVE_INVOICE)
            ->addPrecedingInvoiceReference(new InvoiceReference('FA-1'))
            ->addPrecedingInvoiceReference(new InvoiceReference('FA-2'));

        $this->assertFailsRule('G1.32', $invoice);
    }

    public function testExemptBreakdownWithoutReasonTextFails(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getSeller()->setVatNumber('FR12345678901');
        $invoice->getLines()[0]->setVatCategory('E')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-132-1P');

        // The EN rule is satisfied by a code alone, the French one wants both
        $this->assertFailsRule('G1.41', $invoice);
    }

    public function testExemptBreakdownWithCodeAndTextPasses(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getLines()[0]->setVatCategory('E')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-132-1P')
            ->setVatExemptionReason('Exonération de TVA');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testExemptBreakdownBelow150EurosIsExempted(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getLines()[0]->setPrice(100)->setVatCategory('E')->setVatRate(0)
            ->setVatExemptionReasonCode('VATEX-EU-132-1P');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testForbiddenVatCategoryFails(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getLines()[0]->setVatCategory('L')->setVatRate(0);

        $this->assertFailsRule('G2.31', $invoice);
    }

    public function testShortSirenFails(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getSeller()->setCompanyId(new Identifier('12345678', '0002'));

        $this->assertFailsRule('G1.63', $invoice);
    }

    public function testMissingSirenSchemeFails(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getSeller()->setCompanyId(new Identifier('123456789'));

        $this->assertFailsRule('G1.63', $invoice);
    }

    public function testSiretIsAccepted(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getSeller()->setCompanyId(new Identifier('12345678901234', '0009'));

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testFrenchBuyerNeedsASiren(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getBuyer()->setCompanyId(null);

        $this->assertFailsRule('G1.63', $invoice);
    }

    public function testForeignBuyerNeedsNoSiren(): void {
        $invoice = $this->getFrenchInvoice();
        $invoice->getBuyer()->setCompanyId(null)->setCountry('DE');

        $invoice->validate();
        $this->assertTrue(true);
    }

    public function testDocumentAllowanceRateIsChecked(): void {
        $invoice = $this->getFrenchInvoice()
            ->addAllowance((new AllowanceOrCharge)
                ->setReason('Remise')
                ->setAmount(10)
                ->setVatCategory('S')
                ->setVatRate(19));

        $this->assertFailsRule('G1.24', $invoice);
    }
}
