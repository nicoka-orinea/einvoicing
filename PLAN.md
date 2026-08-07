# Plan de correction et d'amélioration — librairie `einvoicing`

**Référence** : `AUDIT.md` (audit du 7 août 2026, base `f7282cc`). Les identifiants entre parenthèses (`CII-01`, `MOD-05`, …) renvoient aux constats de l'audit.
**Public** : ce plan est destiné à être exécuté par un agent d'implémentation (Opus / Codex) sans contexte préalable. Chaque lot est autonome, précise les fichiers, l'état actuel, l'état cible, et ses critères d'acceptation.

---

## 0. Règles d'exécution (à lire avant tout lot)

### 0.1 Commandes

```bash
# Toujours préfixer par rtk (cf. CLAUDE.md)
rtk composer install                                  # si vendor/ absent
rtk test ./vendor/bin/simple-phpunit                  # suite de tests
vendor/bin/phan --allow-polyfill-parser               # analyse statique (doit être vide)
```

### 0.2 Definition of done (chaque lot)

1. `simple-phpunit` : 0 erreur, 0 échec (y compris les tests ajoutés par le lot).
2. `phan` : 0 nouvelle erreur.
3. Aucune modification hors des fichiers listés par le lot (sauf tests).
4. Un commit par lot, message `fix(...)`/`feat(...)` conventionnel, en anglais.

### 0.3 Style et contraintes

- **Style upstream** : pas de `declare(strict_types=1)` ajouté, indentation et docblocks à l'identique des fichiers modifiés. Commentaires en **anglais** (supprimer les emojis ✅ existants dans le code touché).
- **Rétrocompatibilité** : le fork est privé, les ruptures d'API sont permises **quand le lot le dit explicitement** ; sinon conserver les signatures publiques.
- **Interdits** : ne pas toucher `src/Flux10/`, `src/Models/Report.php`, `Transaction*`, `Flux10Writer` (e-reporting, hors périmètre) — **exception** : lot 1 (correctif CI ponctuel). Ne pas ajouter de dépendance Composer. Ne pas reformater des fichiers entiers.
- **Montants** : jamais de comparaison flottante stricte ; utiliser `abs($a - $b) > 0.005` comme test d'inégalité.

### 0.4 Matériel de référence (à installer avant le lot 2)

Les spécifications PPF v3.2 sont ici (poste local) :

```
SPEC="/Users/remicaillot/Projets/worktrees/orix/clear-cobble/orix/plans/e-reporting/specifications-externes-v3.2"
```

Extraits TSV des annexes (générés lors de l'audit) : `/tmp/audit-einvoicing/spec/*.tsv`. S'ils ont disparu, les régénérer :

```bash
mkdir -p /tmp/audit-einvoicing/spec && python3 - <<'EOF'
import openpyxl, os, csv
SRC = "/Users/remicaillot/Projets/worktrees/orix/clear-cobble/orix/plans/e-reporting/specifications-externes-v3.2/2- Annexes_v3.2"
OUT = "/tmp/audit-einvoicing/spec"
files = {
    "annexe1_flux1": "20260430_Annexe 1 - Format sémantique FE e-invoicing - Flux 1 - v1.2.xlsx",
    "annexe2_flux6": "20260430_Annexe 2 - Format sémantique FE CDV - Flux 6 - V2.3.xlsx",
    "annexe7_regles": "20260430_Annexe 7 - Règles de gestion - V1.9.xlsx",
}
for key, fname in files.items():
    wb = openpyxl.load_workbook(os.path.join(SRC, fname), read_only=True, data_only=True)
    for sheet in wb.sheetnames:
        ws = wb[sheet]
        path = f"{OUT}/{key}__{sheet.replace('/', '_').replace(' ', '_')}.tsv"
        with open(path, "w", newline="") as f:
            w = csv.writer(f, delimiter="\t")
            for row in ws.iter_rows(values_only=True):
                if any(c is not None and str(c).strip() for c in row):
                    w.writerow(["" if c is None else str(c).replace("\n", " ⏎ ") for c in row])
EOF
```

### 0.5 Ordre des lots et dépendances

```
Lot 1 (CI verte)  ──►  Lot 2 (infra tests XSD)  ──►  Lot 3 (refonte CiiWriter)  ──►  Lot 4 (CiiReader)
                                                          │
Lot 5 (UBL)  ◄── indépendant de 3/4                        ▼
Lot 6 (modèle) ── requis par 3 (partiellement), 5, 8   Lot 7 (validation EN)  ──►  Lot 8 (preset France)
Lot 9 (CDAR) ── indépendant
Lot 10 (robustesse) ── indépendant, à faire en dernier
```

Le lot 6 contient des prérequis du lot 3 (BT-8, BG-11) : **exécuter 6.1 à 6.3 avant le lot 3** si les lots sont faits dans l'ordre numérique strict, ou accepter que le lot 3 laisse ces champs de côté et que le lot 6 complète le writer ensuite. **Recommandation : ordre 1, 2, 6, 3, 4, 5, 7, 8, 9, 10.**

---

## Lot 1 — Remettre la CI au vert

> Corrige : BLK-01, BLK-02, CII-05, QUA-10. Taille : ~2 h.

### 1.1 `CiiWriter::addLegalOrganization` — supprimer l'exception et le code mort

**Fichier** : `src/Writers/CiiWriter.php:806-825`.
**État actuel** : la boucle remplit `$organizationIdentifier` (identifiant de scheme `0002` parmi `$party->getIdentifiers()`) qui n'est jamais lu ; toute partie sans `companyId` de scheme `0002` déclenche `throw new \Exception(...)`.
**Cible** — remplacer intégralement la méthode par :

```php
private function addLegalOrganization(UXML $parent, Party $party): void
{
    $identifier = $party->getCompanyId();
    if ($identifier === null) {
        foreach ($party->getIdentifiers() as $candidate) {
            if ($candidate->getScheme() === '0002') {
                $identifier = $candidate;
                break;
            }
        }
    }
    if ($identifier === null) {
        return; // BT-30 is optional under EN 16931
    }
    $attrs = ($identifier->getScheme() !== null) ? ["schemeID" => $identifier->getScheme()] : [];
    $parent->add("ram:SpecifiedLegalOrganization")
        ->add("ram:ID", $identifier->getValue(), $attrs);
}
```

Aucune exception. Le scheme est émis tel quel (plus de `0002` codé en dur).

### 1.2 Erreur Phan `Flux10Writer.php`

**Fichier** : `src/Writers/Flux10Writer.php` (seule intervention autorisée hors périmètre). Phan signale `PhanPossiblyUndeclaredProperty` sur un `->value` appliqué à un `?IssuerRoleCode` (usage ligne ~229 `$parent->add('RoleCode', $roleCode->value)` alimenté par `getReportingRole()` qui retourne `?Flux10IssuerRoleCode`).
**Cible** : garde de nullité au point d'appel (`if ($roleCode !== null)` autour de l'émission, ou early-return), **sans changer le XML produit quand le rôle est non null**. Vérifier avec `vendor/bin/phan --allow-polyfill-parser` que la sortie est vide.

### 1.3 Tests `Flux10WriterTest` auto-portants

**Fichier** : `tests/Writers/Flux10WriterTest.php:28` — pointe vers `$root . '/specifications-externes-v3.2/3- XSD_v3.2/1 - E-reporting/ereporting.xsd'`, chemin hors dépôt (gitignoré).
**Cible** :
1. Copier les 5 XSD e-reporting dans le dépôt : `cp "$SPEC/3- XSD_v3.2/1 - E-reporting/"*.xsd tests/fixtures/xsd/ereporting/`.
2. Modifier le test pour pointer vers `__DIR__ . '/../fixtures/xsd/ereporting/ereporting.xsd'`.
3. Si le fichier est absent au runtime : `$this->markTestSkipped('E-reporting XSD fixtures not available')` — jamais une erreur.

### 1.4 Hygiène du dépôt

