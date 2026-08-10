# Guide d'intégration — librairie `einvoicing`

**Public visé** : l'agent (ou le développeur) chargé d'écrire ou de mettre à jour le **code de glue** entre l'application métier et cette librairie.
**Documents liés** : `AUDIT.md` (état des lieux initial), `PLAN.md` (plan de correction — **intégralement livré**).
**État de référence de ce guide** : branche `audit-fix` (`519f211`). Les 10 lots du plan sont implémentés ; la suite de tests est verte (281 tests, 1039 assertions) et l'analyse statique aussi. Les rares écarts restants sont listés en §8.

> ⚠️ **Si votre code de glue a été écrit contre une version antérieure** (base `f7282cc` ou avant) : plusieurs comportements ont changé — contournements à supprimer, nouveaux champs à câbler, validation devenue stricte. Dérouler impérativement la checklist de migration du §5.

---

## 1. Ce que fait (et ne fait pas) la librairie

| Capacité | État |
|---|---|
| Construire une facture EN 16931 en objets PHP (`Invoice`, `InvoiceLine`, `Party`…) | ✅ |
| Exporter en **UBL 2.1** (`Invoice` / `CreditNote`) | ✅ |
| Exporter en **CII** (base Factur-X, séquences Factur-X EN 16931) | ✅ |
| Importer UBL et CII vers le modèle objet (readers miroirs des writers) | ✅ |
| Valider : règles EN 16931 (BR, BR-CO, BR-S/E/AE/K/G/O/Z, BR-DEC) + règles françaises G x.xx via le preset `Ppf` | ✅ |
| Émettre / lire les **statuts de cycle de vie** (Flux 6, CDAR), y compris statuts de flux/annuaire | ✅ |
| E-reporting (Flux 10) | ✅ hors périmètre de ce guide |
| **Générer le PDF Factur-X (PDF/A-3)** | ❌ jamais — la librairie produit le **XML CII uniquement**. L'assemblage PDF+XML doit être fait par un composant externe côté application. |
| Signature, transport (AS4/API PDP), annuaire | ❌ hors scope librairie |

