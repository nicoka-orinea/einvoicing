# Golden fixtures — Flux 10

These files are **snapshots of what the writer currently produces**, not examples of
conformant e-reporting XML. Several of them are knowingly non-conformant: dates carry
dashes, the transmission timestamp is ISO 8601, and `Sender` holds a SIREN instead of a
platform matricule. `Flux10ConformanceTest` reports each of those against its TT/TG field
and G rule.

Their purpose is to make serialization drift visible: when the format work lands, the
diff on these files shows exactly what changed in the output, field by field. A fixture
changing without a matching entry in the audit or plan means something moved by accident.

## Updating

A missing fixture is recorded automatically on the next run (the test reports itself as
incomplete that one time). To re-record after an intentional change:

```
rm tests/Writers/flux10/<name>.xml
vendor/bin/simple-phpunit --filter Flux10   # records, reports incomplete
vendor/bin/simple-phpunit --filter Flux10   # compares
```

`DateTimeString` is replaced by `@@TIMESTAMP@@` before comparison — it is generated at
export time and would otherwise make every run fail. It is checked for format by
`Flux10SemanticAssertions::checkTimestampFormat()`, not by these fixtures.

## Reference

- `AUDIT-librairie-einvoicing-flux10.md` — the non-conformities these snapshots freeze
- `PLAN-remise-a-niveau-flux10.md` — Lot 0 (this harness), Lots 1-3 (what will change them)

Both live in the orix repository under `plans/e-reporting/`.
