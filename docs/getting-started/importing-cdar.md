# Importing CDAR XML step-by-step
CDAR messages describe the status updates of an invoice lifecycle. This guide shows how to import a CDAR XML file into a
`CrossDomainAcknowledgementAndResponse` instance.

In this guide you'll:
- Read a CDAR XML file.
- Parse it with the `CdarReader`.
- Access the main CDAR fields.

## Step 1: Read the XML file
Load the CDAR XML from disk:
```php
$document = file_get_contents(__DIR__ . "/cdar.xml");
```

## Step 2: Parse with the reader
Use the CDAR reader to parse the XML:
```php
use Einvoicing\Readers\CdarReader;

$reader = new CdarReader();
$cdar = $reader->import($document);
```

If the XML is invalid, the reader throws an `InvalidArgumentException`:
```php
try {
    $cdar = $reader->import($document);
} catch (\InvalidArgumentException $e) {
    // Invalid or unreadable XML
}
```

## Step 3: Access CDAR data
The parsed object exposes the main sections of the CDAR message:
```php
$context = $cdar->getDocumentContext();
$exchanged = $cdar->getExchangedDocument();
$ack = $cdar->getAcknowledgementDocument();
```

For example, you can inspect the process condition and status codes:
```php
$reference = $ack?->getReference();

$processConditionCode = $reference?->getProcessConditionCode();
$statusCode = $reference?->getStatusCode();
$processLabel = $reference?->getProcessCondition(); // English label if known
```

If you need the official CDAR XML label, use the mapping helper:
```php
use Einvoicing\Cdar\Mapping\CdarStatusMap;

$definition = $processConditionCode === null
    ? null
    : CdarStatusMap::forProcessConditionCode((int) $processConditionCode);

$xmlLabel = $definition?->getXmlLabel();
```

You are now ready to handle the CDAR status update in your application.
