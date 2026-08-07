# French PPF invoices (Factur-X)

The `Ppf` preset targets the French *Portail Public de Facturation*, as described
by the "Spécifications externes FE v3.2" and its Annexe 7 "Règles de gestion"
v1.9.

!!! warning "XML only"
    This library produces the **CII XML** document. It does **not** build the
    Factur-X PDF/A-3 container: embedding the XML into an invoice PDF requires a
    separate PDF/A-3 tool.

## What the preset sets up

```php
use Einvoicing\Invoice;
use Einvoicing\Presets;

$invoice = new Invoice(Presets\Ppf::class);
```

- **BT-24** (specification identifier):
  `urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931`.
  The PPF annexes state that BT-24 is mandatory but do not pin a value, so the
  Factur-X EN 16931 guideline identifier is used.
- **BT-5** (currency): `EUR`.
- Rounding matrix: 2 decimals for every amount.

## A complete French invoice

```php
use DateTime;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Presets;
use Einvoicing\Writers\CiiWriter;

$seller = (new Party)
    ->setCompanyId(new Identifier('123456789', '0002')) // BT-30: SIREN
    ->setName('Vendeur SARL')
    ->setVatNumber('FR12345678901')                     // BT-31
    ->setAddress(['1 rue de la Paix'])
    ->setPostalCode('75001')
    ->setCity('Paris')
    ->setCountry('FR');

$buyer = (new Party)
    ->setCompanyId(new Identifier('987654321', '0002')) // BT-47: SIREN
    ->setName('Acheteur SAS')
    ->setAddress(['2 avenue des Champs'])
    ->setPostalCode('69001')
    ->setCity('Lyon')
    ->setCountry('FR');

$invoice = new Invoice(Presets\Ppf::class);
$invoice->setNumber('FA-2026-001')                      // BT-1
    ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)         // BT-3
    ->setBusinessProcess('B1')                          // BT-23: cadre de facturation
    ->setIssueDate(new DateTime('2026-01-15'))          // BT-2
    ->setDueDate(new DateTime('2026-02-15'))            // BT-9
    ->setSeller($seller)
    ->setBuyer($buyer)
    ->addLine((new InvoiceLine)
        ->setName('Prestation de conseil')
        ->setPrice(1000)
        ->setQuantity(1)
        ->setUnit('C62')
        ->setVatCategory('S')
        ->setVatRate(20));

$invoice->validate();
$xml = (new CiiWriter())->export($invoice);
```

## A credit note

A credit note must reference at least one preceding invoice (rule G1.31), and a
corrective invoice exactly one (rule G1.32).

```php
use Einvoicing\InvoiceReference;

$creditNote = new Invoice(Presets\Ppf::class);
$creditNote->setNumber('AV-2026-001')
    ->setType(Invoice::TYPE_CREDIT_NOTE)                // 381
    ->setBusinessProcess('B1')
    ->setIssueDate(new DateTime('2026-02-01'))
    ->setSeller($seller)
    ->setBuyer($buyer)
    ->addPrecedingInvoiceReference(new InvoiceReference('FA-2026-001', new DateTime('2026-01-15')))
    ->addLine((new InvoiceLine)
        ->setName('Remboursement')
        ->setPrice(1000)
        ->setQuantity(1)
        ->setVatCategory('S')
        ->setVatRate(20));

$creditNote->validate();
```

## Rules the preset enforces

| Rule | Check |
|---|---|
| `G1.01` | BT-3 belongs to the fifteen types France allows (`Invoice::FR_ALLOWED_TYPES`) |
| `G1.02` | BT-23 is present and one of `B1`, `S1`, `M1`, `B2`, `S2`, `M2`, `B4`, `S4`, `M4`, `S5`, `S6`, `B7`, `S7` |
| `G1.05` | BT-1 is at most 35 characters, restricted to letters, digits, space, `-`, `+`, `_` and `/`, with no leading, trailing or consecutive space |
| `G1.24` | Every VAT rate is one of the French rates: 0, 0.9, 1.05, 1.75, 2.1, 5.5, 7, 8.5, 9.2, 9.6, 10, 13, 19.6, 20, 20.6 |
| `G1.31` | A credit note carries at least one BT-25 |
| `G1.32` | A corrective invoice carries exactly one BT-25 |
| `G1.41` | An exempt (`E`) VAT breakdown carries both BT-121 and BT-120. Not applied to a corrective invoice nor below 150 EUR |
| `G1.53` | For a euro invoice, BT-109 equals the sum of BT-116 and BT-110 the sum of BT-117, within one cent |
| `G1.63` | Seller BT-30 and French buyer BT-47 carry a scheme of `0002` (9 digits) or `0009` (14 digits) |
| `G2.31` | Every VAT category is one of `S`, `E`, `AE`, `K`, `G`, `O`, `Z` |

Rule `G2.32` (rejecting an invoice made up only of category `O`, or only of
category `E` with a `VATEX-FR-CGI261*` exemption code) is not implemented: it
needs the VATEX code list to be modelled first.

These come on top of the EN 16931 rules, which always run.
