<?php
namespace Tests\Validators;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Validators\CiiCoherenceValidator;
use Einvoicing\Writers\CiiWriter;
use PHPUnit\Framework\TestCase;

final class CiiCoherenceValidatorTest extends TestCase {
    private function invoice(): Invoice {
        return (new Invoice)
            ->setNumber('F26-001497')
            ->setIssueDate(new DateTime('2026-09-01'))
            ->setDueDate(new DateTime('2026-09-01'))
            ->setCurrency('EUR')
            ->setSeller((new Party)->setName('ORINEA')->setCountry('FR')->setCompanyId(new Identifier('518090733', '0002')))
            ->setBuyer((new Party)->setName('Buyer')->setCountry('FR')->setCompanyId(new Identifier('850966391', '0002')));
    }

    /** @return string[] */
    private function rules(array $violations): array {
        return array_values(array_unique(array_map(fn ($v) => $v->rule, $violations)));
    }

    public function testAcceptsACoherentDocumentWithFixedAllowanceHalfCentLinesAndFourDecimalPrice(): void {
        $invoice = $this->invoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('ATS')->setPrice(130)->setQuantity(1)->setVatRate(20)
                ->addAllowance((new AllowanceOrCharge)->setReasonCode('95')->setAmount(79.99)->markAsFixedAmount()->setVatRate(20)))
            ->addLine((new InvoiceLine)->setId('2')->setName('Half cent')->setPrice(10.005)->setQuantity(1)->setVatRate(20))
            ->addLine((new InvoiceLine)->setId('3')->setName('Half cent')->setPrice(10.005)->setQuantity(1)->setVatRate(20))
            ->addLine((new InvoiceLine)->setId('4')->setName('Thirds')->setPrice(33.3333)->setQuantity(3)->setVatRate(20))
            ->addLine((new InvoiceLine)->setId('5')->setName('Half day')->setPrice(800)->setQuantity(0.5)->setVatRate(10))
            ->addAllowance((new AllowanceOrCharge)->setReasonCode('95')->setAmount(10)->markAsFixedAmount());

        $xml = (new CiiWriter)->export($invoice);
        $violations = (new CiiCoherenceValidator)->validate($xml, ['grandTotal' => 0.0]);

        $this->assertSame(['DOCUMENT'], $this->rules($violations), implode("\n", array_map('strval', $violations)));
        $this->assertSame([], (new CiiCoherenceValidator)->validate($xml));
    }

    public function testDetectsTamperedLineAndDocumentTotals(): void {
        $invoice = $this->invoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('ATS')->setPrice(130)->setQuantity(1)->setVatRate(20)
                ->addAllowance((new AllowanceOrCharge)->setReasonCode('95')->setAmount(79.99)->markAsFixedAmount()->setVatRate(20)));
        $xml = (new CiiWriter)->export($invoice);

        // The original F26-001497 defect: a rounded percentage that does not reproduce the amount
        $tampered = str_replace(
            '<ram:ActualAmount>79.99</ram:ActualAmount>',
            '<ram:CalculationPercent>61.50</ram:CalculationPercent><ram:BasisAmount>130.00</ram:BasisAmount><ram:ActualAmount>79.99</ram:ActualAmount>',
            $xml
        );
        $this->assertSame(['PA-ALLOWANCE'], $this->rules((new CiiCoherenceValidator)->validate($tampered)));

        // A line total that does not match price × quantity − allowance, and thus the document sum
        // Only the line-level total (first occurrence): the header keeps 50.01
        $tampered = preg_replace('/<ram:LineTotalAmount>50\.01</', '<ram:LineTotalAmount>50.02<', $xml, 1);
        $rules = $this->rules((new CiiCoherenceValidator)->validate($tampered));
        $this->assertContains('PA-LINE', $rules);
        $this->assertContains('BR-CO-10', $rules);
        $this->assertContains('BR-S-08', $rules);

        // A grand total that does not match the invoice the PDF shows (one cent of VAT drift is tolerated)
        $this->assertSame([], (new CiiCoherenceValidator)->validate($xml, ['grandTotal' => 60.02, 'taxTotal' => 10.01, 'taxBasisTotal' => 50.01]));
        $rules = $this->rules((new CiiCoherenceValidator)->validate($xml, ['grandTotal' => 60.05, 'taxBasisTotal' => 50.02]));
        $this->assertSame(['DOCUMENT'], $rules);
    }

    public function testDetectsWrongVatCategories(): void {
        $invoice = $this->invoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('Zero as standard')->setPrice(100)->setQuantity(1)->setVatCategory('S')->setVatRate(0))
            ->addLine((new InvoiceLine)->setId('2')->setName('Exempt without reason')->setPrice(100)->setQuantity(1)->setVatCategory('E')->setVatRate(0));
        $xml = (new CiiWriter)->export($invoice);

        $rules = $this->rules((new CiiCoherenceValidator)->validate($xml));

        $this->assertContains('BR-S-05', $rules);
        $this->assertContains('BR-E-10', $rules);
        $this->assertNotContains('BR-CO-10', $rules);
    }

    public function testAcceptsExemptCategoriesWithReasonAndDocumentAllowanceSplitByRate(): void {
        $invoice = $this->invoice()
            ->addLine((new InvoiceLine)->setId('1')->setName('Standard')->setPrice(100)->setQuantity(2)->setVatRate(20))
            ->addLine((new InvoiceLine)->setId('2')->setName('Reduced')->setPrice(50)->setQuantity(1)->setVatRate(5.5))
            ->addLine((new InvoiceLine)->setId('3')->setName('Export')->setPrice(75)->setQuantity(1)
                ->setVatCategory('G')->setVatRate(0)->setVatExemptionReasonCode('VATEX-EU-G')->setVatExemptionReason('Export'))
            ->addAllowance((new AllowanceOrCharge)->setReasonCode('95')->setAmount(10)->markAsPercentage());

        $violations = (new CiiCoherenceValidator)->validate((new CiiWriter)->export($invoice));

        $this->assertSame([], $violations, implode("\n", array_map('strval', $violations)));
    }
}
