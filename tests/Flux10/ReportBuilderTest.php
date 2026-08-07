<?php

namespace Tests\Flux10;

use DateTime;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\AmountByRate;
use Einvoicing\Flux10\Enums\BusinessProcessCode;
use Einvoicing\Flux10\Enums\IssuerRoleCode;
use Einvoicing\Flux10\Enums\TransmissionTypeCode;
use Einvoicing\Flux10\Invoice;
use Einvoicing\Flux10\InvoicePayment;
use Einvoicing\Flux10\Issuer;
use Einvoicing\Flux10\ReportBuilder;
use Einvoicing\Flux10\Sender;
use Einvoicing\Flux10\TaxBreakdown;
use Einvoicing\Writers\Flux10Writer;
use PHPUnit\Framework\TestCase;
use Tests\Writers\Flux10SemanticAssertions;

/**
 * The builder refuses to assemble a transmission whose envelope it would have to invent.
 */
final class ReportBuilderTest extends TestCase
{
    use Flux10SemanticAssertions;

    public function testBuildsAConformantTransactionsReport(): void
    {
        $report = $this->builder()
            ->addInvoices([$this->invoice()], BusinessProcessCode::SERVICES)
            ->build();

        $xml = (new Flux10Writer())->exportReport($report);

        $this->assertFlux10Semantics($xml);
        $this->assertStringContainsString('<ID>S1</ID>', $xml);
    }

    public function testBuildsAConformantPaymentsReport(): void
    {
        $report = $this->builder()
            ->addInvoicePayment(
                (new InvoicePayment())
                    ->setInvoiceId('INV-1')
                    ->setIssueDate(new DateTime('2026-01-10'))
                    ->setPaymentDate(new DateTime('2026-01-20'))
                    ->setCurrencyCode('EUR')
                    ->addAmountByRate((new AmountByRate())->setRate(20)->setAmount(1200.00))
            )
            ->build();

        $this->assertFlux10Semantics((new Flux10Writer())->exportReport($report));
    }

    /**
     * The invoicing framework has no EN 16931 equivalent, so the builder stamps it —
     * without overwriting one already set.
     */
    public function testDoesNotOverrideAnExplicitBusinessProcess(): void
    {
        $goods = $this->invoice()->setBusinessProcessId(BusinessProcessCode::GOODS);

        $report = $this->builder()->addInvoices([$goods], BusinessProcessCode::SERVICES)->build();

        $this->assertSame(BusinessProcessCode::GOODS, $report->getInvoices()[0]->getBusinessProcessId());
    }

    public function testRectificativeTransmissionIsAccepted(): void
    {
        $report = $this->builder()
            ->setTransmissionType(TransmissionTypeCode::RECTIFICATIVE)
            ->addInvoices([$this->invoice()], BusinessProcessCode::SERVICES)
            ->build();

        $this->assertStringContainsString('<TypeCode>RE</TypeCode>', (new Flux10Writer())->exportReport($report));
    }

    /**
     * @dataProvider missingEnvelopeProvider
     */
    public function testRefusesAnIncompleteEnvelope(string $omit, string $expectedRule, string $expectedMessage): void
    {
        $builder = new ReportBuilder();
        if ($omit !== 'sender') {
            $builder->setSender((new Sender())->setMatricule('PA01')->setName('Accredited Platform SA'));
        }
        if ($omit !== 'issuer') {
            $builder->setIssuer(
                (new Issuer())->setSiren('123456789')->setName('Issuer SA')->setRoleCode(IssuerRoleCode::SELLER)
            );
        }
        if ($omit !== 'transmissionId') {
            $builder->setTransmissionId('REPORT-2026-01');
        }
        if ($omit !== 'period') {
            $builder->setPeriod(new DateTime('2026-01-01'), new DateTime('2026-01-31'));
        }

        try {
            $builder->addInvoices([$this->invoice()], BusinessProcessCode::SERVICES)->build();
            $this->fail("Expected the builder to refuse a transmission without {$omit}");
        } catch (ValidationException $e) {
            $this->assertSame($expectedRule, $e->getBusinessRuleId());
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function missingEnvelopeProvider(): array
    {
        return [
            'without the emitting platform' => ['sender', 'G6.22', 'accredited platform'],
            'without the declarant' => ['issuer', 'G6.26', 'declarant'],
            'without a transmission identifier' => ['transmissionId', 'G8.05', 'unique per period and declarant'],
            'without a declared period' => ['period', 'G6.25', 'period it covers'],
        ];
    }

    /**
     * Business rules are checked at build time, not at export time.
     */
    public function testBuildValidatesTheReport(): void
    {
        $builder = $this->builder()->addInvoices(
            [$this->invoice()->setTypeCode('381')],
            BusinessProcessCode::SERVICES
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must reference at least one earlier invoice');

        $builder->build();
    }

    // -----------------------------------------------------------------------------

    private function builder(): ReportBuilder
    {
        return (new ReportBuilder())
            ->setSender((new Sender())->setMatricule('PA01')->setName('Accredited Platform SA'))
            ->setIssuer(
                (new Issuer())->setSiren('123456789')->setName('Issuer SA')->setRoleCode(IssuerRoleCode::SELLER)
            )
            ->setTransmissionId('REPORT-2026-01')
            ->setIssueDateTime(new DateTime('2026-02-01 08:30:00'))
            ->setPeriod(new DateTime('2026-01-01'), new DateTime('2026-01-31'));
    }

    private function invoice(): Invoice
    {
        return (new Invoice())
            ->setInvoiceId('INV-1')
            ->setIssueDate(new DateTime('2026-01-10'))
            ->setTypeCode('380')
            ->setCurrencyCode('EUR')
            ->setBusinessProcessTypeId(Flux10Writer::EREPORTING_PROFILE)
            ->setSellerId('123456789')
            ->setSellerSchemeId('0002')
            ->setSellerVatId('FR12345678901')
            ->setSellerCountry('FR')
            ->setBuyerId('DE123456789012')
            ->setBuyerSchemeId('0223')
            ->setBuyerVatId('DE123456789')
            ->setBuyerCountry('DE')
            ->setTaxExclusiveAmount(1000.00)
            ->setTaxAmount(200.00)
            ->addTaxBreakdownItem(
                (new TaxBreakdown())->setRate(20)->setTaxableAmount(1000.00)->setTaxAmount(200.00)
            );
    }
}
