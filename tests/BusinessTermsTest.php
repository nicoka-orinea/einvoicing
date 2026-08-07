<?php
namespace Tests;

use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Payments\Mandate;
use PHPUnit\Framework\TestCase;

/**
 * Accessors for the business terms added on top of the upstream model
 */
final class BusinessTermsTest extends TestCase {
    public function testVatPointDateCodeRoundtrip(): void {
        $invoice = new Invoice();

        $this->assertNull($invoice->getVatPointDateCode());
        $invoice->setVatPointDateCode('72');
        $this->assertSame('72', $invoice->getVatPointDateCode());
        $invoice->setVatPointDateCode(null);
        $this->assertNull($invoice->getVatPointDateCode());
    }

    public function testTaxRepresentativeRoundtrip(): void {
        $invoice = new Invoice();
        $representative = (new Party)->setName('Tax Rep SARL')->setVatNumber('FR99988877766')->setCountry('FR');

        $this->assertNull($invoice->getTaxRepresentative());
        $invoice->setTaxRepresentative($representative);
        $this->assertSame($representative, $invoice->getTaxRepresentative());
        $invoice->setTaxRepresentative(null);
        $this->assertNull($invoice->getTaxRepresentative());
    }

    public function testDespatchAdviceReferenceRoundtrip(): void {
        $invoice = new Invoice();

        $this->assertNull($invoice->getDespatchAdviceReference());
        $invoice->setDespatchAdviceReference('DESP-1');
        $this->assertSame('DESP-1', $invoice->getDespatchAdviceReference());
        $invoice->setDespatchAdviceReference(null);
        $this->assertNull($invoice->getDespatchAdviceReference());
    }

    public function testInvoicedObjectIdentifierRoundtrip(): void {
        $invoice = new Invoice();
        $identifier = new Identifier('OBJ-1', 'AWV');

        $this->assertNull($invoice->getInvoicedObjectIdentifier());
        $invoice->setInvoicedObjectIdentifier($identifier);
        $this->assertSame($identifier, $invoice->getInvoicedObjectIdentifier());
        $invoice->setInvoicedObjectIdentifier(null);
        $this->assertNull($invoice->getInvoicedObjectIdentifier());
    }

    public function testGrossPriceRoundtrip(): void {
        $line = new InvoiceLine();

        $this->assertNull($line->getGrossPrice());
        $line->setGrossPrice(120.5);
        $this->assertSame(120.5, $line->getGrossPrice());
        $line->setGrossPrice(null);
        $this->assertNull($line->getGrossPrice());
    }

    public function testCreditorIdentifierRoundtrip(): void {
        $mandate = new Mandate();

        $this->assertNull($mandate->getCreditorIdentifier());
        $mandate->setCreditorIdentifier('FR12ZZZ123456');
        $this->assertSame('FR12ZZZ123456', $mandate->getCreditorIdentifier());
        $mandate->setCreditorIdentifier(null);
        $this->assertNull($mandate->getCreditorIdentifier());
    }

    public function testFrenchAllowedTypesIsClosedList(): void {
        $this->assertSame(
            [380, 389, 393, 501, 386, 500, 384, 471, 472, 473, 261, 381, 396, 502, 503],
            Invoice::FR_ALLOWED_TYPES
        );
        $this->assertCount(15, Invoice::FR_ALLOWED_TYPES);
    }

    public function testCreditNoteTypesIsClosedList(): void {
        $this->assertSame([81, 83, 261, 381, 396, 502, 503, 532], Invoice::CREDIT_NOTE_TYPES);
    }

    public function testAddedTypeConstantsMatchUntdid1001(): void {
        $this->assertSame(389, Invoice::TYPE_SELF_BILLED_INVOICE);
        $this->assertSame(384, Invoice::TYPE_CORRECTIVE_INVOICE);
        $this->assertSame(261, Invoice::TYPE_SELF_BILLED_CREDIT_NOTE);
        $this->assertSame(501, Invoice::TYPE_SELF_BILLED_FACTORED_INVOICE);
        $this->assertSame(500, Invoice::TYPE_SELF_BILLED_PREPAYMENT_INVOICE);
        $this->assertSame(471, Invoice::TYPE_SELF_BILLED_CORRECTIVE_INVOICE);
        $this->assertSame(472, Invoice::TYPE_FACTORED_CORRECTIVE_INVOICE);
        $this->assertSame(473, Invoice::TYPE_SELF_BILLED_FACTORED_CORRECTIVE_INVOICE);
        $this->assertSame(502, Invoice::TYPE_SELF_BILLED_FACTORED_CREDIT_NOTE);
        $this->assertSame(503, Invoice::TYPE_PREPAYMENT_CREDIT_NOTE);
    }
}
