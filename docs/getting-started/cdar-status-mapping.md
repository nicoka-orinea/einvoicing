# CDAR status mapping step-by-step
CDAR files expose multiple codes and labels for the same life-cycle event. To keep your code readable and consistent,
this library provides a mapping layer around those values.

In this guide you'll:
- Resolve a CDAR status from a process condition code.
- Access the English label for logs or UI.
- Retrieve the exact label required by CDAR XML.

## Step 1: Pick the process condition code
Start with the process condition you want to represent:
```php
use Einvoicing\Cdar\Enums\ProcessConditionCode;

$condition = ProcessConditionCode::PAID;
```

## Step 2: Resolve the mapping definition
Use the mapping helper to fetch the corresponding status definition:
```php
use Einvoicing\Cdar\Mapping\CdarStatusMap;

$definition = CdarStatusMap::forProcessConditionCode($condition);
```

## Step 3: Use the mapped values
From the definition you can access all the values you need:
```php
$processCode = $definition->getProcessConditionCode()->value; // 212
$statusCode = $definition->getStatusCode()->value; // 47
$label = $definition->getLabel(); // "Paid"
$xmlLabel = $definition->getXmlLabel(); // "Encaissee"
```

## Step 4: Apply the status to a CDAR update
When you build a CDAR update, you can apply the process condition directly to a reference document:
```php
use Einvoicing\Cdar\ReferenceReferencedDocument;

$reference = new ReferenceReferencedDocument();
$reference->applyProcessCondition($condition);
```

This convenience method creates a first `SpecifiedDocumentStatus` entry with the mapped XML label. If you need to
attach additional events or characteristics, append them with `addSpecifiedDocumentStatus()`.

## Finding all process conditions for a status code
Sometimes you only have a status code. You can fetch every matching process condition like this:
```php
$definitions = CdarStatusMap::forStatusCode(47);
```
