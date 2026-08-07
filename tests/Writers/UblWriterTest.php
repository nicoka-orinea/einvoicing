<?php
namespace Tests\Writers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Attachment;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Party;
use Einvoicing\Payments\Payment;
use Einvoicing\Presets\Peppol;
use Einvoicing\Readers\UblReader;
use Einvoicing\Writers\UblWriter;
use PHPUnit\Framework\TestCase;
use Tests\ValidatesAgainstXsd;
use UXML\UXML;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;
use function array_map;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function random_int;
use function time;

final class UblWriterTest extends TestCase {
    use ValidatesAgainstXsd;

    /** @var UblWriter */
    private $writer;

    protected function setUp(): void {
        $this->writer = new UblWriter();
    }

    private function getSampleInvoice(): Invoice {
        $seller = (new Party)
            ->setElectronicAddress(new Identifier('9482348239847239874', '0088'))
            ->setCompanyId(new Identifier('COMPANY_ID', '0183'))
            ->setTaxRegistrationId(new Identifier('12345678', 'GST'))
            ->setName('Seller Name Ltd.')
            ->setTradingName('Seller Name')
            ->setVatNumber('ESA00000000')
            ->setAddress(['Fake Street 123'])
            ->setCity('Springfield')
            ->setCountry('ES');

        $buyer = (new Party)
            ->setElectronicAddress(new Identifier('ES12345', '0002'))
            ->setName('Buyer Name Ltd.')
            ->setCountry('ES');
        
        $complexLine = (new InvoiceLine)
            ->setName('Line #1')
            ->setDescription('The description for the first line')
            ->setPrice(10.5, 5)
            ->setQuantity(27)
            ->setVatRate(21)
            ->addCharge((new AllowanceOrCharge)->setReason('Handling and shipping')->setAmount(10.1234));

        $externalAttachment = (new Attachment)
            ->setId(new Identifier('ATT-4321'))
            ->setDescription('A link to an external attachment')
            ->setExternalUrl('https://www.example.com/document.pdf');
        $embeddedAttachment = (new Attachment)
            ->setId(new Identifier('ATT-1234'))
            ->setFilename('ATT-1234.pdf')
            ->setMimeCode('application/pdf')
            ->setContents('The attachment raw contents');

        $invoice = new Invoice(Peppol::class);
        $invoice->setNumber('ABC-123')
            ->setIssueDate(new DateTime('-3 days'))
            ->setDueDate(new DateTime('+30 days'))
            ->setBuyerReference('REF-0172637')
            ->addPrecedingInvoiceReference(new InvoiceReference('INV-123'))
            ->setTenderOrLotReference('PPID-123')
            ->setContractReference('123Contractref')
            ->setSeller($seller)
            ->setBuyer($buyer)
            ->addLine($complexLine)
            ->addLine((new InvoiceLine)->setName('Line #2')->setPrice(40, 2)->setVatRate(21)->setQuantity(4))
            ->addLine((new InvoiceLine)->setName('Line #3')->setPrice(0.56)->setVatRate(10)->setQuantity(2))
            ->addLine((new InvoiceLine)->setName('Line #4')->setPrice(0.56)->setVatRate(10)->setQuantity(2))
            ->addAllowance((new AllowanceOrCharge)->setReason('5% discount')->setAmount(5)->markAsPercentage()->setVatRate(21))
            ->setInvoicedObjectIdentifier(new Identifier('INV-123', 'ABT'))
            ->addAttachment($externalAttachment)
            ->addAttachment($embeddedAttachment);
        
        return $invoice;
    }

