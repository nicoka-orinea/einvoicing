<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\Writers\UblWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;
use UXML\UXML;

/**
 * Witness test for the XSD/DOM assertion harness on the UBL side.
 *
 * No complete OASIS UBL 2.1 schema is vendored (the PPF F1 profiles drop
 * EN 16931 mandatory terms, see Tests\ValidatesAgainstXsd), so document element
 * ordering is asserted against the standard UBL 2.1 sequences instead.
 */
final class UblWriterXsdTest extends TestCase {
    use ValidatesAgainstXsd;

    private function getFrenchInvoice(): Invoice {
        $seller = (new Party)
            ->setElectronicAddress(new Identifier('123456789', '0002'))
            ->setCompanyId(new Identifier('123456789', '0002'))
            ->setName('Seller Name Ltd.')
            ->setVatNumber('FR12345678901')
            ->setAddress(['Fake Street 123'])
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setCountry('FR');

        $buyer = (new Party)
            ->setElectronicAddress(new Identifier('987654321', '0002'))
            ->setCompanyId(new Identifier('987654321', '0002'))
            ->setName('Buyer Name Ltd.')
            ->setAddress(['Main Avenue 12'])
            ->setPostalCode('69001')
            ->setCity('Lyon')
            ->setCountry('FR');

        $invoice = new Invoice();
        $invoice->setSpecification('urn:cen.eu:en16931:2017')
            ->setBusinessProcess('B1')
            ->setNumber('INV-XSD-001')
            ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)
            ->setIssueDate(new DateTime('2026-01-15'))
            ->setDueDate(new DateTime('2026-02-15'))
            ->setTaxPointDate(new DateTime('2026-01-15'))
            ->setCurrency('EUR')
            ->setVatCurrency('USD')
            ->setBuyerReference('B1')
            ->setBuyerAccountingReference('ACC-1')
            ->setPurchaseOrderReference('PO-1')
            ->setContractReference('CT-1')
            ->setProjectReference('PR-1')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addAllowance((new AllowanceOrCharge)->setReason('Discount')->setAmount(10)->setVatCategory('S')->setVatRate(20))
            ->addPayment((new Payment)->setId('PAY-1')->setMeansCode('30')->addTransfer((new Transfer)->setAccountId('FR7612345678901234567890123')))
            ->setPaymentTerms('30 days net')
            ->addLine((new InvoiceLine)
                ->setName('Line #1')
                ->setPrice(100)
                ->setQuantity(1)
                ->setUnit('C62')
                ->setVatCategory('S')
                ->setVatRate(20));

        return $invoice;
    }

    public function testInvoiceChildrenFollowStandardUblSequence(): void {
        $xml = UXML::fromString((new UblWriter())->export($this->getFrenchInvoice()));

        $this->assertChildOrder($xml, '', self::UBL_INVOICE_ORDER);
    }

    // The credit note sequence is asserted in UblWriterTest, together with the
    // profile-specific fixes it requires (BT-7, BT-11, BT-9).
}