1. `git rm --cached $(git ls-files | grep '\.DS_Store')` + ajouter `.DS_Store` au `.gitignore`.
2. `composer.json` : renommer le paquet `"name": "nicoka-orinea/einvoicing"`, ajouter un bloc author pour le fork (conserver l'auteur upstream en second). Ne pas toucher au reste.

### Critères d'acceptation du lot 1

- `simple-phpunit` : **74+ tests, 0 erreur** (les 2 tests CII passent ; les 2 tests Flux10 passent ou sont skipped selon la présence des fixtures — après 1.3 ils doivent passer).
- `phan` : sortie vide.

---

## Lot 2 — Infrastructure de validation XSD en test

> Prérequis des lots 3, 5. Corrige la lacune « aucune validation XSD automatisée ». Taille : ~3 h.

### 2.1 Vendoriser les XSD PPF F1

```bash
mkdir -p tests/fixtures/xsd
cp -R "$SPEC/3- XSD_v3.2/2 - E-invoicing/F1_BASE_UBL_2.1"  tests/fixtures/xsd/
cp -R "$SPEC/3- XSD_v3.2/2 - E-invoicing/F1_FULL_UBL_2.1"  tests/fixtures/xsd/
cp -R "$SPEC/3- XSD_v3.2/2 - E-invoicing/F1_BASE_CII_D22B" tests/fixtures/xsd/
cp -R "$SPEC/3- XSD_v3.2/2 - E-invoicing/F1_FULL_CII_D22B" tests/fixtures/xsd/
```

Points d'entrée (vérifier les noms exacts après copie ; pour UBL ce sont `F1BASE_UBL-invoice-2.1.xsd`, `F1BASE_UBL-CreditNote-2.1.xsd`, `F1FULL_UBL_invoice-2.1.xsd`, `F1FULL_UBL_CreditNote-2.1.xsd` ; pour CII, prendre le fichier racine `*CrossIndustryInvoice*.xsd` de chaque dossier).

**Optionnel mais souhaitable** : si le paquet UN/CEFACT est présent sous `~/Downloads/UNCEFACT/` (il l'était pendant l'audit pour CDAR), vendoriser aussi `CrossIndustryInvoice_100pD16B.xsd` (ou D22B) + ses imports dans `tests/fixtures/xsd/CII_UNCEFACT/` — c'est le seul schéma qui accepte tous les champs EN 16931 (les profils PPF sont restrictifs). Si introuvable, ne rien télécharger : les ordres d'éléments hors profil PPF seront couverts par des assertions DOM (2.3).

### 2.2 Trait de test `ValidatesAgainstXsd`

**Nouveau fichier** : `tests/ValidatesAgainstXsd.php` (namespace `Tests`).

```php
trait ValidatesAgainstXsd {
    protected function assertValidAgainstXsd(string $xml, string $xsdPath): void {
        if (!is_file($xsdPath)) {
            $this->markTestSkipped("XSD fixture not available: $xsdPath");
        }
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $doc->schemaValidate($xsdPath);
        $errors = array_map(
            fn($e) => trim($e->message) . " (line {$e->line})",
            libxml_get_errors()
        );
        libxml_clear_errors();
        $this->assertTrue($valid, "XSD validation failed:\n" . implode("\n", $errors));
    }
}
```

### 2.3 Helper d'assertion d'ordre DOM

Dans le même trait :

```php
/** Asserts that the direct children of $parentPath appear in the relative order given
 *  (children absent from $expectedOrder are ignored; those present must be ordered). */
protected function assertChildOrder(UXML $root, string $parentPath, array $expectedOrder): void
```

Implémentation : récupérer l'élément via `$root->get($parentPath)`, itérer `childNodes` (éléments seulement), construire la liste des `localName` rencontrés, filtrer sur `$expectedOrder`, vérifier que la sous-séquence est croissante selon l'index dans `$expectedOrder`.

### Critères d'acceptation du lot 2

- Un test témoin `tests/Writers/UblWriterXsdTest.php` : exporter la facture de démonstration du README (adaptée : vendeur FR avec SIREN 0002, 1 ligne, TVA S 20 %) et la valider contre `F1BASE_UBL-invoice-2.1.xsd`. **Ce test doit passer.**

---

## Lot 6 — Extensions du modèle métier *(à exécuter avant le lot 3 — cf. §0.5)*

> Corrige : MOD-06→MOD-11, MOD-16, UBL-01, C7 (constantes). Taille : ~1,5 j.

### 6.1 Constantes de types de facture (G1.01)

**Fichier** : `src/Invoice.php`. Ajouter les constantes manquantes (texte officiel G1.01, Annexe 7) :

```php
const TYPE_SELF_BILLED_INVOICE = 389;                       // Facture auto-facturée
const TYPE_CORRECTIVE_INVOICE = 384;                        // Facture rectificative
const TYPE_SELF_BILLED_CREDIT_NOTE = 261;                   // Avoir auto-facturé
const TYPE_SELF_BILLED_FACTORED_INVOICE = 501;              // Facture auto-facturée affacturée
const TYPE_SELF_BILLED_PREPAYMENT_INVOICE = 500;            // Facture d'acompte auto-facturée
const TYPE_SELF_BILLED_CORRECTIVE_INVOICE = 471;            // Facture rectificative auto-facturée
const TYPE_FACTORED_CORRECTIVE_INVOICE = 472;               // Facture rectificative affacturée
const TYPE_SELF_BILLED_FACTORED_CORRECTIVE_INVOICE = 473;   // Facture rectificative auto-facturée affacturée
const TYPE_SELF_BILLED_FACTORED_CREDIT_NOTE = 502;          // Avoir auto-facturé affacturé
const TYPE_PREPAYMENT_CREDIT_NOTE = 503;                    // Avoir de facture d'acompte
```

Ne **pas** supprimer les constantes existantes (types interdits en France : le contrôle relève du preset, lot 8). Ajouter deux constantes de commodité **publiques** utilisées par les writers et le preset :

```php
/** UNTDID 1001 codes rendered as /CreditNote in UBL */
const CREDIT_NOTE_TYPES = [81, 83, 261, 381, 396, 502, 503, 532];
/** Invoice types allowed by French rule G1.01 */
const FR_ALLOWED_TYPES = [380, 389, 393, 501, 386, 500, 384, 471, 472, 473, 261, 381, 396, 502, 503];
```

### 6.2 BT-8 — code d'exigibilité de la TVA

**Fichier** : `src/Invoice.php`. Nouvelle propriété + accesseurs, après `taxPointDate` :

```php
protected $vatPointDateCode = null;

/** Get VAT point date code (BT-8, UNTDID 2005 subset: "5", "29", "72") */
public function getVatPointDateCode(): ?string;
public function setVatPointDateCode(?string $code): self;
```

Pas de contrôle de valeur ici (le preset FR s'en charge). Émission UBL/CII : lots 5.4 et 3.6.

### 6.3 BG-11 — représentant fiscal du vendeur

**Fichier** : `src/Invoice.php`. Réutiliser la classe `Party` :

```php
protected $taxRepresentative = null;

/** Get seller tax representative (BG-11) */
public function getTaxRepresentative(): ?Party;
public function setTaxRepresentative(?Party $party): self;
```

Champs utilisés d'une `Party` en contexte BG-11 : `name` (BT-62), `vatNumber` (BT-63), adresse postale (BG-12). Émission : lots 5.5 (UBL) et 3.5 (CII).

### 6.4 BT-148 / BT-147 — prix brut et rabais tarifaire

**Fichier** : `src/InvoiceLine.php` :

```php
protected $grossPrice = null;

/** Get item gross price (BT-148), for the same base quantity as the net price */
public function getGrossPrice(): ?float;
public function setGrossPrice(?float $grossPrice): self;
```

BT-147 (rabais) est **dérivé** : `grossPrice - price` ; ne pas créer de champ. Contrainte documentée en docblock : si `grossPrice !== null`, il doit être ≥ `price` (contrôlé par la règle BR-28 au lot 7).

### 6.5 BT-90 — identifiant créancier SEPA (ICS)

**Fichier** : `src/Payments/Mandate.php` :

```php
protected $creditorIdentifier = null;
public function getCreditorIdentifier(): ?string;
public function setCreditorIdentifier(?string $creditorIdentifier): self;
```

Émission UBL : `cac:PaymentMeans/cac:PaymentMandate` existe déjà ? Vérifier `UblWriter::addPaymentMeansNode` — si le mandat y est écrit, ajouter BT-90 selon le mapping EN 16931 UBL : `cbc:ID` du `cac:PartyIdentification` du `cac:PayeeParty` avec `@schemeID="SEPA"` **n'est pas porté par le mandat** ; pour rester simple et symétrique, émettre BT-90 en CII seulement (`ram:CreditorReferenceID`, premier enfant de `ApplicableHeaderTradeSettlement`, cf. lot 3.4) et documenter la non-émission UBL dans le docblock. *(Décision assumée : l'usage cible est Factur-X/CII.)*

### 6.6 BT-16 et BT-18 — références de livraison et d'objet

**Fichier** : `src/Invoice.php` :

```php
protected $despatchAdviceReference = null;   // BT-16
protected $invoicedObjectIdentifier = null;  // BT-18, Identifier (son scheme = BT-18-1)

public function getDespatchAdviceReference(): ?string;
public function setDespatchAdviceReference(?string $reference): self;
public function getInvoicedObjectIdentifier(): ?Identifier;
public function setInvoicedObjectIdentifier(?Identifier $identifier): self;
```

Émission :
- **UBL** : BT-16 → `cac:DespatchDocumentReference/cbc:ID` (après `cac:BillingReference`, avant `cac:ReceiptDocumentReference`/`cac:OriginatorDocumentReference` — vérifier la séquence du XSD F1 avant insertion) ; BT-18 → `cac:AdditionalDocumentReference` avec `cbc:ID` (+`@schemeID`) et `cbc:DocumentTypeCode` = `130`. Lecture symétrique dans `UblReader` : un `AdditionalDocumentReference` avec `DocumentTypeCode=130` alimente `setInvoicedObjectIdentifier` et **ne doit plus** être traité comme pièce jointe.
- **CII** : BT-16 → `ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID` dans `ApplicableHeaderTradeDelivery` (après `ActualDeliverySupplyChainEvent`) ; BT-18 → `ram:AdditionalReferencedDocument` (`IssuerAssignedID`, `TypeCode=130`, `ReferenceTypeCode` = scheme) dans `ApplicableHeaderTradeAgreement`.

### Critères d'acceptation du lot 6

- Tests unitaires modèle : accesseurs (aller-retour de valeurs, null).
- `InvoiceTest` : `FR_ALLOWED_TYPES` contient exactement les 15 codes listés (test figé).

---

## Lot 3 — Refonte du writer CII

> Corrige : CII-01→CII-09, CII-11 (écriture), CII-12, CII-13, CII-16, CII-19, CII-22, QUA-01, QUA-04, QUA-09, m6. **C'est le lot le plus important.** Taille : ~3 j.

### 3.0 Principe directeur

`CiiWriter` ne calcule **plus rien** : toutes les valeurs monétaires proviennent de `InvoiceTotals::fromInvoice($invoice)` (comme `UblWriter`). **Supprimer intégralement** :

- les propriétés `$computedVatBreakdownAfterHeaderAC`, `$headerAllowanceTotal`, `$headerChargeTotal` (`CiiWriter.php:22-29`) ;
- les méthodes `computeVatBreakdownAfterHeaderAdjustments()` (`:402-446`), `splitHeaderAllowanceOrChargeByVat()` (`:455-505`), `addHeaderTradeTax()` (`:507-517`, code mort), `addHeaderAllowanceOrChargeSplitByVat()` (`:519-595`), `addHeaderAllowanceOrChargeSingle()` (`:597-663`).

### 3.1 Helpers de formatage

Remplacer `formatCurrency()` par deux helpers :

```php
/** Monetary amounts: always 2 decimals (EN 16931 / G1.14) */
private function formatAmount(float $amount): string {
    return number_format(round($amount, 2, PHP_ROUND_HALF_UP), 2, '.', '');
}

/** Quantities, unit prices and percentages: up to $maxDecimals, trailing zeros trimmed */
private function formatDecimal(float $value, int $maxDecimals = 4): string {
    $s = number_format($value, $maxDecimals, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' || $s === '-') ? '0' : $s;
}
```

Usage : `formatAmount` pour tous les `*Amount` ; `formatDecimal` pour `BasisQuantity`, `BilledQuantity`, `CalculationPercent`, `RateApplicablePercent`, `ChargeAmount` (prix unitaires : `formatDecimal($v, 4)`). *(Corrige QUA-09.)*

### 3.2 Lignes (`addLineItems`) — corrections

1. **CII-02** — supprimer les lignes 144-145 (`$baseQty = max(1.0, ...)`, division). Nouveau code :

```php
$baseQty = (float) $line->getBaseQuantity();
if ($baseQty <= 0) {
    throw new ExportException("Line base quantity must be positive"); // classe créée au lot 10.3, utiliser \InvalidArgumentException en attendant
}
$netUnitPrice = (float) $line->getPrice(); // BT-146 = price for BT-149 units, no division
```

`BasisQuantity` émis quand `$baseQty != 1.0`, valeur `$this->formatDecimal($baseQty)`, attribut `unitCode` inchangé.

2. **Prix brut (BT-148/147)** : `GrossPriceProductTradePrice` émis **uniquement** si `$line->getGrossPrice() !== null` :

```
ram:GrossPriceProductTradePrice
    ram:ChargeAmount            = formatDecimal(grossPrice, 4)
    ram:AppliedTradeAllowanceCharge          (si grossPrice != price)
        ram:ChargeIndicator/udt:Indicator = false
        ram:ActualAmount        = formatAmount(grossPrice - price)   ← BT-147
```

Si `grossPrice === null` : ne pas émettre `GrossPriceProductTradePrice` du tout (le XSD PPF FULL l'exige ? — **vérifier** : si le XSD FULL impose `GrossPriceProductTradePrice` 1..1, alors l'émettre avec `ChargeAmount = netUnitPrice` en fallback, comme aujourd'hui ; trancher en lisant `tests/fixtures/xsd/F1_FULL_CII_D22B/*.xsd`, élément `LineTradeAgreementType`).

3. **Ordre des enfants de `SpecifiedLineTradeAgreement`** (CII-09) : `BuyerOrderReferencedDocument` → `GrossPriceProductTradePrice` → `NetPriceProductTradePrice`. (Actuellement l'ordre inverse : `:157-160` après les prix.)

4. **Ordre des enfants de `SpecifiedLineTradeSettlement`** (CII-09) : `ApplicableTradeTax` → `BillingSpecifiedPeriod` → `SpecifiedTradeAllowanceCharge` (× n) → `SpecifiedTradeSettlementLineMonetarySummation` → `ReceivableSpecifiedTradeAccountingAccount`. Déplacer le bloc période (`:195-197`) **avant** les allowances/charges, et le bloc `Receivable...` (`:199-202`) **après** la summation (`:205-207`).

5. **`addLineTradeTax`** (CII-04) : n'émettre `ram:RateApplicablePercent` que si `$line->getVatRate() !== null` :

```php
$tax->add("ram:TypeCode", "VAT");
$tax->add("ram:CategoryCode", $line->getVatCategory());
if ($line->getVatRate() !== null) {
    $tax->add("ram:RateApplicablePercent", $this->formatDecimal($line->getVatRate(), 2));
}
```

6. **BT-131** : arrondir via la matrice : `$this->formatAmount($invoice->round((float)$line->getNetAmount(), 'line/netAmount'))` (nécessite de passer `$invoice` — la méthode l'a déjà).

### 3.3 En-tête agreement (`addHeaderAgreement`) — corrections

Ordre cible des enfants de `ram:ApplicableHeaderTradeAgreement` (XSD `HeaderTradeAgreementType`) :

```
ram:BuyerReference
ram:SellerTradeParty
ram:BuyerTradeParty
ram:SellerTaxRepresentativeTradeParty        ← nouveau (BG-11, si getTaxRepresentative() non null)
ram:SellerOrderReferencedDocument            ← BT-14 (AVANT BuyerOrder — CII-08)
ram:BuyerOrderReferencedDocument             ← BT-13
ram:ContractReferencedDocument               ← BT-12
ram:AdditionalReferencedDocument             ← BT-18 (lot 6.6)
```

`SellerTaxRepresentativeTradeParty` : émis via `addParty()` (name, adresse, `SpecifiedTaxRegistration` schemeID `VA` = BT-63).

### 3.4 En-tête settlement (`addHeaderSettlement`) — réécriture

Ordre cible des enfants de `ram:ApplicableHeaderTradeSettlement` (ordre Factur-X/XSD, corrige CII-06) :

```
ram:CreditorReferenceID                       ← BT-90 (si un payment a un mandate avec creditorIdentifier)
ram:PaymentReference                          ← BT-83 (premier payment ayant un id)
ram:TaxCurrencyCode                           ← BT-6  (AVANT InvoiceCurrencyCode)
ram:InvoiceCurrencyCode                       ← BT-5
ram:PayeeTradeParty                           ← BT-59 sqq. (si getPayee() non null) — via addParty()
ram:SpecifiedTradeSettlementPaymentMeans      ← × n (cf. 3.7)
ram:ApplicableTradeTax                        ← × n (cf. 3.5)
ram:BillingSpecifiedPeriod                    ← BG-14 (CII-18 : émettre si Invoice::getPeriodStartDate/EndDate non null, même structure que addBillingPeriod ligne)
ram:SpecifiedTradeAllowanceCharge             ← × n (cf. 3.6)
ram:SpecifiedTradePaymentTerms                ← inchangé
ram:SpecifiedTradeSettlementHeaderMonetarySummation   ← cf. 3.8
ram:InvoiceReferencedDocument                 ← × n, inchangé (BT-25/26)
ram:ReceivableSpecifiedTradeAccountingAccount ← BT-19 (EN DERNIER — CII-06)
```

### 3.5 Ventilation TVA d'en-tête (corrige CII-01, CII-04, CII-19)

Source unique : `$totals = $invoice->getTotals()` puis itération sur **toutes** les entrées de `$totals->vatBreakdown` (y compris `rate === null`, catégorie O). Ordre des enfants de `ram:ApplicableTradeTax` (XSD `TradeTaxType`) :

```
ram:CalculatedAmount        = formatAmount(b->taxAmount)
ram:TypeCode                = "VAT"
ram:ExemptionReason         = b->exemptionReason          (si non null)   ← BT-120
ram:BasisAmount             = formatAmount(b->taxableAmount)
ram:CategoryCode            = b->category
ram:ExemptionReasonCode     = b->exemptionReasonCode      (si non null)   ← BT-121
ram:DueDateTypeCode         = invoice->getVatPointDateCode() (si non null) ← BT-8
ram:RateApplicablePercent   = formatDecimal(b->rate, 2)   (si rate non null)
```

BT-7 : si `invoice->getTaxPointDate()` non null, émettre `ram:TaxPointDate/udt:DateString` (`@format=102`) juste avant `DueDateTypeCode`. BT-7 et BT-8 sont mutuellement exclusifs (BR-CO-3, contrôlé au lot 7 — le writer émet ce qu'on lui donne).

### 3.6 Remises/charges d'en-tête (corrige CII-01/CII-03)

**Une** `ram:SpecifiedTradeAllowanceCharge` **par objet** `AllowanceOrCharge` du modèle (plus aucun éclatement par taux). Chaque objet porte sa propre TVA via `VatTrait` — comportement identique à l'UBL. Ordre des enfants (déjà correct au niveau ligne, réutiliser la même méthode que `addLineAllowanceOrCharge` en la généralisant) :

```
ram:ChargeIndicator/udt:Indicator = true|false
ram:CalculationPercent  = formatDecimal(amount, 2)                     (si isPercentage)
ram:BasisAmount         = formatAmount(totals->netAmount)              (si isPercentage)
ram:ActualAmount        = formatAmount(invoice->round($item->getEffectiveAmount($totals->netAmount), 'line/allowanceChargeAmount'))
ram:ReasonCode, ram:Reason                                             (si non null)
ram:CategoryTradeTax { TypeCode=VAT, CategoryCode, RateApplicablePercent si non null }
```

La base des pourcentages est `$totals->netAmount` (identique à `InvoiceTotals::fromInvoice`, `InvoiceTotals.php:110/118`) — cohérence garantie par construction.

### 3.7 Moyens de paiement (corrige CII-16 + symétrie carte/mandat)

Dans `addPaymentMeans` : émettre **un** `ram:SpecifiedTradeSettlementPaymentMeans` **par `Transfer`** (et un seul si aucun transfer). Ordre des enfants (XSD `TradeSettlementPaymentMeansType`) :

```
ram:TypeCode            = meansCode           (si non null)
ram:Information         = meansText           (si non null)
ram:ApplicableTradeSettlementFinancialCard { ram:ID = card->getPan(), ram:CardholderName = card->getHolder() }   (si getCard() non null)
ram:PayerPartyDebtorFinancialAccount { ram:IBANID = mandate->getAccount() }   (si getMandate()?->getAccount() non null)
ram:PayeePartyCreditorFinancialAccount { ram:IBANID, ram:AccountName }        (pour le transfer courant)
ram:PayeeSpecifiedCreditorFinancialInstitution { ram:BICID = provider }       (si non null)
```

Conserver la garde existante « transfer 58 sans compte → ignoré » (`:697-699`).

### 3.8 Totaux (`addMonetarySummation`) — réécriture (corrige CII-01, CII-07, CII-13)

Toutes les valeurs depuis `$totals` ; ordre exact (XSD `TradeSettlementHeaderMonetarySummationType`) :

```
ram:LineTotalAmount      = formatAmount(totals->netAmount)                         BT-106
ram:ChargeTotalAmount    = formatAmount(totals->chargesAmount)     si != 0         BT-108 (AVANT Allowance — CII-07)
ram:AllowanceTotalAmount = formatAmount(totals->allowancesAmount)  si != 0         BT-107
ram:TaxBasisTotalAmount  = formatAmount(totals->taxExclusiveAmount)                BT-109
ram:TaxTotalAmount       = formatAmount(totals->vatAmount)  @currencyID=totals->currency        BT-110
ram:TaxTotalAmount       = formatAmount(totals->customVatAmount) @currencyID=totals->vatCurrency
                           si vatCurrency non null ET customVatAmount non null      BT-111 (CII-13)
ram:RoundingAmount       = formatAmount(totals->roundingAmount)    si != 0.0       BT-114 (AVANT GrandTotal — CII-07)
ram:GrandTotalAmount     = formatAmount(totals->taxInclusiveAmount)                BT-112
ram:TotalPrepaidAmount   = formatAmount(totals->paidAmount)        si != 0         BT-113
ram:DuePayableAmount     = formatAmount(totals->payableAmount)                     BT-115
```

### 3.9 Livraison (`addHeaderDelivery`) — corrige CII-12

```php
private function addHeaderDelivery(UXML $parent, Invoice $invoice): void
{
    $delivery = $parent->add("ram:ApplicableHeaderTradeDelivery"); // element is mandatory (1..1) in CII XSD
    $date = $invoice->getDelivery()?->getDate();
    if ($date !== null) {
        $delivery->add("ram:ActualDeliverySupplyChainEvent")
            ->add("ram:OccurrenceDateTime")
            ->add("udt:DateTimeString", $date->format("Ymd"), ["format" => "102"]);
    }
    if ($invoice->getDespatchAdviceReference() !== null) {   // BT-16, lot 6.6
        $delivery->add("ram:DespatchAdviceReferencedDocument")
            ->add("ram:IssuerAssignedID", $invoice->getDespatchAdviceReference());
    }
}
```

**Plus jamais** de date de livraison par défaut égale à la date d'émission.

### 3.10 Parties (`addParty`) — corrige m6

Nouveau mapping (aligné sur la sémantique EN/UBL) :

- `ram:ID` / `ram:GlobalID` ← `$party->getIdentifiers()` (BT-29/BT-46) : pour chaque identifiant, `ram:GlobalID` avec `@schemeID` si scheme non null, sinon `ram:ID`. Les `ram:ID` d'abord, puis les `ram:GlobalID` (ordre XSD).
- `ram:Name` ← name ; `ram:SpecifiedLegalOrganization/ram:ID` ← `companyId` (méthode 1.1) + `ram:TradingBusinessName` ← `getTradingName()` si non null (corrige CII-17 partiellement).
- `ram:DefinedTradeContact` (BG-6/BG-9, corrige CII-17) : si `getContactName()`/`getContactPhone()`/`getContactEmail()` non null : `ram:PersonName`, `ram:TelephoneUniversalCommunication/ram:CompleteNumber`, `ram:EmailURIUniversalCommunication/ram:URIID`. Position : après `SpecifiedLegalOrganization`, avant `PostalTradeAddress`.
- Le `companyId` n'est **plus** dupliqué dans `GlobalID`.

Ordre complet des enfants de `SellerTradeParty`/`BuyerTradeParty` : `ram:ID`× → `ram:GlobalID`× → `ram:Name` → `ram:SpecifiedLegalOrganization` → `ram:DefinedTradeContact` → `ram:PostalTradeAddress` → `ram:URIUniversalCommunication` → `ram:SpecifiedTaxRegistration`.

### 3.11 BT-23 (corrige CII-11 côté écriture)

Inchangé structurellement (`addContext`), mais ajouter un docblock : BT-23 est 1..1 pour le PPF ; le contrôle d'obligation appartient au preset FR (lot 8). Ne pas émettre d'élément vide.

### Critères d'acceptation du lot 3 (tests à créer dans `tests/Writers/CiiWriterTest.php` + nouveau `tests/Writers/CiiWriterCalculationTest.php`)

Chaque scénario de l'audit devient un test chiffré :

| Test | Scénario | Assertions |
|---|---|---|
| `testHeaderAllowanceAppliedOnce` | 1 ligne 100 €, TVA S 20 %, remise doc 10 % (percentage, vatRate 20, vatCategory S) | `ActualAmount=10.00`, breakdown : `BasisAmount=90.00`, `CalculatedAmount=18.00`, `TaxBasisTotalAmount=90.00`, `GrandTotalAmount=108.00`, `AllowanceTotalAmount=10.00` |
| `testBaseQuantityPrice` | `setPrice(100, 2)`, quantity 2 | `NetPriceProductTradePrice/ChargeAmount=100`, `BasisQuantity=2`, `LineTotalAmount=100.00` |
| `testFractionalBaseQuantity` | `setPrice(10, 0.5)`, quantity 1 | `BasisQuantity=0.5`, `LineTotalAmount=20.00` |
| `testCategoryOInvoice` | 1 ligne 100 €, catégorie O, rate null, exemption reason "Hors champ TVA" | exactement 1 `ApplicableTradeTax` header : `CategoryCode=O`, pas de `RateApplicablePercent`, `CalculatedAmount=0.00`, `BasisAmount=100.00`, `ExemptionReason` présent ; ligne : pas de `RateApplicablePercent` vide |
| `testMultiRateHeaderAllowance` | 2 lignes (100 € @20 %, 100 € @5.5 %), remise doc 10 % vatCategory S vatRate 20 | **1 seule** `SpecifiedTradeAllowanceCharge`, `ActualAmount=20.00` ; 2 breakdowns dont S/20 : base `80.00` |
| `testSettlementChildOrder` | facture avec BT-6, BT-83, BT-19, payment, avoir avec BT-25 | `assertChildOrder(..., 'ApplicableHeaderTradeSettlement', [CreditorReferenceID?, PaymentReference, TaxCurrencyCode, InvoiceCurrencyCode, SpecifiedTradeSettlementPaymentMeans, ApplicableTradeTax, SpecifiedTradeAllowanceCharge, SpecifiedTradePaymentTerms, SpecifiedTradeSettlementHeaderMonetarySummation, InvoiceReferencedDocument, ReceivableSpecifiedTradeAccountingAccount])` |
| `testMonetarySummationChildOrder` | facture avec remise + charge + rounding + prepaid | ordre : LineTotal, ChargeTotal, AllowanceTotal, TaxBasisTotal, TaxTotal, RoundingAmount, GrandTotal, TotalPrepaid, DuePayable |
| `testAgreementChildOrder` | facture avec BT-13 + BT-14 | SellerOrder avant BuyerOrder |
| `testNoDeliveryDateFabricated` | facture sans delivery | `ApplicableHeaderTradeDelivery` présent et **vide** |
| `testBt111DualCurrency` | vatCurrency USD + customVatAmount 12.34 | 2 `TaxTotalAmount`, currencyID EUR puis USD |
| `testPartyWithoutSiren` | vendeur étranger sans companyId | export réussi, pas de `SpecifiedLegalOrganization` |
| `testXsdValidBase` | facture nominale FR (SIREN 0002, TVA S) | `assertValidAgainstXsd(..., 'F1_BASE_CII_D22B/...')` |

Adapter les tests existants qui figeaient l'ancien comportement (notamment le roundtrip baseQuantity).

---

## Lot 4 — Symétrie du reader CII

> Corrige : CII-10, CII-11 (lecture), CII-14, QUA-02, QUA-11, m6 (lecture). Taille : ~1 j.
> **Fichier** : `src/Readers/CiiReader.php`.

### 4.1 Préservation du preset (QUA-02 / CII-11)

`import()` lignes 36-51 — remplacer par : lire d'abord `$businessProcess` et `$specification` dans des variables locales, créer l'invoice (avec preset si trouvé), **puis** :

```php
if ($specification !== null) { $invoice->setSpecification($specification); }   // document value wins over preset default
if ($businessProcess !== null) { $invoice->setBusinessProcess($businessProcess); }
```

### 4.2 BT-25/BT-26 (CII-10)

Dans le bloc settlement, ajouter :

```php
foreach ($settlement->getAll("ram:InvoiceReferencedDocument") as $refNode) {
    $value = $refNode->get("ram:IssuerAssignedID")?->asText();
    if ($value === null) continue;
    $dateNode = $refNode->get("ram:FormattedIssueDateTime/qdt:DateTimeString")
        ?? $refNode->get("ram:FormattedIssueDateTime/udt:DateTimeString");
    $invoice->addPrecedingInvoiceReference(new InvoiceReference(
        $value,
        ($dateNode !== null) ? $this->parseDateTime($dateNode) : null
    ));
}
```

(Vérifier la signature exacte de `InvoiceReference::__construct` dans `src/InvoiceReference.php` et l'API du trait `PrecedingInvoiceReferencesTrait` avant d'écrire.)

### 4.3 BT-7 / BT-8 (CII-14)

Supprimer le bloc `TaxApplicableTradeCurrencyExchange` (`:191-194`). Lire à la place, sur le **premier** `ram:ApplicableTradeTax` du settlement :

- `ram:TaxPointDate/udt:DateString` → `setTaxPointDate(parseDateTime(...))` ;
- `ram:DueDateTypeCode` → `setVatPointDateCode(...)`.

**Corriger aussi la fixture** `tests/Readers/CiiReaderTest.php:228-230` qui verrouille l'ancien mauvais mapping.

### 4.4 Moyens de paiement multiples (QUA-11)

`:140-160` : remplacer `get(...)` par `getAll("ram:SpecifiedTradeSettlementPaymentMeans")` et boucler ; pour chaque nœud, lire aussi tous les `PayeePartyCreditorFinancialAccount` (`getAll`), la carte (`ApplicableTradeSettlementFinancialCard` → `Payment::setCard`) et le compte débiteur (`PayerPartyDebtorFinancialAccount/ram:IBANID` → mandate). `PaymentReference` reste lu une fois au niveau settlement et posé sur le premier payment.

### 4.5 Remises/charges d'en-tête en % (CII-03, côté lecture)

`addAllowanceOrCharge()` (`:317-349`) : quand `ram:CalculationPercent` est présent **et** `ram:BasisAmount` aussi, garder le pourcentage (`markAsPercentage`) ; c'est correct **une fois que le writer n'éclate plus** (une seule occurrence par remise). Aucun changement de code nécessaire ici après le lot 3 — ajouter simplement le test de roundtrip (critères ci-dessous).

### 4.6 Ventilation, parties, en-tête (m6, CII-19 lecture)

- `parsePartyNode` : `ram:GlobalID` → `addIdentifier(...)` (plus `setCompanyId`) ; `ram:SpecifiedLegalOrganization/ram:ID` → `setCompanyId(...)` (plus `addIdentifier`). Miroir exact du lot 3.10.
- Lire `ExemptionReason`/`ExemptionReasonCode` des `ApplicableTradeTax` header : les reporter sur les lignes n'est pas possible — les stocker n'a pas de réceptacle dédié ; **décision** : reporter `exemptionReason`/`exemptionReasonCode` sur chaque `InvoiceLine` dont `(category, rate)` correspond au breakdown et qui n'en a pas déjà. Documenter dans le docblock d'`import()`.
- BG-14 header : lire `ram:BillingSpecifiedPeriod` du settlement → `setPeriodStartDate/EndDate` de l'invoice.
- `SellerTaxRepresentativeTradeParty` → `setTaxRepresentative(parsePartyNode(...))`.

### Critères d'acceptation du lot 4 (nouveau `tests/Integration/CiiRoundtripTest.php`)

| Test | Assertion |
|---|---|
| `testAmountRoundtrip` | facture 2 lignes (100 @20, 100 @5.5) + remise doc 10 % + charge doc 5 € : export → import → `getTotals()` identiques champ à champ (netAmount, allowancesAmount, chargesAmount, vatAmount, taxExclusiveAmount, taxInclusiveAmount, payableAmount) à 0.005 près |
| `testBaseQuantityRoundtrip` | `setPrice(100, 2)` : après roundtrip `getPrice()==100.0` et `getBaseQuantity()==2.0` |
| `testCreditNoteReferencesRoundtrip` | avoir 381 + 1 preceding reference avec date : après roundtrip, référence et date présentes |
| `testBusinessProcessPreservedWithPreset` | XML avec guideline Peppol + BusinessProcess `B1` : après import, `getBusinessProcess()=='B1'` et `getSpecification()` = valeur du document |
| `testCompanyIdRoundtrip` | vendeur avec companyId 0002 + identifier 0009 : mapping conservé après roundtrip |

---

## Lot 5 — Correctifs UBL

> Corrige : UBL-02, UBL-04, UBL-05, UBL-06, UBL-01 (écriture/lecture), BG-11 UBL. Taille : ~1 j.
> **Fichiers** : `src/Writers/UblWriter.php`, `src/Readers/UblReader.php`.

### 5.1 Avoirs 261/502/503 (UBL-02)

`UblWriter::isCreditNoteProfile()` (`:225-234`) : remplacer le tableau par `Invoice::CREDIT_NOTE_TYPES` (lot 6.1). Vérifier que `UblReader` mappe déjà `/CreditNote` correctement (oui — XPath alternatifs).

### 5.2 BT-11 sur avoir (UBL-04)

Dans `export()` autour de `:148-152` : si `isCreditNoteProfile`, émettre le project reference comme :

```
cac:AdditionalDocumentReference
    cbc:ID              = projectReference
    cbc:DocumentTypeCode = 50
```

à la position des `AdditionalDocumentReference` (celle des pièces jointes), **pas** `cac:ProjectReference` (inexistant du schéma CreditNote). Sur Invoice : comportement actuel conservé.
`UblReader` : lors du parsing des `AdditionalDocumentReference`, si `cbc:DocumentTypeCode == '50'` → `setProjectReference(cbc:ID)` et **ne pas** créer d'Attachment ; si `== '130'` → BT-18 (lot 6.6) ; sinon comportement actuel.

### 5.3 BT-7 sur avoir (UBL-05)

Dans `export()` : pour le profil CreditNote, émettre `cbc:TaxPointDate` **avant** `cbc:CreditNoteTypeCode` (ordre XSD CN : `IssueDate` → `TaxPointDate` → `CreditNoteTypeCode` → `Note`). Pour Invoice, ordre actuel conservé (`DueDate` → `InvoiceTypeCode` → `Note` → `TaxPointDate`). Implémentation : sortir l'émission de TaxPointDate dans une variable/condition selon le profil.

### 5.4 BT-8 (UBL-01)

- `addPeriodNode()` (`:256-272`) : accepter un 3ᵉ cas — pour la source `Invoice`, émettre aussi `cbc:DescriptionCode` (après StartDate/EndDate) quand `getVatPointDateCode()` non null ; créer le nœud `cac:InvoicePeriod` même sans dates si le code est présent. (Les `InvoiceLine` n'ont pas ce champ — garder la signature en testant `method_exists` ou, plus propre, un paramètre optionnel `?string $descriptionCode = null` passé par l'appelant document.)
- `UblReader` : lire `cac:InvoicePeriod/cbc:DescriptionCode` → `setVatPointDateCode`.

### 5.5 BG-11 (UBL-03)

- Writer : émettre `cac:TaxRepresentativeParty` **après** `cac:PayeeParty` et avant `cac:Delivery` quand `getTaxRepresentative()` non null :

```
cac:TaxRepresentativeParty
    cac:PartyName/cbc:Name          = name (BT-62)
    cac:PostalAddress { ... }        = même helper que les autres parties (BG-12)
    cac:PartyTaxScheme { cbc:CompanyID = vatNumber, cac:TaxScheme/cbc:ID = VAT }   (BT-63)
```

- Reader : parser symétriquement → `setTaxRepresentative`.

### 5.6 BT-9 sur avoir (UBL-06)

Dans `export()` (`:179-181`) : ne passer `$dueDate` qu'au **premier** `addPaymentMeansNode` (index 0 de la boucle), `null` aux suivants.

### Critères d'acceptation du lot 5 (compléter `tests/Writers/UblWriterTest.php`)

| Test | Assertion |
|---|---|
| `testSelfBilledCreditNoteUsesCreditNoteRoot` | `setType(261)` → racine `CreditNote`, `cbc:CreditNoteTypeCode=261` ; XSD-valide contre `F1BASE_UBL-CreditNote-2.1.xsd` |
| `testProjectReferenceOnCreditNote` | avoir + projectReference → pas de `cac:ProjectReference`, un `AdditionalDocumentReference` TypeCode 50 ; XSD-valide ; roundtrip conserve la valeur |
| `testTaxPointDateOrderOnCreditNote` | avoir + taxPointDate → XSD-valide |
| `testDueDateSinglePaymentDueDate` | avoir + dueDate + 2 payments → exactement 1 `cbc:PaymentDueDate` |
| `testVatPointDateCode` | facture + `setVatPointDateCode('72')` → `cac:InvoicePeriod/cbc:DescriptionCode=72` ; roundtrip conserve |
| `testTaxRepresentative` | BG-11 complet → XSD-valide, roundtrip conserve name/vatNumber/pays |

---

## Lot 7 — Moteur de validation EN 16931

> Corrige : MOD-01, MOD-03, MOD-04, MOD-14, MOD-15 (partiel). Taille : ~2 j.
> **Fichiers** : `src/Traits/InvoiceValidationTrait.php`, `src/Models/InvoiceTotals.php`.
> **Référence des textes** : `/tmp/audit-einvoicing/spec/annexe7_regles__Règles_de_la_norme_EN16931.tsv` — les messages d'erreur doivent reprendre le texte anglais officiel de chaque règle.

### 7.1 Correctif d'arrondi BR-CO-10 (MOD-03) — *à faire en premier*

`InvoiceTotals::fromInvoice()`, `InvoiceTotals.php:101-105` :

```php
foreach ($inv->getLines() as $line) {
    $lineNetAmount = $inv->round($line->getNetAmount() ?? 0.0, 'line/netAmount');  // round BEFORE summing (BR-CO-10)
    $totals->netAmount += $lineNetAmount;
    self::updateVatMap($vatMap, $line, $lineNetAmount);
}
```

Et harmoniser les clés de matrice (MOD-13) : `:110` et `:118` utilisent `'invoice/allowancesChargesAmount'` (pas `line/…`) ; `:126` utilise `'invoice/taxableAmount'` (nouvelle clé, fallback `''` automatique).
Vérifier que `UblWriter` écrit BT-131 avec la même clé `line/netAmount` (c'est le cas, `UblWriter.php:893-899`).

### 7.2 Garde-fou taux manquant (MOD-04)

Nouvelle règle (voir 7.3) plutôt que changement de calcul : `BR-S-05` — toute ligne/allowance/charge en catégorie S avec `vatRate === null` ou `<= 0` est une erreur de validation. **Et** dans `InvoiceTotals.php:127`, remplacer la coercition silencieuse par : `$item->taxAmount = ($item->rate === null) ? 0.0 : $inv->round(...)` (explicite, comportement identique).

### 7.3 Règles TVA par catégorie — implémentation data-driven

Dans `InvoiceValidationTrait`, ajouter une table privée et une boucle générique :

```php
// category => [ruleIdPrefix, rateRule, exemptionRule, sellerVatRequired, buyerVatRequired, forbidVatIds]
// rateRule: 'positive' | 'zero' | 'null' ; exemptionRule: 'required' | 'forbidden'
private const VAT_CATEGORY_RULES = [
    'S'  => ['BR-S',  'positive', 'forbidden', true,  false, false],
    'Z'  => ['BR-Z',  'zero',     'forbidden', true,  false, false],
    'E'  => ['BR-E',  'zero',     'required',  true,  false, false],
    'AE' => ['BR-AE', 'zero',     'required',  true,  true,  false],
    'K'  => ['BR-IC', 'zero',     'required',  true,  true,  false],
    'G'  => ['BR-G',  'zero',     'required',  true,  false, false],
    'O'  => ['BR-O',  'null',     'required',  false, false, true],
];
```

La boucle parcourt `$inv->getTotals()->vatBreakdown` + toutes les lignes/allowances/charges, et vérifie :

1. `rateRule` : `positive` → `rate !== null && rate > 0` ; `zero` → `rate !== null && rate == 0` ; `null` → `rate === null`.
2. `exemptionRule` sur le breakdown : `required` → `exemptionReasonCode !== null || exemptionReason !== null` ; `forbidden` → les deux null.
3. `sellerVatRequired` : le vendeur (ou le représentant fiscal, lot 6.3) a `getVatNumber() !== null` — ou `getTaxRegistrationId()` pour S (BR-S-2 accepte BT-32).
4. `buyerVatRequired` : `getBuyer()->getVatNumber() !== null`.
5. `forbidVatIds` (O) : ni vendeur, ni acheteur, ni représentant n'ont de `vatNumber` (BR-O-10) ; et si un breakdown O existe, **aucun** autre breakdown d'une autre catégorie (BR-O-11/12/13/14).
6. Calcul (BR-*-08/09 famille) : pour chaque breakdown, `abs(taxAmount − taxableAmount × rate/100) <= 0.005` sauf catégorie O/null (tax == 0).

L'identifiant de règle levé doit être le vrai code EN (ex. `BR-E-10`) — mapper précisément en consultant le TSV pour chaque contrôle (ne pas inventer de numérotation).

### 7.4 Règles BR-CO

À ajouter dans `getDefaultRules()` (formules exactes, tolérance 0.005) :

| ID | Contrôle |
|---|---|
| BR-CO-3 | `taxPointDate` et `vatPointDateCode` non simultanément non-null |
| BR-CO-4 | chaque ligne a `vatCategory` non vide (toujours vrai par défaut — contrôler quand même) |
| BR-CO-10 | `totals->netAmount == Σ round(line->getNetAmount(), 'line/netAmount')` |
| BR-CO-11/12 | `allowancesAmount`/`chargesAmount` == Σ des effectifs arrondis |
| BR-CO-13 | `taxExclusiveAmount == netAmount − allowancesAmount + chargesAmount` |
| BR-CO-14 | `vatAmount == Σ breakdown taxAmount` |
| BR-CO-15 | `taxInclusiveAmount == taxExclusiveAmount + vatAmount` |
| BR-CO-16 | `payableAmount == taxInclusiveAmount − paidAmount + roundingAmount` |
| BR-CO-17 | par breakdown, formule du 7.3.6 |
| BR-CO-18 | `count(vatBreakdown) >= 1` |
| BR-CO-19/20 | période (document / ligne) : si l'une des bornes est posée, start ≤ end quand les deux existent |
| BR-CO-25 | si `payableAmount > 0` alors `dueDate !== null || paymentTerms !== null` |
| BR-CO-26 | vendeur : au moins un parmi `getIdentifiers()`, `getCompanyId()`, `getVatNumber()` |

### 7.5 Règles BR simples manquantes

Ajouter : BR-08 (adresse postale vendeur présente : au moins country — déjà BR-09 ; contrôler `getCity()` ? **non** : s'en tenir au texte officiel, BR-08 = « shall contain the Seller postal address » → exiger que `getCountry()` non null couvre déjà ; marquer BR-08 non implémentable distinctement, skip), BR-10 (idem acheteur, skip), **BR-18/19/20** (si `taxRepresentative` non null : name requis, adresse + country requis), **BR-28** (`grossPrice !== null → grossPrice >= 0` et `grossPrice >= price − 0.005`), **BR-29/30** (déjà en BR-CO-19/20), **BR-32/37** (chaque allowance/charge document a une `vatCategory`), **BR-53** (si `vatCurrency` non null, rien à vérifier de plus dans le modèle — skip), **BR-57** (si `getDelivery()?->getAddress()` existe : country requis — vérifier l'API `Delivery` avant), **BR-62/63** (electronic address du vendeur/acheteur : scheme requis quand adresse présente), **BR-49 corrigé** (MOD-14) : supprimer la ligne `if ($inv->getPaymentTerms() !== null) return;` (`InvoiceValidationTrait.php:171`).

### 7.6 BR-DEC

Une règle générique `BR-DEC` : pour `paidAmount`, `roundingAmount`, chaque `AllowanceOrCharge` **à montant fixe** (document et ligne) : `abs($v - round($v, 2)) < 1e-9`, sinon message « The allowed maximum number of decimals is 2 » avec l'ID BR-DEC approprié (BR-DEC-09→18 selon le champ ; consulter le TSV).

### 7.7 MOD-15 — motifs d'exonération divergents

Dans `InvoiceTotals::updateVatMap` (`InvoiceTotals.php:178-186`) : ne pas écraser silencieusement — si le breakdown a déjà un `exemptionReasonCode` différent non null, conserver le premier (ne rien faire). Le contrôle de cohérence (« deux lignes E même taux avec motifs différents ») devient une règle de validation `BR-E-10`-adjacente au lot 8 (règle FR G1.41) — pas ici.

### Critères d'acceptation du lot 7

- Nouveau `tests/Traits/InvoiceValidationTest.php` (ou compléter l'existant) : un test par famille —
  - ligne E sans motif → `ValidationException` code `BR-E-10` ;
  - ligne S avec rate null → exception `BR-S-05` ;
  - facture O + ligne S → exception `BR-O-*` ;
  - AE sans VAT acheteur → exception ;
  - BT-7 + BT-8 simultanés → `BR-CO-3` ;
  - `paidAmount=10.123` → `BR-DEC` ;
  - payment sans meansCode **avec** paymentTerms → exception `BR-49` (comportement corrigé) ;
  - facture Peppol de la doc README → **passe** (non-régression).
- Test d'arrondi : 3 lignes à 10.005 € (matrice 2 déc.) → `totals->netAmount == 30.03` (somme des arrondis, plus 30.02).

---

## Lot 8 — Preset France (PPF / Factur-X)

> Corrige : MOD-02, MOD-05 (indirectement), UBL-12, UBL-13, MOD-08, MOD-09. Dépend des lots 6 et 7. Taille : ~1 j.

### 8.1 Nouveau fichier `src/Presets/Ppf.php`

```php
/**
 * French PPF (Portail Public de Facturation) / Factur-X EN 16931 profile
 * Spec: "Spécifications externes FE v3.2" — rules G x.xx from Annexe 7 v1.9
 */
class Ppf extends AbstractPreset {
    public function getSpecification(): string {
        return "urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931";
    }
    public function setupInvoice(Invoice $invoice) {
        parent::setupInvoice($invoice);        // rounding matrix ['' => 2]
        $invoice->setCurrency('EUR');
    }
    public function getRules(): array { ... }
}
```

> ⚠️ **À vérifier avant merge** : la valeur BT-24 attendue par le PPF pour le Flux 1. La chercher dans l'onglet Notice de l'Annexe 1 (`grep -i "urn" /tmp/audit-einvoicing/spec/annexe1_flux1__Notice_.tsv`). Si le PPF exige une autre valeur (CIUS FR dédié), l'utiliser à la place ; le reste du preset est inchangé.

### 8.2 Règles `getRules()` — liste exhaustive et fermée

Chaque règle = une closure, ID = code PPF. Textes de référence dans `annexe7_regles__Règles_de_gestion.tsv`.

| ID | Implémentation exacte |
|---|---|
| `G1.01` | `in_array($inv->getType(), Invoice::FR_ALLOWED_TYPES, true)` sinon erreur |
| `G1.02` | si `getBusinessProcess()` non null : `in_array(..., ['B1','S1','M1','B2','S2','M2','B4','S4','M4','S5','S6','B7','S7'])` ; si null : erreur (« Le cadre de facturation (BT-23) est obligatoire ») |
| `G1.05` | numéro : `strlen ≤ 35`, regex `/^[A-Za-z0-9 \-+_\/]+$/`, pas uniquement des espaces, pas d'espace en tête/queue, pas de `"  "` (deux espaces consécutifs) |
| `G1.24` | pour chaque ligne/allowance/charge avec `vatRate !== null` : valeur ∈ `[0, 0.9, 1.05, 1.75, 2.1, 5.5, 7, 8.5, 9.2, 9.6, 10, 13, 19.6, 20, 20.6]` (comparaison à 0.001 près) |
| `G1.31/G1.32` | si type ∈ {261, 381, 396, 502, 503} (avoirs) : `count(getPrecedingInvoiceReferences()) >= 1` ; si type ∈ {384, 471, 472, 473} (rectificatives) : `count(...) == 1` |
| `G1.41` | pour chaque breakdown de catégorie E : `exemptionReasonCode !== null && exemptionReason !== null` (code **et** texte) |
| `G1.53` | pour chaque breakdown : `abs(taxAmount − taxableAmount × rate/100) <= 0.01` (tolérance 1 centime) |
| `G2.31` | catégories de toutes les lignes/allowances/charges ∈ {S, E, AE, K, G, O, Z} |
| `G1.63` | vendeur : `getCompanyId() !== null` **et** `getCompanyId()->getScheme() ∈ {'0002','0009'}` **et** si 0002 : `preg_match('/^\d{9}$/', value)` ; si 0009 : `^\d{14}$` |
| `G1.63-buyer` | acheteur français (country FR) : même contrôle sur `getCompanyId()` |

Ne **pas** implémenter G2.32 (détection « 100 % CGI 261 » nécessite les codes VATEX — reporter, ajouter un TODO commenté).

### 8.3 Documentation

Ajouter `docs/getting-started/france-ppf.md` : exemple complet de facture française (SIREN, cadre B1, TVA 20 %, avoir avec référence antérieure), mention explicite : **la librairie ne produit pas le PDF Factur-X, uniquement le XML CII** (à assembler avec un outil PDF/A-3 externe).

### Critères d'acceptation du lot 8

- `tests/Presets/PpfTest.php` : facture nominale FR → `validate()` passe ; puis 8 tests négatifs (un par règle : type 388, cadre absent, numéro `"FA  01"`, taux 19, avoir sans BG-3, rectificative avec 2 refs, E sans texte, catégorie L, SIREN à 8 chiffres).
- `new Invoice(Presets\Ppf::class)` → `getDecimals('') == 2` (corrige MOD-05 pour l'usage FR).

---

## Lot 9 — Correctifs CDAR

> Corrige : CDAR-01→CDAR-05, CDAR-07, CDAR-10, CDAR-11 (partiel), CDAR-13. Taille : ~1,5 j.
> **Fichiers** : `src/Cdar/*`, `src/Readers/CdarReader.php`, `src/Writers/CdarWriter.php`, `src/CrossDomainAcknowledgementAndResponse.php`.

### 9.1 Helper d'attribut nullable (CDAR-02)

Dans `CdarReader`, ajouter et utiliser **partout** (occurrences : `currencyID` ~`:232`, `schemeID` ~`:255` et `:271`, `format` `:281`) :

```php
private function attr(UXML $node, string $name): ?string {
    $el = $node->element();
    return $el->hasAttribute($name) ? $el->getAttribute($name) : null;
}
```

Côté writer, vérifier que `CdarWriter` n'émet jamais un attribut dont la valeur est `null` ou `''` (auditer chaque `->add(..., [...])`).

### 9.2 Notes détaillées à l'import (CDAR-01) + modèle multi-anomalies (CDAR-07)

1. **Modèle** `src/Cdar/SpecifiedDocumentStatus.php` : enrichir la structure de note —

```php
/** @var array<int, array{content: string, languageId: ?string, contentCode: ?string, subjectCode: ?string}> */
private array $includedNotes = [];

public function addIncludedNote(string $content, ?string $languageId = null,
                                ?string $contentCode = null, ?string $subjectCode = null): self
```

`setIncludedNotes` accepte les nouvelles clés optionnelles. `getIncludedNoteContentCode()`/`setIncludedNoteContentCode()` : **conservés dépréciés** (`@deprecated`), le setter s'applique comme fallback aux notes sans `contentCode` propre au moment de l'écriture.

2. **Writer** `CdarWriter::addIncludedNote` (~`:237-247`) : émettre par note `ram:ContentCode` (= `contentCode` propre, sinon fallback déprécié), `ram:Content`, `ram:SubjectCode` (si non null) — vérifier l'ordre exact des enfants de `ram:IncludedNote` dans le XSD UN/CEFACT (`NoteType` : ContentCode, Content, SubjectCode…) avant d'écrire.

3. **Reader** `parseSpecifiedDocumentStatus` (`CdarReader.php:158-199`) : ajouter la boucle

```php
foreach ($node->getAll("ram:IncludedNote") as $noteNode) {
    $content = $noteNode->get("ram:Content")?->asText();
    if ($content === null) continue;
    $status->addIncludedNote(
        $content,
        $this->attr($noteNode->get("ram:Content"), 'languageID'),
        $noteNode->get("ram:ContentCode")?->asText(),
        $noteNode->get("ram:SubjectCode")?->asText()
    );
}
```

### 9.3 `AcknowledgementDocument` multiples (CDAR-03)

`src/CrossDomainAcknowledgementAndResponse.php` : remplacer la propriété unique par un tableau :

```php
/** @var AcknowledgementDocument[] */
private array $acknowledgementDocuments = [];

public function addAcknowledgementDocument(AcknowledgementDocument $doc): self;
/** @return AcknowledgementDocument[] */
public function getAcknowledgementDocuments(): array;
/** @deprecated Use getAcknowledgementDocuments() */
public function getAcknowledgementDocument(): ?AcknowledgementDocument;  // returns first or null
public function setAcknowledgementDocument(AcknowledgementDocument $doc): self;  // @deprecated, resets array to [$doc]
```

`CdarWriter` : boucler. `CdarReader` (~`:77`) : `getAll("rsm:AcknowledgementDocument")` et boucler.

### 9.4 Référentiels de codes (CDAR-04, CDAR-05, CDAR-10)

1. `src/Cdar/Enums/DocumentTypeCode.php` : ajouter les cases `261, 384, 389, 471, 472, 473, 500, 501, 502, 503` (libellés G1.01) et `303, 304, 305, 306` (libellés exacts G7.15 : « CDV sur Flux », « CDV sur Transmission ou facture e-reporting ou flux 1 », « CDV sur message CDV », « CDV sur flux de données annuaires »). Ne rien supprimer.
2. `src/Cdar/Enums/ProcessConditionCode.php` : ajouter les statuts non-facture — `250, 251` (flux 1), `300, 301` (flux 10), `400, 401` (annuaire), `500` (Recevable), `501` (Irrecevable), `601` (Rejeté CDV). Les libellés `label()`/`xmlLabel()` et le mapping `statusCode()` doivent être pris de l'onglet Statuts : `cat /tmp/audit-einvoicing/spec/annexe2_flux6__Statuts.tsv` — **ne pas inventer** ; si un libellé manque dans le TSV, utiliser le libellé de l'Annexe 2 CI_ARM et marquer `// TODO verify against PPF examples`.
3. `src/Cdar/Mapping/CdarStatusMap.php:18-29` : compléter `all()` avec **toutes** les cases de l'enum (206, 208, 209 manquants + les nouveaux). Remplacer la liste manuelle par `foreach (ProcessConditionCode::cases() as $case)`.

### 9.5 Validation CDAR minimale (CDAR-06, périmètre réduit)

Nouvelle méthode `CrossDomainAcknowledgementAndResponse::validate(): void` (lève `ValidationException`), appelée **explicitement** par l'utilisateur (ne pas l'appeler dans le writer). Contrôles :

1. `ExchangedDocument` présent, avec ID, TypeCode et IssueDateTime non null (exigé par le XSD, minOccurs=1).
2. Au moins un `AcknowledgementDocument`.
3. Pour chaque `SpecifiedDocumentStatus` dont `processConditionCode` ∈ `{'210','213','251','301','401','501','601'}` : `reasonCode !== null` (règle G7.08).

Documenter dans `docs/getting-started/exporting-cdar.md`.

### 9.6 Libellé de statut non écrasé (CDAR-10)

- `CdarWriter` (~`:143-148`) : n'appeler `xmlLabel()` **que si** `getProcessCondition() === null`.
- `CdarReader` (~`:165-174`) : l'élément `ram:ProcessCondition` du XML, s'il existe, a toujours priorité (le code actuel le fait déjà en écrasant après — vérifier l'ordre et garder ce comportement, mais ne pas pré-remplir avec `resolveProcessConditionLabel` si l'élément existe).

### 9.7 XSD Flux 6 (CDAR-13)

Ajouter dans `docs/` (page CDAR) un encadré : les valeurs `ReferenceTypeCode` (urn G7.14) et `@format=204` sont exigées par l'Annexe 2 mais refusées par le XSD UN/CEFACT brut ; le XSD Flux 6 publié par le PPF fait foi et doit être récupéré (absent du paquet v3.2 local). Aucun changement de code.

### Critères d'acceptation du lot 9

- `tests/Readers/CdarReaderTest.php` : import d'un CDV 210 avec 2 `IncludedNote` (ContentCode/Content/SubjectCode distincts) → les 2 notes complètes sont restituées ; roundtrip import→export ne produit **aucun** attribut `schemeID=""` (assertion sur la chaîne XML).
- Import d'un CDAR à 2 `AcknowledgementDocument` → 2 objets.
- `CdarStatusMap::forProcessConditionCode(206)` non null.
- `validate()` : CDV 210 sans reasonCode → exception G7.08.

---

## Lot 10 — Robustesse et sécurité

> Corrige : QUA-03, QUA-04, QUA-05, QUA-06, CII-21, CDAR-11. Taille : ~0,5 j.

### 10.1 Parsing de dates défensif

Dans **les trois** readers (`CiiReader.php:218-226`, `CdarReader.php:279-290`, et vérifier `UblReader` pour ses parses `Y-m-d`), remplacer le corps de `parseDateTime` par le motif :

```php
private function parseDateTime(UXML $node): DateTime {
    $format = $node->element()->getAttribute('format');
    $value = trim($node->asText());
    $result = match ($format) {
        '102' => DateTime::createFromFormat('!Ymd', $value),
        '204' => DateTime::createFromFormat('YmdHis', $value),   // CdarReader only
        default => null,
    };
    if ($result === null) {
        try {
            $result = new DateTime($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid date value: '$value'", 0, $e);
        }
    }
    if ($result === false) {
        throw new InvalidArgumentException("Invalid date value: '$value' for format $format");
    }
    return $result;
}
```

(Le `!` de `'!Ymd'` remet l'heure à 00:00:00 — supprime le `setTime` enchaîné.)

### 10.2 Rejet des DOCTYPE

Au début de `import()` des trois readers, avant `UXML::fromString` :

```php
if (preg_match('/<!DOCTYPE/i', $document) === 1) {
    throw new InvalidArgumentException("XML documents with a DOCTYPE declaration are not accepted");
}
```

### 10.3 Exception d'export typée (QUA-04)

Nouveau fichier `src/Exceptions/ExportException.php` (`class ExportException extends \RuntimeException`). Remplacer le `throw new \InvalidArgumentException` provisoire du lot 3.2 et tout `throw new \Exception` restant dans `src/Writers/` par `ExportException`, déclarée en `@throws` sur `export()`.

### 10.4 Base64 strict (QUA-06)

`UblReader.php:885` : `base64_decode($value, true)` ; si `false` → ignorer la pièce jointe embarquée (ne pas lever — tolérance de lecture) et continuer.

### Critères d'acceptation du lot 10

- Tests : XML avec `<!DOCTYPE foo [...]>` → `InvalidArgumentException` (3 readers) ; date `20261399` en format 102 → `InvalidArgumentException` (pas d'erreur fatale) ; base64 corrompu → import réussi sans attachment embarqué.

---

## Backlog assumé (hors plan, à ne PAS faire sans nouvelle décision)

| Sujet | Constat | Raison du report |
|---|---|---|
| Extensions françaises de ligne (EXT-FR-FE-*, profil FULL) | UBL-10, CII-23 | Trajectoire cible 2027, structure de modèle à concevoir (BillingReference/Delivery par ligne) |
| Notes de ligne multiples + code sujet ligne | UBL-09 | Rupture d'API `InvoiceLine::setNote`, faible enjeu au démarrage |
| BT-93/BT-100 (base spécifique des remises doc) | MOD-12 | Nécessite un champ `baseAmount` sur `AllowanceOrCharge` et une révision d'`InvoiceTotals` |
| Pièces jointes en CII (BG-24) | CII-20 | Hors périmètre TSV Flux 1 |
| Statuts CDAR : listes de motifs IRR_*/REJ_* typées | CDAR-05 (résiduel) | Champ libre accepté par le PPF ; enum optionnelle plus tard |
| G2.32 (100 % exonération CGI 261) | Lot 8 | Nécessite la gestion des codes VATEX |
| `declare(strict_types=1)` généralisé, renommage namespace | QUA-07 | Churn massif vs upstream, à faire lors d'un éventuel détachement définitif du fork |
| Génération PDF Factur-X (PDF/A-3) | — | Nouvelle capacité, dépendance externe requise — projet séparé |

---

## Récapitulatif — correspondance constats d'audit → lots

| Constat | Lot | Constat | Lot | Constat | Lot |
|---|---|---|---|---|---|
| BLK-01/02 | 1 | CII-01→09 | 3 | CDAR-01/02/03 | 9 |
| QUA-10 | 1 | CII-10/11/14 | 4 | CDAR-04/05/07/10 | 9 |
| (infra XSD) | 2 | CII-12/13/16/19/22 | 3 | CDAR-06 (min.) | 9 |
| MOD-06→11, MOD-16 | 6 | QUA-01/09 | 3 | CDAR-11/13 | 9 |
| UBL-01→06 | 5 | QUA-02/11 | 4 | QUA-03/04/05/06 | 10 |
| MOD-01/03/04/14/15 | 7 | UBL-07 | non traité (comportement assumé : recalcul) | CII-21 | 10 |
| MOD-02/05/08/09, UBL-12/13 | 8 | UBL-08 | 8 (via preset) + 7 | UBL-14, CDAR-12 | aucun (info) |