**Principe fondamental** : la librairie **recalcule tous les totaux** (`InvoiceTotals::fromInvoice()`) à partir des lignes, remises et charges. Le code de glue ne fournit **jamais** de montants totaux — uniquement des prix unitaires, quantités, taux et remises. Corollaire (constat UBL-07 de l'audit, comportement assumé) : en réimport→réexport, les totaux d'une facture tierce peuvent être réécrits à l'arrondi près. **Ne pas utiliser la librairie comme passe-plat de factures entrantes** ; conserver le XML/PDF Factur-X original pour la retransmission et l'archivage légal.

**Dépendance Composer** : le paquet s'appelle désormais **`nicoka-orinea/einvoicing`**. Vérifier le `require` et la configuration `repositories` de l'application — un `require josemmo/einvoicing` résoudrait l'upstream, sans aucun des correctifs ni des ajouts français.

---

## 2. Construire une facture Flux 1 — référence champ par champ

### 2.1 Squelette recommandé

```php
use Einvoicing\{Invoice, InvoiceLine, Party, Identifier, InvoiceReference, AllowanceOrCharge};
use Einvoicing\Presets;

// TOUJOURS passer par le preset France pour une facture française :
// il pose la matrice d'arrondi à 2 décimales, la devise EUR, et active les règles G x.xx.
$inv = new Invoice(Presets\Ppf::class);

$inv->setNumber('FA-2026-0042')                      // BT-1  — ≤35 car., [A-Za-z0-9 -+_/], règle G1.05
    ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)      // BT-3  — voir table §7.1
    ->setIssueDate(new DateTime('2026-08-07'))       // BT-2
    ->setDueDate(new DateTime('2026-09-06'))         // BT-9
    ->setBusinessProcess('B1')                       // BT-23 — cadre de facturation, OBLIGATOIRE (G1.02, §7.2)
    ->setBuyerReference('SERVICE-ACHATS-77');        // BT-10 — exigé par beaucoup d'acheteurs
```

Sans preset, `new Invoice()` arrondit à **8 décimales** par défaut — rejet PPF garanti. Ne jamais construire une facture française sans `Presets\Ppf::class` (ou, à défaut, `setRoundingMatrix(['' => 2])` + `setSpecification(...)` posés à la main).

Le preset `Ppf` utilise comme BT-24 le guideline Factur-X EN 16931 (`urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931`) — les annexes PPF ne fixant pas de valeur propre. Documentation dédiée : `docs/getting-started/france-ppf.md`.

### 2.2 Parties (vendeur / acheteur)

```php
$seller = (new Party())
    ->setName('ACME SAS')                                        // BT-27 — raison sociale
    ->setTradingName('ACME')                                     // BT-28
    ->setCompanyId(new Identifier('123456789', '0002'))          // BT-30 — SIREN, scheme 0002 OBLIGATOIRE (G1.63)
    ->setVatNumber('FR32123456789')                              // BT-31
    ->setElectronicAddress(new Identifier('12345678900011', '0225'))  // BT-34 — SIRET pour le routage annuaire FR
    ->setAddress(['1 rue de la Paix'])                           // BG-5 (tableau : max 3 lignes)
    ->setPostalCode('75002')->setCity('Paris')->setCountry('FR');
$inv->setSeller($seller);
$inv->setBuyer($buyer);                    // BG-7, mêmes règles ; SIREN acheteur FR obligatoire
// $inv->setPayee($payee);                 // BG-10, seulement si différent du vendeur
// $inv->setTaxRepresentative($rep);       // BG-11 — représentant fiscal / assujetti unique (Party : name, vatNumber, adresse)
```

**Règles de glue** :
- `companyId` = **identifiant légal** (SIREN/SIRET) ; `vatNumber` = n° TVA intracom ; `identifiers` (via `addIdentifier`) = identifiants additionnels. Ne pas mettre le SIREN dans `addIdentifier` si `companyId` est déjà rempli.
- En CII : `companyId` sort en `SpecifiedLegalOrganization/ram:ID`, les `identifiers` en `ram:ID`/`ram:GlobalID` — mapping aligné sur la sémantique EN 16931, symétrique à la lecture.
- Le scheme de l'`Identifier` n'est **jamais** deviné par la librairie : toujours le passer explicitement (`0002` SIREN, `0009` SIRET — table §7.4).
- Une partie **sans** SIREN (acheteur étranger…) s'exporte normalement en CII : le bloc `SpecifiedLegalOrganization` est simplement omis. La validation `Ppf` (G1.63), elle, exige le SIREN/SIRET du vendeur et de l'acheteur français.

### 2.3 Lignes

```php
$line = (new InvoiceLine())
    ->setId('1')                          // BT-126 — sinon auto-généré à l'export
    ->setName('Prestation de conseil')    // BT-153
    ->setPrice(500.0)                     // BT-146 — prix unitaire NET (HT, après remise tarifaire)
    ->setQuantity(3)                      // BT-129
    ->setUnit('DAY')                      // BT-130 — UN/ECE Rec 20 (défaut C62 = unité)
    ->setVatCategory('S')                 // BT-151 — voir §7.3
    ->setVatRate(20.0);                   // BT-152 — OBLIGATOIRE si catégorie S (BR-S-05 sinon)
$inv->addLine($line);
```

**Sémantique de `setPrice($price, $baseQuantity)`** :
- `setPrice(100, 2)` signifie « 100 € **pour 2 unités** » (BT-146 = 100, BT-149 = 2). Le net de ligne = `price / baseQuantity × quantity`. Les writers émettent le prix **tel quel** avec sa `BasisQuantity` — les bases fractionnaires (0,5…) sont acceptées ; une base ≤ 0 lève `ExportException` en CII.
- `setGrossPrice(?float)` : prix brut unitaire (BT-148), pour la même base que le prix net ; le rabais BT-147 est dérivé (`grossPrice − price`). Ne renseigner que si l'ERP gère des prix tarif/remisé ; `grossPrice` doit être ≥ `price` (BR-28).

Remises et charges de **ligne** :

```php
$line->addAllowance((new AllowanceOrCharge())->setAmount(10)->markAsPercentage()->setReason('Remise fidélité'));
$line->addCharge((new AllowanceOrCharge())->setAmount(5.00)->setReason('Éco-participation'));
```

Un pourcentage s'applique au brut de ligne (`price/baseQuantity × quantity`). Motif (`setReason`) **ou** code motif (`setReasonCode`, UNTDID 5189/7161) obligatoire (BR-33/38/42/44).

### 2.4 Remises / charges de **document** (BG-20 / BG-21)

```php
$inv->addAllowance((new AllowanceOrCharge())
    ->setAmount(5)->markAsPercentage()          // 5 % de la somme des nets de ligne
    ->setVatCategory('S')->setVatRate(20.0)     // ⚠️ OBLIGATOIRE : chaque remise doc porte SA TVA (BT-95/96)
    ->setReasonCode('95')->setReason('Remise commerciale'));
```

**Règle de glue essentielle** : une remise/charge document ne s'applique qu'à **une** catégorie/taux de TVA. Si la remise commerciale porte sur une facture multi-taux, le code de glue doit la **ventiler lui-même en N objets** `AllowanceOrCharge` (un par taux, avec `setVatCategory`/`setVatRate`), au prorata des bases. La librairie n'éclate plus rien automatiquement (l'ancien éclatement CII était le bug CII-01/CII-03) : **c'est la responsabilité de l'appelant**, et c'est aussi ce qui garantit des montants identiques en UBL et en CII.