    private function validateInvoice(string $contents, string $type): void {
        // Build SOAP request
        $req  = '<?xml version="1.0" encoding="UTF-8"?>';
        $req .= '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"';
        $req .= ' xmlns:v1="http://www.gitb.com/vs/v1/" xmlns:v11="http://www.gitb.com/core/v1/">';
        $req .= '<soapenv:Body>';
        $req .= '<v1:ValidateRequest>';
        $req .= '<sessionId>' . time() . '-' . random_int(0, 9999) . '</sessionId>';
        $req .= '<input name="type" embeddingMethod="STRING"><v11:value>' . $type . '</v11:value></input>';
        $req .= '<input name="xml" embeddingMethod="STRING"><v11:value>';
        $req .= '<![CDATA[' . $contents . ']]>';
        $req .= '</v11:value></input>';
        $req .= '</v1:ValidateRequest>';
        $req .= '</soapenv:Body>';
        $req .= '<soapenv:Envelope>';

        // Send cURL request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://www.itb.ec.europa.eu/invoice/api/validation',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $req,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml']
        ]);
        $res = curl_exec($ch);
        unset($ch);

        if (!is_string($res) || trim($res) === '') {
            $this->markTestSkipped('EU invoice validation service is unavailable in this environment.');
        }

        // Validate response
        $nsSoap = 'http://schemas.xmlsoap.org/soap/envelope/';
        $nsVs = 'http://www.gitb.com/vs/v1/';
        $nsTr = 'http://www.gitb.com/tr/v1/';
        $xml = UXML::fromString($res);
        $report = $xml->get("{{$nsSoap}}Body/{{$nsVs}}ValidationResponse/report");
        $result = $report->get("{{$nsTr}}result")->asText();
        if ($result === 'SUCCESS') {
            $this->assertTrue(true); // To avoid marking test as incomplete
            return;
        }

        // Parse and report errors
        $errors = [];
        foreach ($report->getAll("{{$nsTr}}reports/{{$nsTr}}error") as $element) {
            $errors[] = $element->get("{{$nsTr}}description")->asText();
        }
        $this->fail(implode("\n", $errors));
    }

    public function testCanGenerateValidInvoice(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->validate();
        $contents = $this->writer->export($invoice);
        $this->validateInvoice($contents, 'ubl');
    }

    public function testCanGenerateValidCreditNote(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->setType(Invoice::TYPE_CREDIT_NOTE);
        $invoice->addPayment((new Payment)->setMeansCode('10')->setMeansText('In cash'));
        $invoice->validate();
        $contents = $this->writer->export($invoice);
        $this->validateInvoice($contents, 'credit');
    }

    public function testCanHaveLinesWithForcedDuplicateIdentifiers(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->getLines()[1]->setId('DuplicateId');
        $invoice->getLines()[2]->setId('DuplicateId');
        $invoice->getLines()[3]->setId('DuplicateId');
        $xml = UXML::fromString($this->writer->export($invoice));
        $actualLineIds = array_map(function(UXML $item) {
            return $item->asText();
        }, $xml->getAll('cac:InvoiceLine/cbc:ID'));
        $this->assertEquals(['1', 'DuplicateId', 'DuplicateId', 'DuplicateId'], $actualLineIds);
    }

    public function testCanAutogenerateInvoiceLineIdentifiers(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->getLines()[1]->setId('1');
        $invoice->getLines()[2]->setId('AnotherCustomId');
        $xml = UXML::fromString($this->writer->export($invoice));
        $actualLineIds = array_map(function(UXML $item) {
            return $item->asText();
        }, $xml->getAll('cac:InvoiceLine/cbc:ID'));
        $this->assertEquals(['2', '1', 'AnotherCustomId', '3'], $actualLineIds);
    }

    public function testCanWriteDocumentNoteSubjectCode(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->addNote('Late payment penalties', 'PMD');

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertSame('#PMD#Late payment penalties', $xml->get('cbc:Note')->asText());
    }

    public function testWritesNaOrderReferenceWhenOnlySalesOrderReferenceExists(): void {
        $invoice = $this->getSampleInvoice();
        $invoice->setPurchaseOrderReference(null);
        $invoice->setSalesOrderReference('SO-123');

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertSame('NA', $xml->get('cac:OrderReference/cbc:ID')->asText());
        $this->assertSame('SO-123', $xml->get('cac:OrderReference/cbc:SalesOrderID')->asText());
    }

    public function testSelfBilledCreditNoteUsesCreditNoteRoot(): void {
        $invoice = $this->getSampleInvoice()->setType(Invoice::TYPE_SELF_BILLED_CREDIT_NOTE);

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertSame('CreditNote', $xml->element()->localName);
        $this->assertSame('261', $xml->get('cbc:CreditNoteTypeCode')->asText());
        $this->assertNull($xml->get('cbc:InvoiceTypeCode'));
    }

    /** @return array<string, array{int}> */
    public static function creditNoteTypeProvider(): array {
        return [
            'credit note related to goods or services' => [81],
            'credit note related to financial adjustments' => [83],
            'self-billed credit note' => [261],
            'credit note' => [381],
            'factored credit note' => [396],
            'self-billed factored credit note' => [502],
            'prepayment credit note' => [503],
            "forwarder's credit note" => [532],
        ];
    }

    /** @dataProvider creditNoteTypeProvider */
    public function testEveryCreditNoteTypeUsesCreditNoteRoot(int $type): void {
        $invoice = $this->getSampleInvoice()->setType($type);

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertSame('CreditNote', $xml->element()->localName);
        $this->assertSame((string) $type, $xml->get('cbc:CreditNoteTypeCode')->asText());
    }

    public function testCreditNoteChildrenFollowStandardUblSequence(): void {
        $invoice = $this->getSampleInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setTaxPointDate(new DateTime('2026-01-15'))
            ->setProjectReference('PR-1')
            ->setDueDate(new DateTime('2026-02-15'))
            ->addPayment((new Payment)->setMeansCode('10')->setMeansText('In cash'));

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertChildOrder($xml, '', self::UBL_CREDIT_NOTE_ORDER);
    }

    public function testInvoiceChildrenFollowStandardUblSequence(): void {
        $invoice = $this->getSampleInvoice()
            ->setTaxPointDate(new DateTime('2026-01-15'))
            ->setProjectReference('PR-1')
            ->setDespatchAdviceReference('DESP-1')
            ->setTaxRepresentative((new Party)->setName('Tax Rep')->setVatNumber('FR99988877766')->setCountry('FR'))
            ->addPayment((new Payment)->setMeansCode('10')->setMeansText('In cash'));

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertChildOrder($xml, '', self::UBL_INVOICE_ORDER);
    }

    public function testProjectReferenceOnCreditNote(): void {
        $invoice = $this->getSampleInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setProjectReference('PR-1');

        $contents = $this->writer->export($invoice);
        $xml = UXML::fromString($contents);

        // cac:ProjectReference does not exist in the credit note schema
        $this->assertNull($xml->get('cac:ProjectReference'));
        $projectReference = null;
        foreach ($xml->getAll('cac:AdditionalDocumentReference') as $node) {
            if ($node->get('cbc:DocumentTypeCode')?->asText() === '50') {
                $projectReference = $node->get('cbc:ID')?->asText();
            }
        }
        $this->assertSame('PR-1', $projectReference);
        $this->assertSame('PR-1', (new UblReader())->import($contents)->getProjectReference());
    }

    public function testProjectReferenceOnInvoiceKeepsProjectReferenceNode(): void {
        $invoice = $this->getSampleInvoice()->setProjectReference('PR-1');

        $contents = $this->writer->export($invoice);
        $xml = UXML::fromString($contents);

        $this->assertSame('PR-1', $xml->get('cac:ProjectReference/cbc:ID')->asText());
        $this->assertSame('PR-1', (new UblReader())->import($contents)->getProjectReference());
    }

    public function testTaxPointDateOrderOnCreditNote(): void {
        $invoice = $this->getSampleInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setTaxPointDate(new DateTime('2026-01-15'));

        $xml = UXML::fromString($this->writer->export($invoice));

        // The credit note sequence is IssueDate, TaxPointDate, CreditNoteTypeCode
        $this->assertChildOrder($xml, '', ['IssueDate', 'TaxPointDate', 'CreditNoteTypeCode', 'Note']);
    }

    public function testTaxPointDateOrderOnInvoice(): void {
        $invoice = $this->getSampleInvoice()->setTaxPointDate(new DateTime('2026-01-15'));

        $xml = UXML::fromString($this->writer->export($invoice));

        // The invoice sequence is InvoiceTypeCode, Note, TaxPointDate
        $this->assertChildOrder($xml, '', ['InvoiceTypeCode', 'Note', 'TaxPointDate', 'DocumentCurrencyCode']);
    }

    public function testDueDateSinglePaymentDueDate(): void {
        $invoice = $this->getSampleInvoice()
            ->setType(Invoice::TYPE_CREDIT_NOTE)
            ->setDueDate(new DateTime('2026-02-15'))
            ->addPayment((new Payment)->setMeansCode('10'))
            ->addPayment((new Payment)->setMeansCode('30'));

        $xml = UXML::fromString($this->writer->export($invoice));

        $this->assertNull($xml->get('cbc:DueDate'));
        $this->assertCount(1, $xml->getAll('cac:PaymentMeans/cbc:PaymentDueDate'));
    }

    public function testVatPointDateCode(): void {
        $invoice = $this->getSampleInvoice()->setVatPointDateCode('72');

        $contents = $this->writer->export($invoice);
        $xml = UXML::fromString($contents);

        $this->assertSame('72', $xml->get('cac:InvoicePeriod/cbc:DescriptionCode')->asText());
        $this->assertSame('72', (new UblReader())->import($contents)->getVatPointDateCode());
    }

    public function testVatPointDateCodeIsWrittenWithoutPeriodDates(): void {
        $invoice = $this->getSampleInvoice()
            ->setPeriodStartDate(null)
            ->setPeriodEndDate(null)
            ->setVatPointDateCode('5');

        $xml = UXML::fromString($this->writer->export($invoice));
        $period = $xml->get('cac:InvoicePeriod');

        $this->assertNotNull($period);
        $this->assertNull($period->get('cbc:StartDate'));
        $this->assertSame('5', $period->get('cbc:DescriptionCode')->asText());
    }

    public function testTaxRepresentative(): void {
        $representative = (new Party)
            ->setName('Tax Rep SARL')
            ->setVatNumber('FR99988877766')
            ->setAddress(['Rep Street 1'])
            ->setPostalCode('13001')
            ->setCity('Marseille')
            ->setCountry('FR');
        $invoice = $this->getSampleInvoice()->setTaxRepresentative($representative);

        $contents = $this->writer->export($invoice);
        $xml = UXML::fromString($contents);
        $node = $xml->get('cac:TaxRepresentativeParty');

        $this->assertNotNull($node);
        $this->assertSame('Tax Rep SARL', $node->get('cac:PartyName/cbc:Name')->asText());
        $this->assertSame('FR99988877766', $node->get('cac:PartyTaxScheme/cbc:CompanyID')->asText());
        $this->assertSame('VAT', $node->get('cac:PartyTaxScheme/cac:TaxScheme/cbc:ID')->asText());
        $this->assertSame('FR', $node->get('cac:PostalAddress/cac:Country/cbc:IdentificationCode')->asText());

        $imported = (new UblReader())->import($contents)->getTaxRepresentative();
        $this->assertSame('Tax Rep SARL', $imported?->getName());
        $this->assertSame('FR99988877766', $imported?->getVatNumber());
        $this->assertSame('FR', $imported?->getCountry());
        $this->assertSame('Marseille', $imported?->getCity());
    }
}
