# Exporting CDAR XML step-by-step
CDAR messages are used to publish invoice lifecycle updates. This guide shows how to build a
`CrossDomainAcknowledgementAndResponse` instance and export it to CDAR XML.

In this guide you'll:
- Create a minimal CDAR message.
- Add one or more lifecycle events.
- Export the XML with the CDAR writer.

## Step 1: Create the CDAR container
Start with the main CDAR object:
```php
use Einvoicing\CrossDomainAcknowledgementAndResponse;

$cdar = new CrossDomainAcknowledgementAndResponse();
```

## Step 2: Add the exchanged document
The exchanged document contains the CDAR message metadata:
```php
use DateTime;
use Einvoicing\Cdar\ExchangedDocument;

$exchanged = new ExchangedDocument();
$exchanged->setId('F202500003_212_20250701151000#380_20250701')
    ->setName('CDAR status update')
    ->setIssueDateTime(new DateTime('2025-08-02 10:05:00'));

$cdar->setExchangedDocument($exchanged);
```

## Step 3: Add the acknowledgement document
The acknowledgement document carries the status update for the referenced invoice.
Each lifecycle event is represented by a `SpecifiedDocumentStatus` node:
```php
use DateTime;
use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\ReferenceReferencedDocument;
use Einvoicing\Cdar\SpecifiedDocumentCharacteristic;
use Einvoicing\Cdar\SpecifiedDocumentStatus;

$reference = new ReferenceReferencedDocument();
$reference->setIssuerAssignedId('F202500003')
    ->setTypeCode('380')
    ->setReceiptDateTime(new DateTime('2025-07-01 15:10:00'))
    ->setFormattedIssueDateTime(new DateTime('2025-07-01'));

$paid = (new SpecifiedDocumentStatus())
    ->setReferenceDateTime(new DateTime('2025-08-02'))
    ->setProcessConditionCode('212')
    ->setProcessCondition('Encaissee')
    ->addCharacteristic(
        (new SpecifiedDocumentCharacteristic())
            ->setId('BT-20')
            ->setDescription('Payment terms')
            ->setValueChangedIndicator(true)
            ->setValue('Paid by transfer')
    );

$reference->addSpecifiedDocumentStatus($paid);

$ack = new AcknowledgementDocument();
$ack->setMultipleReferencesIndicator(false)
    ->setTypeCode('23')
    ->setIssueDateTime(new DateTime('2025-08-02 10:00:00'))
    ->setReference($reference);

$cdar->setAcknowledgementDocument($ack);
```

## Step 4: Export the XML
Use the CDAR writer to export the XML document:
```php
use Einvoicing\Writers\CdarWriter;

$writer = new CdarWriter();
$document = $writer->export($cdar);
file_put_contents(__DIR__ . "/cdar.xml", $document);
```

The writer preserves each `SpecifiedDocumentStatus` block in order, which is useful when a CDAR carries several
events for the same invoice.