### 2.5 Avoirs et factures rectificatives

```php
$credit = new Invoice(Presets\Ppf::class);
$credit->setType(Invoice::TYPE_CREDIT_NOTE);                    // 381 — table §7.1
$credit->addPrecedingInvoiceReference(
    new InvoiceReference('FA-2026-0042', new DateTime('2026-08-07'))   // BG-3 — OBLIGATOIRE (G1.31/G1.32)
);
```

- Avoirs (261, 381, 396, 502, 503) : **au moins une** référence antérieure (`G1.31`). Rectificatives (384, 471, 472, 473) : **exactement une** (`G1.32`). Contrôlé par le preset `Ppf`.
- Tous les types d'avoir, y compris 261/502/503, sortent bien en racine `/CreditNote` UBL (`Invoice::CREDIT_NOTE_TYPES` fait foi).
- Les montants d'un avoir sont saisis **en positif** (le type de document porte le sens).
- `setProjectReference()` sur un avoir est émis en `AdditionalDocumentReference` code 50 (l'élément `cac:ProjectReference` n'existe pas dans le schéma CreditNote) — transparent pour l'appelant, symétrique à la lecture.

### 2.6 TVA : cas particuliers français

| Cas métier | Code de glue |
|---|---|
| Exonération (ex. formation, art. 261 CGI) | `setVatCategory('E')` + `setVatRate(0)` + `setVatExemptionReasonCode('VATEX-FR-CGI261-4-4A')` **et** `setVatExemptionReason('Exonéré art. 261 CGI')` — code **et** texte exigés (G1.41) |
| Autoliquidation (sous-traitance BTP…) | `setVatCategory('AE')` + rate 0 + motif ; n° TVA de l'acheteur obligatoire (BR-AE) |
| Livraison intracommunautaire | `setVatCategory('K')` + rate 0 + motif ; n° TVA des deux parties |
| Export hors UE | `setVatCategory('G')` + rate 0 + motif |
| Hors champ TVA (franchise en base art. 293 B) | `setVatCategory('O')` + `setVatRate(null)` + motif « TVA non applicable, art. 293 B du CGI ». Une facture O est **exclusivement** O : aucune autre catégorie ni aucun n° TVA vendeur/acheteur (BR-O-10 à BR-O-14, contrôlés) |
| TVA sur les débits / encaissements | `setVatPointDateCode('5')` (UNTDID 2005 : `5` date de facture / `29` livraison / `72` encaissements). Exclusif de `setTaxPointDate` (BR-CO-3). UBL : `InvoicePeriod/DescriptionCode` ; CII : `DueDateTypeCode` |
| Devise ≠ EUR avec TVA en EUR | `setCurrency('USD')` + `setVatCurrency('EUR')` + `setCustomVatAmount(<montant TVA en EUR>)` (BT-111, émis dans les deux syntaxes) |

### 2.7 Paiement (BG-16..19)

```php
use Einvoicing\Payments\{Payment, Transfer, Mandate, Card};

$inv->setPaymentTerms('Paiement à 30 jours, escompte 0%, pénalités 3× taux légal');  // BT-20
$inv->addPayment((new Payment())
    ->setMeansCode('30')                          // BT-81 — OBLIGATOIRE (BR-49, strict) — §7.5
    ->setId('FA-2026-0042')                       // BT-83 — référence de paiement (remittance info)
    ->addTransfer((new Transfer())
        ->setAccountId('FR7630006000011234567890189')   // BT-84 IBAN
        ->setAccountName('ACME SAS')                    // BT-85
        ->setProvider('AGRIFRPPXXX')));                 // BT-86 BIC
// Prélèvement :
// ->setMandate((new Mandate())
//     ->setReference('RUM-123')                  // BT-89
//     ->setAccount('FR76...')                    // BT-91 compte débité
//     ->setCreditorIdentifier('FR12ZZZ123456'))  // BT-90 ICS — émis en CII uniquement (ram:CreditorReferenceID)
```

