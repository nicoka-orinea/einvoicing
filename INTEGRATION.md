# Guide d'intégration — librairie `einvoicing`

**Public visé** : l'agent (ou le développeur) chargé d'écrire ou de mettre à jour le **code de glue** entre l'application métier et cette librairie.
**Documents liés** : `AUDIT.md` (état des lieux), `PLAN.md` (corrections à venir, lots 1 à 10).
**Convention de ce guide** : tout ce qui est marqué 🔵 **[actuel]** décrit la librairie telle qu'elle est aujourd'hui (commit `f7282cc`) ; tout ce qui est marqué 🟣 **[après lot N]** décrit l'API ou le comportement une fois le lot N de `PLAN.md` exécuté. Le code de glue doit être écrit pour la cible 🟣, avec les adaptations temporaires signalées.

---

## 1. Ce que fait (et ne fait pas) la librairie

| Capacité | État |
|---|---|
| Construire une facture EN 16931 en objets PHP (`Invoice`, `InvoiceLine`, `Party`…) | ✅ |
| Exporter en **UBL 2.1** (`Invoice` / `CreditNote`) | ✅ fiable |
| Exporter en **CII** (base Factur-X) | ⚠️ 🔵 défectueux hors cas nominal → ✅ 🟣 après lot 3 |
| Importer UBL et CII vers le modèle objet | ✅ / ⚠️ (symétrie CII complète après lot 4) |
| Valider (EN 16931 + règles françaises) | ⚠️ 🔵 très partiel → ✅ 🟣 après lots 7-8 |
| Émettre / lire les **statuts de cycle de vie** (Flux 6, CDAR) | ✅ (compléments au lot 9) |
| E-reporting (Flux 10) | ✅ hors périmètre de ce guide |
| **Générer le PDF Factur-X (PDF/A-3)** | ❌ jamais — la librairie produit le **XML CII uniquement**. L'assemblage PDF+XML doit être fait par un composant externe côté application. |
| Signature, transport (AS4/API PDP), annuaire | ❌ hors scope librairie |

