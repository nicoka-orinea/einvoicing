# Exporting Flux 10 e-reporting XML

Flux 10 is the French e-reporting flow defined by the DGFiP *spécifications externes*
(v3.2). It is **not** an invoice format: it is a periodic declaration that an accredited
platform sends to the PPF, aggregating either transactions or payments over a period.

Four sub-flux share one envelope:

| Sub-flux | Content | XML block |
|---|---|---|
| 10.1 | Invoices — B2B international | `TransactionsReport/Invoice` |
| 10.3 | Aggregated transactions — B2C | `TransactionsReport/Transactions` |
| 10.2 | Payments collected against invoices | `PaymentsReport/Invoice` |
| 10.4 | Payments collected against transactions | `PaymentsReport/Transactions` |

!!! warning "Transactions and payments travel separately"
    A transmission carries aggregated transactions **or** aggregated payments, never
    both — rule G6.29. The writer refuses a report holding both.

## Building a report

Use `ReportBuilder`. It asks for the things a transmission needs and no invoice knows:

```php
use Einvoicing\Flux10\Enums\BusinessProcessCode;
use Einvoicing\Flux10\Enums\IssuerRoleCode;
use Einvoicing\Flux10\{Issuer, ReportBuilder, Sender};
use Einvoicing\Writers\Flux10Writer;

$report = (new ReportBuilder())
    // The accredited platform emitting the flow: 4-character matricule, scheme 0238,
    // role WK (G6.22, G7.51)
    ->setSender((new Sender())->setMatricule('PA01')->setName('My Platform'))

    // The declarant: 9-digit SIREN, scheme 0002, seller or buyer (G6.26, G7.52)
    ->setIssuer(
        (new Issuer())
            ->setSiren('123456789')
            ->setName('My Company')
            ->setRoleCode(IssuerRoleCode::SELLER)
    )

    // Unique per period and per declarant — the PPF runs a blocking duplicate check
    // on it (G8.05). This is not the invoice number.
    ->setTransmissionId('REPORT-2026-01')

    // The end must be strictly after the start (G6.25)
    ->setPeriod(new DateTime('2026-01-01'), new DateTime('2026-01-31'))

    ->addInvoices($invoices, BusinessProcessCode::SERVICES)
    ->build();

$xml = (new Flux10Writer())->exportReport($report);
```

`build()` validates the report, so a missing or inconsistent element fails there rather
than at the PPF.

## Reading a failure

Every rule carries its identifier from *Annexe 7*:

```php
use Einvoicing\Exceptions\ValidationException;

try {
    $report = $builder->build();
} catch (ValidationException $e) {
    $e->getBusinessRuleId();  // "G1.53"
    $e->getMessage();         // "Invoice "INV-1": the total excluding VAT (1500) does not…"
}
```

## What the library will not do for you

Three things are deliberately refused rather than guessed, because a wrong value here is
accepted by the PPF and silently produces a false declaration:

- **A missing VAT breakdown.** A zero-rate fallback is a valid rate (G1.24), so the
  declaration would go through with the whole base untaxed.
- **Currency conversion.** VAT totals and collected amounts must be in euros (G6.23,
  G6.27). For a foreign-currency invoice, set the converted value yourself with
  `setVatAmountEur()`.
- **The invoicing framework** (`B1`, `S1`, `M1`, …, TT-28). It has no EN 16931
  equivalent, and presets fill the business process with their own specification URN.

## Amended invoices

A corrective invoice references exactly one earlier invoice with its date; a credit note
at least one, in the header or on **every** line — rule G1.32.

```php
use Einvoicing\Flux10\ReferencedDocument;

$invoice
    ->setTypeCode('381')  // credit note
    ->addReferencedDocument(new ReferencedDocument('INV-ORIGIN', new DateTime('2025-12-05')));
```

## Code lists

Values from the specification are enums under `Einvoicing\Flux10\Enums`, so an invalid
code cannot be held by the model at all:

| Enum | Field | Rule |
|---|---|---|
| `TransmissionTypeCode` | TT-4 | G8.01 |
| `IssuerRoleCode` | TT-15 | G7.52 |
| `IcdSchemeId` | TT-33-1, TT-37 | G2.19 |
| `VatCategoryCode` | TT-56 | G2.31 |
| `BusinessProcessCode` | TT-28 | G1.02 |
| `TransactionCategoryCode` | TT-81 | G1.68 |
| `VatRate` | TT-57, TT-86, TT-93, TT-97 | G1.24 |
| `InvoiceTypeCode` | TT-21 | G1.01 |

Setters accept the string form too, validating on assignment:

```php
$transaction->setCategoryCode('TPS1');                       // ok
$transaction->setCategoryCode(TransactionCategoryCode::SERVICES);  // same thing
$transaction->setCategoryCode('XXXX');                       // ValueError
```

## Migrating from `export()` / `exportAll()`

Both are deprecated. They accept EN 16931 invoices and infer the envelope, which cannot
produce a conformant file: the platform matricule, the transmission identifier and the
declared period are simply not in an invoice. Deriving the period from issue dates also
yields a start equal to the end as soon as a single day is reported, which G6.25 rejects.

If you still need the derived path while migrating, supply the two things that cannot be
inferred:

```php
$writer = (new Flux10Writer())
    ->setSender((new Sender())->setMatricule('PA01')->setName('My Platform'))
    ->setPeriod((new Period())->setStartDate($start)->setEndDate($end));

$xml = $writer->exportAll($invoices);
```