⚠️ `BR-49` est désormais **stricte** : toute `Payment` doit porter un `meansCode`, même si `paymentTerms` est renseigné (l'ancienne tolérance a été supprimée). Le code de glue doit systématiquement fournir `meansCode`.
En CII, chaque `Transfer` produit son propre bloc `SpecifiedTradeSettlementPaymentMeans` ; carte (`setCard`) et compte débiteur du mandat sont émis et relus.

### 2.8 Autres références, livraison, pièces jointes

```php
$inv->setPurchaseOrderReference('PO-889');        // BT-13 — n° de commande (souvent exigé, motif de refus REF_CT_ABSENT)
$inv->setContractReference('CT-2025-12');         // BT-12
$inv->setProjectReference('PROJ-7');              // BT-11
$inv->setDespatchAdviceReference('BL-4471');      // BT-16 — n° de bon de livraison
$inv->setInvoicedObjectIdentifier(new Identifier('ABO-99', 'ABZ'));  // BT-18 (scheme = BT-18-1)

$inv->setDelivery((new Delivery())->setDate(new DateTime('2026-08-01')));  // BT-72 — date de livraison
```

⚠️ **Date de livraison** : la librairie n'invente **plus jamais** de date (l'ancien writer CII copiait la date d'émission — supprimé). **Le code de glue doit poser explicitement la date de livraison / fin de prestation quand elle est connue** — c'est une mention obligatoire des factures françaises dans la plupart des cas.

Pièces jointes (BG-24, UBL uniquement) :

```php
$inv->addAttachment((new Attachment())
    ->setId(new Identifier('DOC-1'))
    ->setFilename('detail.pdf')->setMimeCode('application/pdf')
    ->setContents($binaryPdf));   // contenu binaire brut — la librairie encode le base64
```

---

## 3. Exporter, valider, importer

### 3.1 Export

```php
use Einvoicing\Writers\{UblWriter, CiiWriter};
use Einvoicing\Exceptions\ExportException;

$inv->validate();                            // TOUJOURS valider avant export (§3.2)
$ublXml = (new UblWriter())->export($inv);   // string XML
$ciiXml = (new CiiWriter())->export($inv);   // string XML (à encapsuler en PDF/A-3 côté appli pour Factur-X)
```

- L'export ne valide **pas** : une facture incohérente s'exporte sans erreur. Le pipeline de glue doit être `construire → validate() → export → transmettre`.
- L'export peut lever `ExportException` (donnée rendant le XML impossible, ex. `baseQuantity ≤ 0`). Plus aucune `\Exception` générique.
- Les deux writers produisent des montants **identiques** (moteur de calcul unique `InvoiceTotals`).

### 3.2 Validation — contrat d'erreurs

```php
use Einvoicing\Exceptions\ValidationException;

try {
    $inv->validate();
} catch (ValidationException $e) {
    $ruleId  = $e->getBusinessRuleId();   // ?string — ex. "BR-E-10", "G1.31" (peut être null)
    $message = $e->getMessage();
    // → remonter ruleId + message à l'UI / au log métier ; la facture NE DOIT PAS partir
}
```

- `validate()` s'arrête à la **première** règle en échec (pas de collecte multiple). Pour un rapport complet, boucler corriger→revalider.
- Couverture actuelle : règles BR de présence, **BR-CO** (cohérence arithmétique), **BR-S/Z/E/AE/K/G/O** (TVA par catégorie, table data-driven), **BR-DEC** (décimales), plus — via le preset `Ppf` — les règles françaises **G1.01, G1.02, G1.05, G1.24, G1.31, G1.32, G1.41, G1.53, G1.63, G2.31**.
- Un `validate()` vert est une très bonne approximation de l'acceptation PPF, mais ne remplace pas le schematron officiel : garder le traitement des rejets Flux 6 (CDAR 213) dans l'application.
- ⚠️ **La validation est beaucoup plus stricte qu'avant** : des gabarits de facture qui passaient sur l'ancienne version échoueront. Prévoir une campagne de re-validation des gabarits existants et l'affichage du `ruleId` dans les erreurs utilisateur (mapping `ruleId → message français` si besoin UX).

### 3.3 Import

```php
use Einvoicing\Readers\{UblReader, CiiReader};

$invoice = (new UblReader())->import($xmlString);    // ou CiiReader
```

- Les readers lèvent `InvalidArgumentException` sur XML illisible, **y compris** : document contenant un `<!DOCTYPE` (rejeté — durcissement anti-XXE/expansion d'entités) et dates malformées (plus d'erreur fatale PHP). Si un partenaire envoie des DOCTYPE (non conforme), les nettoyer en amont.
- Le preset est auto-détecté depuis BT-24 ; la valeur BT-24 **du document** et le BT-23 sont préservés (le preset ne les écrase plus).
- Le reader CII est le **miroir** du writer : BT-25/26 (références d'avoir), moyens de paiement multiples, carte/mandat, BT-7/BT-8, période d'en-tête, représentant fiscal, contacts et nom commercial sont relus. Le base64 corrompu d'une pièce jointe est ignoré silencieusement (tolérance de lecture, UBL).

---

## 4. CDAR (Flux 6) — cycle de vie

### 4.1 Émettre un statut

```php
use Einvoicing\CrossDomainAcknowledgementAndResponse as Cdar;
use Einvoicing\Cdar\{DocumentContext, ExchangedDocument, AcknowledgementDocument,
                     ReferenceReferencedDocument, TradeParty};
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Writers\CdarWriter;

$ref = (new ReferenceReferencedDocument())
    ->setIssuerAssignedId('FA-2026-0042')            // n° de la facture concernée
    ->setTypeCode(380)                                // type de l'objet (G7.15 : type facture, ou 303-306 pour flux/CDV/annuaire)
    ->setFormattedIssueDateTime(new DateTime('2026-08-07'))
    ->applyProcessCondition(ProcessConditionCode::REFUSED);
    // applyProcessCondition() pose code 210 + libellé + statusCode ET crée le SpecifiedDocumentStatus

$status = $ref->getSpecifiedDocumentStatuses()[0];    // créé par applyProcessCondition()
$status->setReasonCode('DEST_ERR')                    // OBLIGATOIRE pour 210/213/251/301/401/501/601 (G7.08, contrôlé par validate())
       ->setReason('Le destinataire conteste être le débiteur')
       ->setReferenceDateTime(new DateTime())
       ->addIncludedNote('Détail du refus', 'fr', 'G1.01', 'facture.xml');
       // addIncludedNote(content, languageId, contentCode, subjectCode) — une note par anomalie

$cdar = (new Cdar())
    ->setDocumentContext((new DocumentContext())/* guideline G7.14 : urn.cpro.gouv.fr:1p0:CDV:einvoicingF2 */)
    ->setExchangedDocument((new ExchangedDocument())
        ->setId(uniqid())->setIssueDateTime(new DateTime())
        ->setSender((new TradeParty())->setGlobalId('123456789')->setGlobalIdScheme('0002')))
    ->addAcknowledgementDocument((new AcknowledgementDocument())
        ->setMultipleReferencesIndicator(false)       // MDT-74 : toujours false (P1.14)
        ->setTypeCode('916')
        ->setIssueDateTime(new DateTime())
        ->setReference($ref));

$cdar->validate();                                    // contrôles XSD-obligatoires + G7.08
$xml = (new CdarWriter())->export($cdar);
```

Guides détaillés : `docs/getting-started/exporting-cdar.md`, `importing-cdar.md`, `cdar-status-mapping.md`.

### 4.2 Lire un statut reçu

```php
$cdar = (new \Einvoicing\Readers\CdarReader())->import($xml);
foreach ($cdar->getAcknowledgementDocuments() as $ack) {       // un CDAR peut porter PLUSIEURS blocs
    $ref    = $ack->getReference();
    $code   = $ref->getProcessConditionCode();                 // '200'..'213', '250'/'251', '300'/'301', '400'/'401', '500'/'501', '601'
    foreach ($ref->getSpecifiedDocumentStatuses() as $status) {
        $reason = $status->getReasonCode();                    // ex. 'DEST_ERR', 'IRR_*', 'REJ_*'
        foreach ($status->getIncludedNotes() as $note) {
            // ['content' => ..., 'languageId' => ..., 'contentCode' => ..., 'subjectCode' => ...]
            // contentCode = code de la règle en erreur ; subjectCode = fichier/balise fautive
        }
    }
}
```

- `getAcknowledgementDocument()` / `setAcknowledgementDocument()` (singulier) existent encore mais sont **dépréciés** — basculer sur le pluriel.
- `setIncludedNoteContentCode()` est **déprécié** — porter le code sur chaque note via `addIncludedNote()`.
- Les attributs absents sont relus comme `null` (plus de `schemeID=""` réémis) : un CDAR importé peut être réémis fidèlement.

### 4.3 Machine à états côté application

La librairie ne gère **pas** l'enchaînement licite des statuts (200 → 202/210/213 → 212…). Le code de glue doit tenir sa propre machine à états par facture et par sens (émis/reçu). Statuts obligatoires à traiter a minima (G7.44) : **200 Déposée, 210 Refusée, 212 Encaissée, 213 Rejetée**. Un refus (210) reçu impose un traitement métier (avoir ou re-facturation) ; un rejet (213) impose une correction technique et une re-soumission. Les statuts de recevabilité de flux (500/501) et de rejet de CDV (601) concernent la couche technique d'échange, pas la facture elle-même.

---

## 5. Checklist de migration du code de glue existant

Si l'application intégrait la librairie **avant** la branche `audit-fix`, dérouler ces actions (tout est livré en une fois — pas de séquencement) :

| # | Changement livré | Action requise côté application |
|---|---|---|
| 1 | Paquet renommé `nicoka-orinea/einvoicing` | Mettre à jour `require` + `repositories` ; vérifier qu'un `composer update` ne rapatrie pas l'upstream |
| 2 | `CiiWriter` ne jette plus si une partie n'a pas de SIREN | **Supprimer** les contournements (try/catch `\Exception`, SIREN factice sur parties étrangères) |
| 3 | Prix/`baseQuantity` corrigés en CII | **Supprimer** toute pré-division du prix ou évitement de `baseQuantity` ; re-générer les XML attendus des tests applicatifs |
| 4 | Les remises document ne sont plus éclatées par taux en CII | Ventiler les remises multi-taux **dans le code de glue** (un `AllowanceOrCharge` par taux, TVA posée dessus) |
| 5 | Plus de date de livraison fabriquée en CII | Poser `setDelivery()` explicitement partout où la date est connue |
| 6 | Mapping parties CII : `companyId` → `SpecifiedLegalOrganization`, `identifiers` → `GlobalID` | Si l'appli lisait/écrivait le SIREN via `GlobalID` en CII, basculer sur `companyId` |
| 7 | `ExportException` typée | Remplacer les catch `\Exception` par `ExportException` autour des `->export()` |
| 8 | Reader CII complet (BT-25/26, paiements multiples, BT-23 préservé…) | Réactiver/écrire les traitements aval (ex. rapprochement avoir↔facture sur import CII) |
| 9 | Avoirs 261/502/503 → `/CreditNote` UBL | Lever l'interdiction éventuelle de ces types côté appli ; re-générer les snapshots |
| 10 | Nouveaux champs modèle | Câbler depuis l'ERP : `setVatPointDateCode` (option TVA débits — paramétrage vendeur), `setTaxRepresentative`, `setGrossPrice`, `Mandate::setCreditorIdentifier`, `setDespatchAdviceReference`, `setInvoicedObjectIdentifier` |
| 11 | `validate()` strict (BR-CO, TVA par catégorie, BR-DEC, G x.xx) ; `BR-49` durci | Campagne de re-validation des gabarits ; toujours fournir `meansCode` ; surfacer `ruleId` dans l'UI |
| 12 | `InvoiceTotals` arrondit chaque ligne avant sommation (BR-CO-10) | Écarts d'un centime possibles vs anciens exports sur cas limites — re-générer les montants attendus stockés |
| 13 | Preset `Presets\Ppf` | Remplacer `new Invoice()` + `setRoundingMatrix` par `new Invoice(Presets\Ppf::class)` ; poser `setBusinessProcess('B1'…)` systématiquement |
| 14 | CDAR : API pluriel + notes détaillées + `validate()` | Basculer sur `getAcknowledgementDocuments()` / `addIncludedNote(...)` ; traiter les notes détaillées (affichage des motifs de rejet) ; appeler `$cdar->validate()` avant export |
| 15 | Readers durcis : `InvalidArgumentException` sur dates invalides, rejet des `<!DOCTYPE` | Adapter la gestion d'erreur d'ingestion ; nettoyer les DOCTYPE en amont si un partenaire en envoie |

---

## 6. Anti-patterns (ne jamais faire dans le code de glue)

1. **Ne jamais** créer une `new Invoice()` sans preset (ni `setRoundingMatrix(['' => 2])`) → montants à 8 décimales, rejet garanti.
2. **Ne jamais** fournir des montants TTC ou des totaux : uniquement prix unitaires HT, quantités, taux, remises. Pour vérifier la cohérence avec l'ERP : `$inv->getTotals()` (objet `InvoiceTotals` : `netAmount`, `vatAmount`, `taxInclusiveAmount`, `payableAmount`, `vatBreakdown[]`…) et comparer à ±0,01 €.
3. **Ne jamais** réémettre le XML d'une facture importée pour la transmettre (totaux recalculés) — archiver l'original.
4. **Ne jamais** omettre `vatRate` sur une ligne de catégorie S — `validate()` lève `BR-S-05`, mais un export sans validation émettrait une TVA à 0.
5. **Ne jamais** poser une remise document sans `setVatCategory`/`setVatRate`.
6. **Ne jamais** mapper `paidAmount` (BT-113) avec un règlement partiel classique : c'est le champ des **acomptes déjà facturés** ; le solde `payableAmount` en découle.
7. **Ne jamais** mettre du HTML/CRLF exotiques dans les notes ; contenu texte brut (le `#code#note` UBL est géré par la librairie via `addNote($texte, $subjectCode)`).
8. **Ne jamais** utiliser les constantes `TYPE_*` hors liste G1.01 pour une facture France (ex. `TYPE_TAX_INVOICE`/388) — le preset `Ppf` la rejettera (`G1.01`).
9. **Ne pas** dépendre de l'ordre ou du formatage exact du XML entre deux versions de la librairie (comparer en C14N ou via le modèle réimporté, pas en `diff` brut).
10. **Ne pas** poser à la fois `setTaxPointDate` (BT-7) et `setVatPointDateCode` (BT-8) — mutuellement exclusifs (`BR-CO-3`).

---

## 7. Tables de référence pour le mapping ERP

### 7.1 Types de facture autorisés France (G1.01) — constantes `Invoice::*`

| Code | Constante | Usage | Racine UBL |
|---|---|---|---|
| 380 | `TYPE_COMMERCIAL_INVOICE` | Facture standard | Invoice |
| 386 | `TYPE_PREPAYMENT_INVOICE` | Facture d'acompte | Invoice |
| 389 | `TYPE_SELF_BILLED_INVOICE` | Autofacturation | Invoice |
| 393 | `TYPE_FACTORED_INVOICE` | Facture affacturée | Invoice |
| 500 | `TYPE_SELF_BILLED_PREPAYMENT_INVOICE` | Acompte autofacturé | Invoice |
| 501 | `TYPE_SELF_BILLED_FACTORED_INVOICE` | Autofacturée affacturée | Invoice |
| 384 | `TYPE_CORRECTIVE_INVOICE` | Rectificative (1 réf. BG-3 exigée) | Invoice |
| 471 | `TYPE_SELF_BILLED_CORRECTIVE_INVOICE` | Rectificative autofacturée | Invoice |
| 472 | `TYPE_FACTORED_CORRECTIVE_INVOICE` | Rectificative affacturée | Invoice |
| 473 | `TYPE_SELF_BILLED_FACTORED_CORRECTIVE_INVOICE` | Rectificative autofacturée affacturée | Invoice |
| 381 | `TYPE_CREDIT_NOTE` | Avoir (≥1 réf. BG-3) | CreditNote |
| 396 | `TYPE_FACTORED_CREDIT_NOTE` | Avoir affacturé | CreditNote |
| 261 | `TYPE_SELF_BILLED_CREDIT_NOTE` | Avoir autofacturé | CreditNote |
| 502 | `TYPE_SELF_BILLED_FACTORED_CREDIT_NOTE` | Avoir autofacturé affacturé | CreditNote |
| 503 | `TYPE_PREPAYMENT_CREDIT_NOTE` | Avoir de facture d'acompte | CreditNote |

Listes utilitaires : `Invoice::FR_ALLOWED_TYPES` (les 15 ci-dessus), `Invoice::CREDIT_NOTE_TYPES` (types rendus en `/CreditNote`).

### 7.2 Cadres de facturation (BT-23, G1.02) — `setBusinessProcess()`

`B1` dépôt facture par le fournisseur · `S1` dépôt par un tiers/PDP source · `M1` facture mixte… Liste complète : B1, S1, M1, B2, S2, M2, B4, S4, M4, S5, S6, B7, S7. Le cas nominal fournisseur→client domestique est **B1**. À stocker comme donnée de paramétrage du flux, pas en dur. Obligatoire et contrôlé (`G1.02`).

### 7.3 Catégories de TVA acceptées France (G2.31) — `setVatCategory()`

`S` (taux plein/réduit), `Z` (taux 0), `E` (exonéré), `AE` (autoliquidation), `K` (intracom), `G` (export), `O` (hors champ). **Interdits** : L, M. Taux autorisés (G1.24, contrôlés) : 0 · 0.9 · 1.05 · 1.75 · 2.1 · 5.5 · 7 · 8.5 · 9.2 · 9.6 · 10 · 13 · 19.6 · 20 · 20.6.

### 7.4 Schemes d'identifiants (ISO 6523) — `new Identifier($value, $scheme)`

| Scheme | Contenu | Usage |
|---|---|---|
| `0002` | SIREN (9 chiffres) | `companyId` vendeur/acheteur FR — **obligatoire** (G1.63, format contrôlé) |
| `0009` | SIRET (14 chiffres) | `companyId` au niveau établissement (accepté par G1.63) |
| `0225` | SIRET qualifié routage FR | `electronicAddress` (adresse annuaire) — vérifier le code exact retenu par l'annuaire PPF dans l'Annexe 3 |
| `0088` | GLN | electronic address / identifiants EAN |
| `0231` | Assujetti unique (groupe TVA) | identifiant du groupe — cas G6.13 |

### 7.5 Moyens de paiement courants (BT-81, UNTDID 4461) — `setMeansCode()`

`30` virement · `58` virement SEPA · `49` prélèvement · `59` prélèvement SEPA · `48` carte · `42` paiement sur compte · `20` chèque · `10` espèces · `97` compensation. Virement (30/58) ⇒ IBAN obligatoire (BR-61) ; prélèvement ⇒ mandat. `meansCode` obligatoire dans tous les cas (BR-49).

### 7.6 Statuts de cycle de vie (Flux 6) — `ProcessConditionCode`

**Statuts de facture (objet métier)** :

| Code | Enum | Sens pour l'appli | Motif requis |
|---|---|---|---|
| 200 | `SUBMITTED` | Déposée (obligatoire) | — |
| 201 | `EMITTED_BY_PLATFORM` | Émise par la plateforme | — |
| 202/203/204 | `RECEIVED`/`MADE_AVAILABLE`/`TAKEN_IN_CHARGE` | Reçue / mise à dispo / prise en charge | — |
| 205/206 | `APPROVED`/`PARTIALLY_APPROVED` | Approuvée (totale/partielle) | — |
| 207/208 | `IN_DISPUTE`/`SUSPENDED` | Litige / suspendue | recommandé |
| 209 | `COMPLETED` | Complétée (fin de suspension) | — |
| 210 | `REFUSED` | **Refusée** (obligatoire) | ✅ G7.08 |
| 211/212 | `PAYMENT_TRANSMITTED`/`PAID` | Paiement transmis / **Encaissée** (obligatoire) | 212 : montant encaissé |
| 213 | `REJECTED` | **Rejetée** (obligatoire) | ✅ G7.08 |

**Statuts de flux et d'objets techniques** :

| Code | Enum | Objet |
|---|---|---|
| 500/501 | `FLOW_ADMISSIBLE`/`FLOW_INADMISSIBLE` | Recevabilité d'un flux (✅ motif requis pour 501) |
| 250/251 | `FLUX1_SUBMITTED`/`FLUX1_REJECTED` | Dépôt/rejet d'un flux 1 (✅ motif pour 251) |
| 300/301 | `EREPORTING_SUBMITTED`/`EREPORTING_REJECTED` | E-reporting (✅ motif pour 301) |
| 400/401 | `DIRECTORY_ACCEPTED`/`DIRECTORY_REJECTED` | Annuaire (✅ motif pour 401) |
| 601 | `ACKNOWLEDGEMENT_REJECTED` | Rejet d'un message CDV (✅ motif requis) |

`CdarStatusMap::forProcessConditionCode()` couvre l'intégralité de l'enum (libellés UI + libellés XML + `StatusCode`).

### 7.7 Codes sujet de note utiles (BT-21) — `addNote($texte, $code)`

`TXD` mentions TVA (dont assujetti unique, G1.52) · `PMT` conditions de paiement · `AAI` information générale · `ABL` mentions légales (ex. « Membre d'une association de gestion agréée »). Mentions françaises sans BT dédié (escompte, pénalités de retard, indemnité forfaitaire 40 €) → les porter via `setPaymentTerms` et/ou une note `PMT`.

---

## 8. Limitations connues à répercuter dans la conception applicative

1. **Pas de PDF Factur-X** : prévoir un composant PDF/A-3 (assemblage XML + PDF) dans le pipeline d'émission — le XML CII produit par `CiiWriter` s'embarque tel quel (`factur-x.xml`).
2. **Une seule note par ligne** (`InvoiceLine::setNote`) ; notes document illimitées.
3. **Extensions françaises du profil FULL non supportées** (acomptes imputés en ligne, livraison par ligne — trajectoire 2027, backlog `PLAN.md`).
4. **BT-90 (ICS)** émis en CII uniquement ; pour l'UBL, porter l'ICS en note si nécessaire.
5. **BT-93/BT-100** (base spécifique d'une remise document) non modélisables : les pourcentages s'appliquent toujours à la somme des nets de ligne.
6. `validate()` s'arrête à la première erreur — l'UX « liste complète des anomalies » doit boucler.
7. La détection de preset à l'import se fait sur la valeur **exacte** de BT-24 : une facture UBL française portant `urn:cen.eu:en16931:2017` nu ne matchera pas `Ppf` — appliquer les règles FR explicitement côté import si besoin (le reader ne reconstruit pas l'invoice avec `Presets\Ppf::class`).
8. Le contrôle G2.32 (facture 100 % exonération CGI 261) n'est pas implémenté (nécessite la gestion des codes VATEX — backlog).
9. Suite de tests de non-régression applicative : comparer les exports en **modèle réimporté** ou C14N, jamais en chaîne brute.