**Principe fondamental** : la librairie **recalcule tous les totaux** (`InvoiceTotals::fromInvoice()`) à partir des lignes, remises et charges. Le code de glue ne fournit **jamais** de montants totaux — uniquement des prix unitaires, quantités, taux et remises. Corollaire important (constat UBL-07 de l'audit) : en réimport→réexport, les totaux d'une facture tierce peuvent être réécrits à l'arrondi près. **Ne pas utiliser la librairie comme passe-plat de factures entrantes** ; conserver le XML/PDF facturX original pour la retransmission et l'archivage légal.

---

## 2. Construire une facture Flux 1 — référence champ par champ

### 2.1 Squelette recommandé

```php
use Einvoicing\{Invoice, InvoiceLine, Party, Identifier, InvoiceReference, AllowanceOrCharge};
use Einvoicing\Presets;

// 🟣 [après lot 8] — TOUJOURS passer par le preset France :
$inv = new Invoice(Presets\Ppf::class);
// 🔵 [actuel, temporaire] — pas de preset FR ; OBLIGATOIRE en attendant :
// $inv = new Invoice();
// $inv->setRoundingMatrix(['' => 2]);   // sinon montants à 8 décimales → rejet PPF
// $inv->setSpecification('urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931');

$inv->setNumber('FA-2026-0042')                      // BT-1  — ≤35 car., [A-Za-z0-9 -+_/], règle G1.05
    ->setType(Invoice::TYPE_COMMERCIAL_INVOICE)      // BT-3  — voir table §7.1
    ->setIssueDate(new DateTime('2026-08-07'))       // BT-2
    ->setDueDate(new DateTime('2026-09-06'))         // BT-9
    ->setBusinessProcess('B1')                       // BT-23 — cadre de facturation, OBLIGATOIRE PPF (§7.2)
    ->setBuyerReference('SERVICE-ACHATS-77');        // BT-10 — exigé par beaucoup d'acheteurs
```

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
$inv->setBuyer($buyer);        // BG-7, mêmes règles ; SIREN acheteur FR obligatoire
// $inv->setPayee($payee);     // BG-10, seulement si différent du vendeur
// 🟣 [après lot 6] $inv->setTaxRepresentative($rep);  // BG-11 — assujetti unique / représentant fiscal
```

**Règles de glue** :
- `companyId` = **identifiant légal** (SIREN/SIRET) ; `vatNumber` = n° TVA intracom ; `identifiers` (via `addIdentifier`) = identifiants additionnels. Ne pas mettre le SIREN dans `addIdentifier` si `companyId` est déjà rempli.
- Le scheme de l'`Identifier` n'est **jamais** deviné par la librairie 🔵🟣 : toujours le passer explicitement (`0002` SIREN, `0009` SIRET — table §7.4).
- ⚠️ 🔵 `CiiWriter` **jette une `\Exception`** si une partie n'a pas de `companyId` scheme `0002` (bug CII-05) — le code de glue actuel qui contourne ce bug (try/catch, SIREN factice…) devra être **supprimé** 🟣 après lot 1.

### 2.3 Lignes

```php
$line = (new InvoiceLine())
    ->setId('1')                          // BT-126 — sinon auto-généré à l'export
    ->setName('Prestation de conseil')    // BT-153
    ->setPrice(500.0)                     // BT-146 — prix unitaire NET (HT, après remise tarifaire)
    ->setQuantity(3)                      // BT-129
    ->setUnit('DAY')                      // BT-130 — UN/ECE Rec 20 (défaut C62 = unité)
    ->setVatCategory('S')                 // BT-151 — voir §7.3
    ->setVatRate(20.0);                   // BT-152 — OBLIGATOIRE si catégorie S (sinon TVA=0 silencieuse 🔵, BR-S-05 🟣)
$inv->addLine($line);
```

**Sémantique de `setPrice($price, $baseQuantity)`** — source de bug historique, à lire attentivement :
- `setPrice(100, 2)` signifie « 100 € **pour 2 unités** » (BT-146 = 100, BT-149 = 2). Le net de ligne = `price / baseQuantity × quantity`.
- ⚠️ 🔵 le writer CII divise le prix **et** émet la base → montants faux au roundtrip (bug CII-02). **Si le code de glue actuel pré-divise le prix ou évite `baseQuantity` pour contourner ce bug, supprimer le contournement 🟣 après lot 3.**
- 🟣 [après lot 6] `setGrossPrice(?float)` : prix brut unitaire (BT-148) ; le rabais BT-147 est dérivé (`grossPrice − price`). Ne renseigner que si l'ERP gère des prix tarif/remisé.

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

**Règle de glue essentielle** : une remise/charge document ne s'applique qu'à **une** catégorie/taux de TVA. Si la remise commerciale porte sur une facture multi-taux, le code de glue doit la **ventiler lui-même en N objets** `AllowanceOrCharge` (un par taux), au prorata des bases. 🔵 le writer CII tentait de faire cette ventilation automatiquement — c'est le bug CII-01/CII-03 ; 🟣 après lot 3, la librairie n'éclate plus rien : **c'est la responsabilité de l'appelant**.

### 2.5 Avoirs et factures rectificatives

```php
$credit = new Invoice(Presets\Ppf::class);
$credit->setType(Invoice::TYPE_CREDIT_NOTE);                    // 381 — table §7.1
$credit->addPrecedingInvoiceReference(
    new InvoiceReference('FA-2026-0042', new DateTime('2026-08-07'))   // BG-3 — OBLIGATOIRE (G1.31/G1.32)
);
```

- Avoirs (261, 381, 396, 502, 503) : **au moins une** référence antérieure. Rectificatives (384, 471, 472, 473) : **exactement une**.
- ⚠️ 🔵 les types 261/502/503 sortent en `<Invoice>` UBL (bug UBL-02) — corrigé 🟣 lot 5. Ne pas les utiliser avant.
- Les montants d'un avoir sont saisis **en positif** (le type de document porte le sens).

### 2.6 TVA : cas particuliers français

| Cas métier | Code de glue |
|---|---|
| Exonération (ex. formation, art. 261 CGI) | `setVatCategory('E')` + `setVatRate(0)` + `setVatExemptionReasonCode('VATEX-FR-CGI261-4-4A')` **et** `setVatExemptionReason('Exonéré art. 261 CGI')` — code **et** texte exigés (G1.41) |
| Autoliquidation (sous-traitance BTP…) | `setVatCategory('AE')` + rate 0 + motif ; n° TVA de l'acheteur obligatoire |
| Livraison intracommunautaire | `setVatCategory('K')` + rate 0 + motif ; n° TVA des deux parties |
| Export hors UE | `setVatCategory('G')` + rate 0 + motif |
| Hors champ TVA (franchise en base art. 293 B, auto-entrepreneur) | `setVatCategory('O')` + `setVatRate(null)` + motif « TVA non applicable, art. 293 B du CGI ». ⚠️ 🔵 export CII invalide (bug CII-04), corrigé 🟣 lot 3. Une facture O est **exclusivement** O (BR-O-11) |
| TVA sur les débits | 🟣 [après lot 6] `setVatPointDateCode('5')` (UNTDID 2005 : `5` date de facture / `29` livraison / `72` encaissements). 🔵 non exprimable — mention à porter en note en attendant |
| Devise ≠ EUR avec TVA en EUR | `setCurrency('USD')` + `setVatCurrency('EUR')` + `setCustomVatAmount(<montant TVA en EUR>)` (BT-111) |

### 2.7 Paiement (BG-16..19)

```php
use Einvoicing\Payments\{Payment, Transfer, Mandate, Card};

$inv->setPaymentTerms('Paiement à 30 jours, escompte 0%, pénalités 3× taux légal');  // BT-20
$inv->addPayment((new Payment())
    ->setMeansCode('30')                          // BT-81 — 30 virement, 58 SEPA, 49 prélèvement, 48 carte (§7.5)
    ->setId('FA-2026-0042')                       // BT-83 — référence de paiement (remittance info)
    ->addTransfer((new Transfer())
        ->setAccountId('FR7630006000011234567890189')   // BT-84 IBAN
        ->setAccountName('ACME SAS')                    // BT-85
        ->setProvider('AGRIFRPPXXX')));                 // BT-86 BIC
// Prélèvement : ->setMandate((new Mandate())->setReference('RUM-123')->setAccount('FR76...'))
//   🟣 [après lot 6] ->setCreditorIdentifier('FR12ZZZ123456')   // BT-90 ICS — émis en CII uniquement
```

⚠️ Comportement de `BR-49` : 🔵 une `Payment` sans `meansCode` passe la validation si `paymentTerms` est rempli ; 🟣 [lot 7] elle sera **toujours refusée**. Le code de glue doit systématiquement fournir `meansCode`.

### 2.8 Autres références et pièces jointes

```php
$inv->setPurchaseOrderReference('PO-889');        // BT-13 — n° de commande (souvent exigé, motif de refus REF_CT_ABSENT)
$inv->setContractReference('CT-2025-12');         // BT-12
$inv->setProjectReference('PROJ-7');              // BT-11 (⚠️ 🔵 invalide sur avoir — lot 5)
// 🟣 [après lot 6] :
$inv->setDespatchAdviceReference('BL-4471');      // BT-16 — n° de bon de livraison
$inv->setInvoicedObjectIdentifier(new Identifier('ABO-99', 'ABZ'));  // BT-18

$inv->setDelivery((new Delivery())->setDate(new DateTime('2026-08-01')));  // BT-72 — date de livraison
```

⚠️ **Date de livraison en CII** : 🔵 si `setDelivery` n'est pas appelé, le writer CII émet la **date d'émission comme date de livraison** (bug CII-12, donnée fiscale inventée). 🟣 après lot 3, plus aucune date fabriquée : **le code de glue doit poser explicitement la date de livraison/fin de prestation quand elle est connue** — c'est une mention obligatoire des factures françaises dans la plupart des cas.

Pièces jointes (BG-24, UBL uniquement 🔵🟣) :

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

$inv->validate();                       // TOUJOURS valider avant export (voir §3.2)
$ublXml = (new UblWriter())->export($inv);   // string XML
$ciiXml = (new CiiWriter())->export($inv);   // string XML (à encapsuler en PDF/A-3 côté appli pour Factur-X)
```

- L'export ne valide **pas** : une facture incohérente s'exporte sans erreur. Le pipeline de glue doit être `construire → validate() → export → (XSD facultatif) → transmettre`.
- 🟣 [lot 10] l'export peut lever `Einvoicing\Exceptions\ExportException` (donnée rendant le XML impossible, ex. baseQuantity ≤ 0). 🔵 il peut lever `\Exception` générique (bug QUA-04) — attraper large en attendant.

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

- `validate()` s'arrête à la **première** règle en échec (pas de collecte multiple). Pour un rapport complet, le code de glue doit boucler corriger→revalider.
- 🔵 la validation est très permissive (27 règles) : **un `validate()` qui passe ne garantit pas l'acceptation PPF**. 🟣 [lots 7-8] elle devient stricte (règles BR-CO, TVA par catégorie, BR-DEC, G1.xx via preset `Ppf`).
- ⚠️ **Impact migration majeur** : des factures qui passaient 🔵 échoueront 🟣. Prévoir dans l'application : (1) une campagne de re-validation des gabarits de facture existants dès le lot 7 livré ; (2) l'affichage du `ruleId` dans les erreurs utilisateur ; (3) un mapping `ruleId → message français` si besoin UX.

### 3.3 Import

```php
use Einvoicing\Readers\{UblReader, CiiReader};

$invoice = (new UblReader())->import($xmlString);    // ou CiiReader
```

- Les readers lèvent `InvalidArgumentException` sur XML illisible. ⚠️ 🔵 une **date malformée provoque une erreur fatale PHP** (bug QUA-03) — entourer l'import d'un try/catch `\Throwable` en attendant le lot 10 ; 🟣 `InvalidArgumentException` propre.
- 🟣 [lot 10] les documents contenant un `<!DOCTYPE` seront **rejetés** (durcissement sécurité). Si des partenaires envoient des DOCTYPE (rare et non conforme), les nettoyer en amont.
- Le preset est auto-détecté depuis BT-24 (`CustomizationID`/Guideline). ⚠️ 🔵 le reader CII **perd BT-23 et écrase BT-24** quand un preset est reconnu (bug QUA-02) — corrigé 🟣 lot 4.
- Ce qui n'est pas (encore) relu — le code de glue ne doit pas s'attendre à les retrouver après import CII 🔵 : BT-25/26 (références d'avoir), moyens de paiement multiples, BT-7/BT-8. Corrigé 🟣 lot 4.

---

## 4. CDAR (Flux 6) — cycle de vie

### 4.1 Émettre un statut

```php
use Einvoicing\CrossDomainAcknowledgementAndResponse as Cdar;
use Einvoicing\Cdar\{DocumentContext, ExchangedDocument, AcknowledgementDocument,
                     ReferenceReferencedDocument, TradeParty, SpecifiedDocumentStatus};
use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Writers\CdarWriter;

$ref = (new ReferenceReferencedDocument())
    ->setIssuerAssignedId('FA-2026-0042')            // n° de la facture concernée
    ->setTypeCode(380)                                // type de l'objet (G7.15 : type facture, ou 303-306 🟣 lot 9)
    ->setFormattedIssueDateTime(new DateTime('2026-08-07'))
    ->applyProcessCondition(ProcessConditionCode::REFUSED);   // pose code 210 + libellé + statusCode

$status = $ref->getSpecifiedDocumentStatuses()[0] ?? null;    // créé par applyProcessCondition — vérifier
$status->setReasonCode('DEST_ERR')                            // OBLIGATOIRE pour 210/213… (G7.08, table §7.6)
       ->setReason('Le destinataire conteste être le débiteur')
       ->setReferenceDateTime(new DateTime());

$ack = (new AcknowledgementDocument())
    ->setMultipleReferencesIndicator(false)           // MDT-74 : toujours false (P1.14)
    ->setTypeCode('916')
    ->setIssueDateTime(new DateTime())
    ->setReference($ref);

$cdar = (new Cdar())
    ->setDocumentContext((new DocumentContext())/* guideline G7.14 : urn.cpro.gouv.fr:1p0:CDV:einvoicingF2 */)
    ->setExchangedDocument((new ExchangedDocument())
        ->setId(uniqid())->setIssueDateTime(new DateTime())
        ->setSender((new TradeParty())->setGlobalId('123456789')->setGlobalIdScheme('0002')))
    ->setAcknowledgementDocument($ack);

// 🟣 [lot 9] $cdar->validate();   // contrôles G7.08 & champs XSD obligatoires
$xml = (new CdarWriter())->export($cdar);
```

> ℹ️ Vérifier le comportement exact d'`applyProcessCondition()` (crée-t-il le `SpecifiedDocumentStatus` ou faut-il l'ajouter soi-même via `addSpecifiedDocumentStatus`) dans `docs/getting-started/exporting-cdar.md` — la doc du dépôt fait foi.

### 4.2 Lire un statut reçu

```php
$cdar = (new \Einvoicing\Readers\CdarReader())->import($xml);
// 🔵 : $ack = $cdar->getAcknowledgementDocument();            (un seul bloc lu — bug CDAR-03)
// 🟣 [lot 9] : foreach ($cdar->getAcknowledgementDocuments() as $ack) { ... }
$ref    = $ack->getReference();
$code   = $ref->getProcessConditionCode();      // '200'..'213' (+ statuts flux 🟣 lot 9)
$status = $ref->getSpecifiedDocumentStatuses()[0] ?? null;
$reason = $status?->getReasonCode();            // ex. 'DEST_ERR'
// 🟣 [lot 9] $status->getIncludedNotes() : [{content, languageId, contentCode, subjectCode}] — motifs détaillés
```

⚠️ Pièges 🔵 (corrigés lot 9) : les `IncludedNote` (détail des motifs de refus/rejet) sont **perdues** à l'import ; un attribut absent est relu comme `""` — ne pas réémettre un CDAR importé sans le lot 9.

### 4.3 Machine à états côté application

La librairie ne gère **pas** l'enchaînement licite des statuts (200 → 202/210/213 → 212…). Le code de glue doit tenir sa propre machine à états par facture et par sens (émis/reçu). Statuts obligatoires à traiter a minima (G7.44) : **200 Déposée, 210 Refusée, 212 Encaissée, 213 Rejetée**. Un refus (210) reçu impose un traitement métier (avoir ou re-facturation) ; un rejet (213) impose une correction technique et une re-soumission.

---

## 5. Impacts du plan sur le code de glue existant — checklist de migration

À dérouler **lot par lot**, au fur et à mesure des livraisons de `PLAN.md` :

| Lot | Changement librairie | Action requise côté application |
|---|---|---|
| 1 | `CiiWriter` ne jette plus sur partie sans SIREN | Supprimer les contournements (try/catch `\Exception`, SIREN factice sur parties étrangères) |
| 1 | `composer.json` renommé `nicoka-orinea/einvoicing` | Mettre à jour le `require` et la config `repositories` de l'application ; vérifier qu'un `composer update` ne rapatrie pas l'upstream `josemmo/einvoicing` |
| 3 | Prix/baseQuantity corrigés (CII) | **Supprimer toute pré-division du prix** ou évitement de `baseQuantity` ; re-générer les XML attendus des tests applicatifs |
| 3 | Remises document plus jamais éclatées par taux | Ventiler les remises multi-taux **dans le code de glue** (un `AllowanceOrCharge` par taux, avec `setVatCategory`/`setVatRate`) |
| 3 | Plus de date de livraison fabriquée (CII) | Poser `setDelivery()` explicitement partout où la date est connue (mention obligatoire FR) |
| 3 | Mapping parties CII : `companyId` → `SpecifiedLegalOrganization`, `identifiers` → `GlobalID` | Si l'appli lisait/écrivait le SIREN via `GlobalID` en CII, basculer sur `companyId` |
| 3/10 | `ExportException` typée | Remplacer les catch `\Exception` par `ExportException` autour des `->export()` |
| 4 | Reader CII : BT-25/26, paiements multiples, BT-23 préservé | Réactiver/écrire les traitements aval qui étaient impossibles (rapprochement avoir↔facture sur import CII) |
| 5 | Avoirs 261/502/503 → `/CreditNote` UBL | Lever l'interdiction éventuelle de ces types côté appli ; re-générer les snapshots |
| 6 | Nouveaux champs modèle | Câbler depuis l'ERP : `setVatPointDateCode` (option TVA débits/encaissements — donnée de paramétrage vendeur), `setTaxRepresentative`, `setGrossPrice`, `Mandate::setCreditorIdentifier`, `setDespatchAdviceReference`, `setInvoicedObjectIdentifier` |
| 7 | `validate()` strict (BR-CO, BR-S/E/AE/K/G/O, BR-DEC) ; `BR-49` durci | Campagne de re-validation des gabarits existants ; toujours fournir `meansCode` ; surfacer `ruleId` dans l'UI |
| 7 | `InvoiceTotals` arrondit les lignes avant sommation | Écarts d'un centime possibles vs anciens exports sur les cas limites — re-générer les montants attendus stockés |
| 8 | Preset `Presets\Ppf` disponible | Remplacer `new Invoice()` + `setRoundingMatrix` par `new Invoice(Presets\Ppf::class)` pour toutes les factures FR ; poser `setBusinessProcess('B1'…)` systématiquement |
| 9 | `getAcknowledgementDocument()`/`setAcknowledgementDocument()` **dépréciés** → pluriel ; `setIncludedNoteContentCode` déprécié → `addIncludedNote($content, $lang, $contentCode, $subjectCode)` | Basculer les appels ; traiter les CDAR multi-blocs et les notes détaillées (affichage des motifs de rejet) |
| 10 | Readers : `InvalidArgumentException` sur dates invalides, rejet des `<!DOCTYPE` | Adapter la gestion d'erreur d'ingestion ; nettoyer les DOCTYPE en amont si un partenaire en envoie |

---

## 6. Anti-patterns (ne jamais faire dans le code de glue)

1. **Ne jamais** créer une `new Invoice()` sans preset ni `setRoundingMatrix(['' => 2])` → montants à 8 décimales, rejet garanti.
2. **Ne jamais** fournir des montants TTC ou des totaux : uniquement prix unitaires HT, quantités, taux, remises. Pour vérifier la cohérence avec l'ERP : `$inv->getTotals()` (objet `InvoiceTotals` : `netAmount`, `vatAmount`, `taxInclusiveAmount`, `payableAmount`, `vatBreakdown[]`…) et comparer à ±0,01 €.
3. **Ne jamais** réémettre le XML d'une facture importée pour la transmettre (totaux recalculés) — archiver l'original.
4. **Ne jamais** omettre `vatRate` sur une ligne de catégorie S (🔵 TVA silencieusement à 0 — bug MOD-04).
5. **Ne jamais** poser une remise document sans `setVatCategory`/`setVatRate`.
6. **Ne jamais** mapper `paidAmount` (BT-113) avec un règlement partiel classique : c'est le champ des **acomptes déjà facturés** ; le solde `payableAmount` en découle.
7. **Ne jamais** mettre du HTML/CRLF exotiques dans les notes ; contenu texte brut (le `#code#note` UBL est géré par la librairie via `addNote($texte, $subjectCode)`).
8. **Ne jamais** utiliser les constantes `TYPE_*` hors liste G1.01 pour une facture France (ex. `TYPE_TAX_INVOICE`/388 : rejet) — voir §7.1.
9. **Ne pas** dépendre de l'ordre ou du formatage exact du XML entre deux versions de la librairie (comparer en C14N ou via le modèle réimporté, pas en `diff` brut).

---

## 7. Tables de référence pour le mapping ERP

### 7.1 Types de facture autorisés France (G1.01)

| Code | Constante (🟣 lot 6 pour les nouvelles) | Usage | Racine UBL |
|---|---|---|---|
| 380 | `TYPE_COMMERCIAL_INVOICE` | Facture standard | Invoice |
| 386 | `TYPE_PREPAYMENT_INVOICE` | Facture d'acompte | Invoice |
| 389 | `TYPE_SELF_BILLED_INVOICE` 🟣 | Autofacturation | Invoice |
| 393 | `TYPE_FACTORED_INVOICE` | Facture affacturée | Invoice |
| 384 | `TYPE_CORRECTIVE_INVOICE` 🟣 | Rectificative (1 réf. BG-3 exigée) | Invoice |
| 500/501 | 🟣 | Acompte autofacturé / autofacturée affacturée | Invoice |
| 471/472/473 | 🟣 | Rectificatives (autofacturée / affacturée / les deux) | Invoice |
| 381 | `TYPE_CREDIT_NOTE` | Avoir (≥1 réf. BG-3) | CreditNote |
| 396 | `TYPE_FACTORED_CREDIT_NOTE` | Avoir affacturé | CreditNote |
| 261 | `TYPE_SELF_BILLED_CREDIT_NOTE` 🟣 | Avoir autofacturé | CreditNote ⚠️ 🔵 bug UBL-02 |
| 502/503 | 🟣 | Avoir autofacturé affacturé / d'acompte | CreditNote ⚠️ 🔵 bug UBL-02 |

### 7.2 Cadres de facturation (BT-23, G1.02) — `setBusinessProcess()`

`B1` dépôt facture par le fournisseur · `S1` dépôt par un tiers/PDP source · `M1` facture mixte… Liste complète : B1, S1, M1, B2, S2, M2, B4, S4, M4, S5, S6, B7, S7. Le cas nominal fournisseur→client domestique est **B1**. À stocker comme donnée de paramétrage du flux, pas en dur.

### 7.3 Catégories de TVA acceptées France (G2.31) — `setVatCategory()`

`S` (taux plein/réduit), `Z` (taux 0), `E` (exonéré), `AE` (autoliquidation), `K` (intracom), `G` (export), `O` (hors champ). **Interdits** : L, M. Taux autorisés (G1.24) : 0 · 0.9 · 1.05 · 1.75 · 2.1 · 5.5 · 7 · 8.5 · 9.2 · 9.6 · 10 · 13 · 19.6 · 20 · 20.6.

### 7.4 Schemes d'identifiants (ISO 6523) — `new Identifier($value, $scheme)`

| Scheme | Contenu | Usage |
|---|---|---|
| `0002` | SIREN (9 chiffres) | `companyId` vendeur/acheteur FR — **obligatoire** |
| `0009` | SIRET (14 chiffres) | `companyId` au niveau établissement |
| `0225` | SIRET qualifié routage FR | `electronicAddress` (adresse annuaire) — vérifier le code exact retenu par l'annuaire PPF dans l'Annexe 3 |
| `0088` | GLN | électronic address / identifiants EAN |
| `0231` | Assujetti unique (groupe TVA) | identifiant du groupe — cas G6.13 |

### 7.5 Moyens de paiement courants (BT-81, UNTDID 4461) — `setMeansCode()`

`30` virement · `58` virement SEPA · `49` prélèvement · `59` prélèvement SEPA · `48` carte · `42` paiement sur compte · `20` chèque · `10` espèces · `97` compensation. Virement (30/58) ⇒ IBAN obligatoire (BR-61) ; prélèvement ⇒ mandat.

### 7.6 Statuts de cycle de vie (Flux 6) — `ProcessConditionCode`

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
| 500/501/601… | 🟣 lot 9 | Recevabilité de flux / rejet CDV | ✅ pour 501/601 |

### 7.7 Codes sujet de note utiles (BT-21) — `addNote($texte, $code)`

`TXD` mentions TVA (dont assujetti unique, G1.52) · `PMT` conditions de paiement · `AAI` information générale · `ABL` mentions légales (ex. « Membre d'une association de gestion agréée »). Mentions françaises sans BT dédié (escompte, pénalités de retard, indemnité forfaitaire 40 €) → les porter via `setPaymentTerms` et/ou une note `PMT`.

---

## 8. Limitations connues à répercuter dans la conception applicative

1. **Pas de PDF Factur-X** : prévoir un composant PDF/A-3 (ex. assemblage XML + PDF) dans le pipeline d'émission — le XML CII produit par `CiiWriter` s'embarque tel quel (`factur-x.xml`).
2. **Une seule note par ligne** (`InvoiceLine::setNote`) ; notes document illimitées.
3. **Extensions françaises du profil FULL non supportées** (acomptes imputés en ligne, livraison par ligne) — trajectoire 2027, hors backlog actuel.
4. **BT-90 (ICS)** émis en CII uniquement 🟣 ; pour l'UBL, porter l'ICS en note si nécessaire.
5. **BT-93/BT-100** (base spécifique d'une remise document) non modélisables : les pourcentages s'appliquent toujours à la somme des nets de ligne.
6. `validate()` s'arrête à la première erreur — l'UX « liste complète des anomalies » doit boucler.
7. La détection de preset à l'import se fait sur la valeur **exacte** de BT-24 : une facture UBL française portant `urn:cen.eu:en16931:2017` nu ne matchera pas `Ppf` — appliquer les règles FR explicitement côté import si besoin (`(new Invoice(Presets\Ppf::class))` n'est pas reconstruit par le reader).
8. Suite de tests de non-régression applicative : comparer les exports en **modèle réimporté** ou C14N, jamais en chaîne brute (l'ordre/formatage évoluera aux lots 3 et 5).
