# Audit de la librairie `einvoicing`

**Date** : 7 août 2026
**Périmètre** : génération et lecture des formats EDI (UBL, CII, CDAR/Flux 6), modèle métier, moteur de validation, qualité logicielle
**Référentiel** : Spécifications externes de la facturation électronique française, v3.2 — Annexes 1 (Flux 1), 2 (Flux 6 / CDV), 7 (règles de gestion), XSD PPF v3.2
**Hors périmètre** : e-reporting (Flux 10) — un audit dédié a déjà été réalisé
**Base auditée** : commit `f7282cc`, ~12 000 lignes de code source, 92 fichiers PHP

---

## 1. Synthèse exécutive

La librairie repose sur un socle open source de bonne facture (`josemmo/einvoicing`, conforme EN 16931) dont le **support UBL est solide et éprouvé** : environ 85 % des champs du Flux 1 sont correctement émis et relus, l'ordre des éléments respecte les schémas, et sept tests d'intégration prouvent un aller-retour fidèle sur des factures Peppol.

Les développements ajoutés par le fork — **CII et CDAR** — ne sont pas au même niveau. Le module CII, qui est pourtant la brique la plus stratégique puisqu'il constitue la base de Factur-X, produit des **montants faux dès qu'une facture sort du cas nominal** (une ligne, TVA au taux normal, sans remise). Cinq défauts de calcul ont été reproduits par exécution, et non simplement déduits de la lecture du code.

Trois constats structurent tout le reste :

> **La branche est livrée en état rouge.** La suite de tests échoue à `HEAD` (74 tests, 4 erreurs) et l'analyse statique Phan signale une erreur bloquante. Deux des quatre échecs viennent d'une exception dans `CiiWriter` que les tests du dernier commit contredisent explicitement.

> **Il n'existe aucun preset France.** Le mécanisme de personnalisation par pays existe et fonctionne (Peppol, Autriche, Espagne, Italie, Roumanie, Pays-Bas), mais aucune des ~60 règles françaises applicables au Flux 1 (G1.01, G1.24, G1.31, G2.31…) n'est implémentée nulle part dans le code. Conséquence directe : sans preset, l'arrondi par défaut est à **8 décimales**, ce qui suffit à faire rejeter une facture.

> **Le moteur de validation ne couvre qu'un tiers de la norme.** 27 règles `BR-*` sur ~59 sont implémentées, et **aucune** règle arithmétique (`BR-CO-*`), TVA par catégorie (`BR-S-*`, `BR-E-*`, `BR-AE-*`…), décimales (`BR-DEC-*`) ou liste de codes (`BR-CL-*`). Une facture invalide passe `validate()` sans un mot, et n'est rejetée qu'en aval par la plateforme.

### Verdict par module

| Module | Maturité | Verdict |
|---|---|---|
| **UBL** (écriture / lecture) | ●●●●○ | Utilisable en production après correction des avoirs et ajout d'un preset France |
| **Modèle métier** | ●●●○○ | Cœur EN 16931 bien couvert ; champs fiscaux français manquants (BT-8, BG-11, BT-147/148) |
| **CDAR / Flux 6** | ●●●○○ | Structure XSD-valide et statuts facture complets ; perte de données à l'import |
| **CII** (écriture / lecture) | ●○○○○ | **Non utilisable en l'état** : calculs faux, ordre XSD violé, tests rouges |
| **Moteur de validation** | ●○○○○ | Filet de sécurité quasi inexistant face aux contrôles PPF |
| **Conformité France (PPF)** | ○○○○○ | Aucune règle française implémentée, aucun preset |

### Répartition des constats

| Sévérité | Nombre | Signification |
|---|---|---|
| 🔴 **Critique** | 10 | Documents rejetés par la plateforme, montants faux, ou dépôt cassé |
| 🟠 **Majeur** | 24 | Perte de données, champ obligatoire absent, invalidité XSD sur cas courant |
| 🟡 **Mineur** | 26 | Écart fonctionnel, robustesse, dette technique |
| ⚪ **Info** | 8 | Point d'attention ou à confirmer avec le PPF |

---

## 2. Méthode

Cinq analyses parallèles ont été menées, chacune croisant le code source avec les annexes de la spécification :

1. **UBL** — `UblWriter` / `UblReader` contre l'Annexe 1 (onglet UBL, 107 lignes BT) et les XSD `F1_BASE_UBL_2.1` / `F1_FULL_UBL_2.1`
2. **CII** — `CiiWriter` / `CiiReader` contre l'Annexe 1 (onglet CII) et les XSD `F1_BASE_CII_D22B` / `F1_FULL_CII_D22B`
3. **CDAR** — modèle `Cdar/`, writer et reader contre l'Annexe 2 (Flux 6 CDV v2.3) et le XSD UN/CEFACT `CrossDomainAcknowledgementAndResponse_100pD22B`
4. **Modèle et validation** — contre l'Annexe 7 (règles de gestion PPF `G x.xx` et règles de la norme `BR-*`)
5. **Qualité logicielle** — architecture, sécurité, tests, outillage, divergence avec l'upstream

Chaque constat est ancré sur un `fichier:ligne` et, lorsque pertinent, sur un identifiant de règle ou de champ. Les défauts de calcul CII et le comportement des attributs XML dans CDAR ont été **reproduits par exécution de scripts ad hoc**, pas seulement déduits par lecture.

---

## 3. Bloquants immédiats — l'état du dépôt

### 🔴 BLK-01 — La suite de tests échoue à `HEAD`

```
Tests: 74, Assertions: 245, Errors: 4     (PHP 8.5.4, simple-phpunit)
```

| Test en échec | Cause |
|---|---|
| `CiiWriterTest::testCanExportInvoiceWithoutOptionalPartyIdentifiers` | Exception `CiiWriter.php:824` |
| `CiiWriterTest::testDoesNotWriteBankTransferWithoutBeneficiaryAccount` | Idem |
| `Flux10WriterTest::testCanGenerateXsdValidTransactionsReportFromInvoice` | XSD hors dépôt |
| `Flux10WriterTest::testCanGenerateXsdValidPaymentsReport` | Idem |

La cause principale est un bug dont le mécanisme est visible à l'œil nu dans `src/Writers/CiiWriter.php:806-825` : la boucle qui cherche l'identifiant SIREN (`scheme 0002`) parmi les identifiants de la partie alimente une variable `$organizationIdentifier`… **qui n'est jamais utilisée ensuite**. Seul le `companyId` est testé, et toute partie qui n'en a pas déclenche `throw new \Exception("Missing legal organization identifier (0002)")`.

L'effet dépasse les tests : **il est impossible d'exporter en CII une facture impliquant une partie sans SIREN** — typiquement un acheteur ou un vendeur étranger, cas que la norme EN 16931 autorise sans réserve.

Les deux autres échecs viennent de `tests/Writers/Flux10WriterTest.php:28`, qui valide contre un XSD situé dans un répertoire `specifications-externes-v3.1/` **listé dans `.gitignore`**. La suite n'est donc pas auto-portante : elle ne peut pas passer sur un poste fraîchement cloné ni en intégration continue.

### 🔴 BLK-02 — L'analyse statique est également rouge

`vendor/bin/phan` signale `src/Writers/Flux10Writer.php:117` — `PhanPossiblyUndeclaredProperty` sur `->value` appliqué à un `?IssuerRoleCode`. Phan est une étape bloquante du workflow `.github/workflows/ci.yml`, et `CONTRIBUTING.md` exige explicitement des tests et une analyse verts. La discipline est documentée mais pas appliquée.

---

## 4. Support UBL

**Ce qui fonctionne bien.** Le cœur EN 16931 est couvert dans les deux sens avec des mappings corrects. L'ordre des éléments UBL 2.1 est respecté, y compris les subtilités de séquence qui diffèrent entre `Invoice` et `CreditNote`. La dualité facture/avoir est gérée jusque dans les détails (`InvoicedQuantity` vs `CreditedQuantity`, `DueDate` remplacé par `PaymentDueDate` sur les avoirs puisque le schéma `CreditNote` n'a pas d'élément `cbc:DueDate`). Les identifiants supportent les `schemeID`, donc SIREN `0002` et SIRET `0009` sont exprimables. La ventilation de TVA gère les catégories et les motifs d'exonération (BT-120/BT-121) ainsi que la double devise (BT-6/BT-111). Les acomptes (BT-113) et l'arrondi de pied de facture (BT-114) sont conformes à `BR-CO-16`.

### Constats

| # | Sév. | Constat | Emplacement |
|---|---|---|---|
| UBL-01 | 🔴 | **BT-8 (code d'exigibilité de la TVA) totalement absent** — ni modèle, ni écriture, ni lecture | `Invoice.php`, `UblWriter.php:256` |
| UBL-02 | 🔴 | **Les avoirs 261, 502 et 503 sont émis comme `/Invoice`** au lieu de `/CreditNote` | `UblWriter.php:225-234` |
| UBL-03 | 🟠 | BG-11 (représentant fiscal, BT-63) non supporté | absent du code |
| UBL-04 | 🟠 | `cac:ProjectReference` (BT-11) émis sur les avoirs : l'élément **n'existe pas** dans le schéma `CreditNote` | `UblWriter.php:148-152` |
| UBL-05 | 🟠 | BT-7 (`TaxPointDate`) écrit après le `TypeCode` : ordre invalide pour un avoir | `UblWriter.php:84-88` |
| UBL-06 | 🟠 | BT-9 perdu sur un avoir sans moyen de paiement, ou dupliqué s'il y en a plusieurs | `UblWriter.php:67, 179-181` |
| UBL-07 | 🟠 | Les totaux sont **recalculés** à l'export : un écart d'arrondi licite d'un centime en entrée est réécrit | `UblReader.php:242-264` |
| UBL-08 | 🟠 | Hors preset, les montants sortent à **8 décimales** (voir MOD-05) | `Invoice.php:26` |
| UBL-09 | 🟡 | Notes de ligne limitées à une occurrence ; code sujet non géré au niveau ligne | `UblReader.php:753-756` |
| UBL-10 | 🟡 | Extensions françaises de ligne absentes (reprise d'acompte, livraison à la ligne) | non implémenté |
| UBL-11 | 🟡 | BT-147/BT-148 (rabais et prix brut de l'article) non supportés | `UblWriter.php:983-1000` |
| UBL-12 | 🟡 | `schemeID` SIREN/SIRET possible mais jamais contrôlé ni positionné par défaut | `UblWriter.php:244-248` |
| UBL-13 | 🟡 | Aucune validation des listes de codes françaises | `InvoiceValidationTrait.php` |
| UBL-14 | ⚪ | `OrderReference/cbc:ID = 'NA'` quand seul BT-14 est présent (convention Peppol) — impact PPF à confirmer | `UblWriter.php:288-292` |

### Deux constats critiques détaillés

**UBL-01 — BT-8, l'option TVA sur les débits.** Le champ `/cac:InvoicePeriod/cbc:DescriptionCode` porte, en France, la mention de l'option pour le paiement de la taxe d'après les débits (règle G1.43). C'est une mention fiscale obligatoire pour tout prestataire de services ayant exercé cette option. Le modèle ne dispose que de `taxPointDate` (BT-7), le writer n'écrit que les dates de début et de fin de période, et le reader ne lit jamais `DescriptionCode`. Le champ appartient pourtant aux profils Base **et** Full.

**UBL-02 — Les avoirs autofacturés partent dans le mauvais document.** La méthode `isCreditNoteProfile()` ne reconnaît que les types 81, 83, 381, 396 et 532. Or la règle G1.01 autorise également les types 261 (avoir autofacturé), 502 (avoir autofacturé affacturé) et 503 (avoir de facture d'acompte) — qui ne sont même pas déclarés comme constantes dans `Invoice.php`. Appeler `setType(261)` produit un document `<Invoice>` portant un `cbc:InvoiceTypeCode` d'avoir : rejet garanti. Le cas est aggravé en aller-retour, puisqu'un `/CreditNote` correctement lu est ré-exporté en `/Invoice`.

---

## 5. Support CII — le point noir

**Ce qui fonctionne bien.** Le squelette est correct : les quatre namespaces (`rsm`, `ram`, `udt`, `qdt`) sont justes, l'ordre des quatre blocs de `SupplyChainTradeTransaction` respecte la spécificité CII qui place les lignes avant l'en-tête, l'attribut `@format="102"` accompagne toutes les dates, `@currencyID` est présent sur `TaxTotalAmount`, `schemeID="VA"` sur les enregistrements de TVA, et le `qdt:DateTimeString` du BT-26 est correctement distingué de son homologue `udt:`. Le bloc `InvoiceReferencedDocument` des avoirs (BT-25/BT-26) est écrit au bon endroit.

**Une clarification importante : la librairie ne fait pas de Factur-X.** Elle produit et lit le XML CII, mais ne comporte aucune brique PDF/A-3 — ni génération, ni extraction, aucune dépendance PDF. Le profil par défaut est `urn:cen.eu:en16931:2017` ; aucun profil `urn:factur-x.eu:1p0:*` n'est reconnu. Toute promesse Factur-X nécessite un composant externe.

### Constats

| # | Sév. | Constat | Emplacement |
|---|---|---|---|
| CII-01 | 🔴 | **Remises et charges d'en-tête appliquées deux fois** dans la ventilation TVA et les totaux | `CiiWriter.php:402-446, 519-595` |
| CII-02 | 🔴 | **Prix unitaire divisé par la base quantity alors que `BasisQuantity` est aussi émis** | `CiiWriter.php:144-156` |
| CII-03 | 🔴 | **Remise d'en-tête en % éclatée par taux, puis relue N fois sur la base totale** | `CiiWriter.php:551-567`, `CiiReader.php:332-335` |
| CII-04 | 🔴 | **Facture en catégorie O : aucune ventilation TVA émise** (BG-23 est 1..n) et `RateApplicablePercent` vide | `CiiWriter.php:347-349, 258-264` |
| CII-05 | 🔴 | **Export impossible sans SIREN `0002`** — cause de BLK-01 | `CiiWriter.php:806-825` |
| CII-06 | 🟠 | Ordre XSD violé dans `ApplicableHeaderTradeSettlement` (3 violations) | `CiiWriter.php:319-331` |
| CII-07 | 🟠 | Ordre XSD violé dans `MonetarySummation` : remise avant charge, arrondi mal placé | `CiiWriter.php:748-779` |
| CII-08 | 🟠 | Ordre XSD violé dans `ApplicableHeaderTradeAgreement` : BT-14 doit précéder BT-13 | `CiiWriter.php:291-298` |
| CII-09 | 🟠 | Ordre XSD violé dans les blocs de ligne (BT-132, période, BT-133) | `CiiWriter.php:157-207` |
| CII-10 | 🟠 | **BT-25/BT-26 jamais relus** : un avoir réimporté perd sa référence obligatoire | `CiiReader.php` |
| CII-11 | 🟠 | BT-23 optionnel à l'écriture alors qu'il est 1..1, et écrasé à la lecture par le preset | `CiiWriter.php:74-77`, `CiiReader.php:37-51` |
| CII-12 | 🟠 | **Date de livraison inventée** : égale à la date d'émission quand aucune livraison n'est saisie | `CiiWriter.php:305-314` |
| CII-13 | 🟠 | BT-111 (TVA en devise de comptabilisation) jamais émis ni lu | `CiiWriter.php:736-782` |
| CII-14 | 🟠 | BT-7 lu dans le mauvais élément (celui du taux de change) ; BT-8 absent | `CiiReader.php:191-194` |
| CII-15 | 🟡 | BT-147/BT-148 non supportés : le prix brut est toujours égal au prix net | `CiiWriter.php:147-148` |
| CII-16 | 🟡 | Plusieurs comptes bancaires regroupés sous un `PaymentMeans` unique (0..1 au schéma) | `CiiWriter.php:713-732` |
| CII-17 | 🟡 | Nom commercial et contacts lus mais jamais écrits | `CiiReader.php:245-292` |
| CII-18 | 🟡 | Période de facturation d'en-tête (BG-14) ni écrite ni lue | — |
| CII-19 | 🟡 | Motifs d'exonération (BT-120/BT-121) ni écrits ni lus en CII, alors que l'UBL les gère | — |
| CII-20 | 🟡 | Pièces jointes (BG-24) absentes du CII | — |
| CII-21 | 🟡 | Robustesse : date malformée en entrée provoque une erreur fatale | `CiiReader.php:218-226` |
| CII-22 | 🟡 | Code mort : `addHeaderTradeTax()` n'est jamais appelée | `CiiWriter.php:507-517` |
| CII-23 | ⚪ | Extensions françaises du profil Full absentes (reprise d'acompte en ligne, etc.) | — |

### Les défauts de calcul, reproduits

**CII-01 — Les remises d'en-tête comptent double.** `InvoiceTotals::fromInvoice()` intègre déjà les remises et charges d'en-tête dans la ventilation de TVA. `CiiWriter::computeVatBreakdownAfterHeaderAdjustments()` les réapplique une seconde fois sur cette ventilation déjà ajustée. Sur une facture d'une ligne à 100 € avec une remise d'en-tête de 10 % :

| Champ | Attendu | Émis |
|---|---|---|
| Montant de la remise | 10,00 | **9,00** |
| Base imposable (ventilation) | 90,00 | **81,00** |
| TVA calculée | 18,00 | **16,20** |
| `TaxBasisTotalAmount` | 90,00 | **91,00** ← incohérent avec la ventilation |
| Total TTC | 108,00 | **107,20** |

L'incohérence entre `TaxBasisTotalAmount` (91) et la somme des bases de la ventilation (81) déclenche `BR-S-08` chez n'importe quel validateur EN 16931.

**CII-02 — Le prix unitaire est divisé deux fois.** Le writer calcule `prixNet = prix / baseQuantity`, puis émet également `BasisQuantity = baseQuantity`. Or BT-146 est par définition le prix **pour** BT-149 unités : la division est en trop. Un aller-retour sur un prix de 100 € pour 2 unités ressort à 50 €. Deux défauts s'y ajoutent : `max(1.0, baseQty)` écrase toute base inférieure à 1 (0,5 est pourtant légal), et `BasisQuantity` est formaté avec le formateur monétaire à 2 décimales alors qu'il s'agit d'une quantité.

**CII-03 — Une remise en pourcentage se multiplie à la relecture.** Le writer émet un bloc de remise par taux de TVA, chacun portant le même pourcentage ; le reader relit chaque occurrence comme une remise appliquée sur la base totale, en ignorant le `BasisAmount`. Sur une facture de deux lignes à 100 € (une à 20 %, une à 5,5 %) avec 10 % de remise d'en-tête, le montant de remise passe de 20 € à **40 €** après un aller-retour.

**CII-04 — Les factures hors champ de TVA sont invalides.** Les lignes dont le taux est `null` — catégorie O, typiquement une facture d'auto-entrepreneur relevant de l'article 293 B — sont ignorées par le calcul de la ventilation. Résultat : **aucun bloc `ram:ApplicableTradeTax`** en en-tête, alors que BG-23 est de cardinalité 1..n et que le XSD PPF exige `ApplicableTradeTax[1..unbounded]`. En prime, la ligne porte un `<ram:RateApplicablePercent/>` vide, invalide pour un type numérique.

---

## 6. Support CDAR (Flux 6 / cycle de vie)

**Ce qui fonctionne bien.** L'architecture est propre et bien découpée, writer et reader se répondent, et trois guides de documentation accompagnent le module. Surtout, un CDAR complet généré par le writer **valide contre le XSD officiel UN/CEFACT D22B** : namespaces, ordre des enfants et types corrects. Les formats de date sont conformes à l'Annexe 2 (`@format=204` là où elle l'exige, `102` pour `ValueDateTime`). Les 14 statuts de facture 200 à 213 sont couverts, dont les quatre statuts obligatoires de la règle G7.44 : Déposée (200), Refusée (210), Encaissée (212) et Rejetée (213). Les corrections récentes sur l'ordre des enfants et les types de date sont bien en place.

### Constats

| # | Sév. | Constat | Emplacement |
|---|---|---|---|
| CDAR-01 | 🟠 | **Le reader ignore les `IncludedNote`** : motifs détaillés perdus à l'import | `CdarReader.php:158-199` |
| CDAR-02 | 🟠 | **Attributs absents relus comme chaîne vide** → réémission de `schemeID=""` | `CdarReader.php:232-281` |
| CDAR-03 | 🟠 | Un seul bloc `AcknowledgementDocument` supporté alors que la spec est **1..n** | `CrossDomain…php:15`, `CdarReader.php:77` |
| CDAR-04 | 🟠 | `DocumentTypeCode` désaligné : codes G1.01 manquants, codes interdits présents | `Cdar/Enums/DocumentTypeCode.php` |
| CDAR-05 | 🟠 | Statuts non-facture absents (recevabilité de flux, rejet de CDV…) | `Cdar/Enums/ProcessConditionCode.php` |
| CDAR-06 | 🟠 | **Aucune validation des exigences PPF** : pas d'équivalent CDAR à `InvoiceValidationTrait` | — |
| CDAR-07 | 🟠 | Modèle de note inapte au détail d'anomalies multiples (un seul code partagé, pas de `SubjectCode`) | `SpecifiedDocumentStatus.php:19-21` |
| CDAR-08 | 🟡 | Libellé de statut écrasé par le writer et ignoré par le reader | `CdarWriter.php:143-148`, `CdarReader.php:130-147` |
| CDAR-09 | 🟡 | Champs de l'Annexe 2 absents du modèle (indicateur de test, identifiants multiples, contact, adresse…) | divers |
| CDAR-10 | 🟡 | `CdarStatusMap` incohérent avec l'énumération : trois statuts sans correspondance | `Cdar/Mapping/CdarStatusMap.php:18-29` |
| CDAR-11 | 🟡 | Robustesse : date malformée provoque une erreur fatale | `CdarReader.php:279-290` |
| CDAR-12 | ⚪ | Champs informatifs émis automatiquement (sans danger, volume inutile) | `ReferenceReferencedDocument.php:270-281` |
| CDAR-13 | ⚪ | **À confirmer** : deux valeurs exigées par l'Annexe 2 sont refusées par le XSD UN/CEFACT brut | `CdarWriter.php:131-138` |

**CDAR-01 et CDAR-02, les deux fuites de données.** Le writer sait émettre les notes détaillées d'un refus ou d'un rejet — code de la règle en erreur, commentaire obligatoire, nom du fichier ou de la balise fautive — mais le reader ne les lit jamais. Une plateforme qui importe un CDV « Refusée » perd donc l'intégralité du motif détaillé, et un aller-retour lecture/réécriture les supprime silencieusement. Séparément, le reader utilise `DOMElement::getAttribute()`, qui retourne une chaîne vide et jamais `null` : les gardes `!== null` sont toujours vraies, et un identifiant relu sans `schemeID` est réémis avec `schemeID=""` — que le PPF rejettera.

**CDAR-13, le point à trancher.** Deux valeurs que l'Annexe 2 impose (le `ReferenceTypeCode` sous forme d'URN et le format de date `204`) sont refusées par le XSD UN/CEFACT non modifié. Le writer suit l'Annexe 2, ce qui est le bon arbitrage, mais le paquet de spécifications v3.2 disponible localement **ne contient pas de XSD pour le Flux 6** : il faut récupérer celui publié par le PPF pour confirmer.

---

## 7. Modèle métier et moteur de validation

**Ce qui fonctionne bien.** Le noyau EN 16931 est largement couvert : références (BT-10, BT-11, BT-17), notes avec code sujet, factures antérieures (BG-3), périodes de facturation et de ligne, pièces jointes, attributs d'article. La classe `Party` est riche (identifiants, TVA, informations légales, contact). Les formules de calcul des totaux sont **structurellement conformes** aux règles `BR-CO-10` à `BR-CO-17`, la TVA étant calculée par catégorie plutôt que ligne à ligne — le choix recommandé par la norme. La matrice d'arrondi paramétrable par champ et le mécanisme de presets constituent exactement la bonne architecture pour accueillir un CIUS français.

### Constats

| # | Sév. | Constat | Emplacement |
|---|---|---|---|
| MOD-01 | 🔴 | **Moteur de validation très partiel** : aucune règle `BR-CO-*`, `BR-S/E/AE/*`, `BR-DEC-*`, `BR-CL-*` | `InvoiceValidationTrait.php:45-231` |
| MOD-02 | 🔴 | **Aucune règle française PPF (`G x.xx`) nulle part** dans le code | — |
| MOD-03 | 🟠 | `BR-CO-10` : somme calculée en pleine précision, lignes écrites arrondies → écart au centime | `InvoiceTotals.php:101-106` |
| MOD-04 | 🟠 | Taux de TVA `null` en catégorie S : **TVA silencieusement à zéro**, aucune alerte | `VatTrait.php:5-6`, `InvoiceTotals.php:127` |
| MOD-05 | 🟠 | Hors preset, arrondi par défaut à **8 décimales** (la norme impose 2) | `Invoice.php:26` |
| MOD-06 | 🟠 | BG-11 (représentant fiscal / assujetti unique) absent du modèle | — |
| MOD-07 | 🟠 | BT-147/BT-148 (rabais et prix brut) absents : le prix brut émis est faux | `InvoiceLine.php:23` |
| MOD-08 | 🟠 | Aucun contrôle du type de facture ; constantes désalignées avec G1.01 | `Invoice.php:33-242, 414-417` |
| MOD-09 | 🟠 | Cadre de facturation (B1/S1/M1…) sans API dédiée ni contrôle | `Invoice.php:247` |
| MOD-10 | 🟠 | BT-8 absent du modèle (voir UBL-01) | `Invoice.php:254` |
| MOD-11 | 🟡 | BT-15, BT-16, BT-18 absents — or le motif de refus « référence contractuelle absente » les cite | — |
| MOD-12 | 🟡 | BT-93/BT-100 (base de calcul des remises d'en-tête) non modélisables | `AllowanceOrCharge.php:7-10` |
| MOD-13 | 🟡 | Clés de matrice d'arrondi incohérentes dans le calcul des totaux | `InvoiceTotals.php:110-126` |
| MOD-14 | 🟡 | `BR-49` court-circuitée dès que des conditions de paiement sont présentes | `InvoiceValidationTrait.php:170-177` |
| MOD-15 | 🟡 | Motif d'exonération : « le dernier gagne » silencieusement si deux lignes divergent | `InvoiceTotals.php:178-186` |
| MOD-16 | 🟡 | BT-90 (identifiant créancier SEPA) absent : mandat de prélèvement incomplet | `Payments/Mandate.php` |

### L'état réel du moteur de validation

| Famille de règles | Applicables au Flux 1 | Implémentées |
|---|---|---|
| `BR-1` à `BR-66` (obligations) | 59 | **27** |
| `BR-CO-*` (cohérence arithmétique) | 24 | **0** |
| `BR-S/Z/E/AE/IC/G/O-*` (TVA par catégorie) | ~76 | **0** |
| `BR-DEC-*` (décimales) | 21 | **0** |
| `BR-CL-*` (listes de codes) | 23 | **0** |
| **Règles françaises `G x.xx` / `P1.x` / `S1.x`** | ~60 | **0** |

Les formules `BR-CO-10` à `BR-CO-17` sont *calculées* par `InvoiceTotals`, mais jamais *vérifiées* : si une valeur incohérente est fournie en entrée, rien ne la signale.

**Le scénario type.** Une facture comportant une ligne en catégorie E sans motif d'exonération passe `validate()` sans erreur. Le PPF la rejette (règle `BR-E-10`, doublée de G1.41). Comme le rejet intervient **après** émission, la librairie ne peut pas s'en remettre au schematron aval : c'est précisément à ce niveau qu'elle doit filtrer.

---

## 8. Qualité logicielle, sécurité et outillage

### Architecture

Le fork est essentiellement **additif** — CII, CDAR et Flux 10 sont de nouveaux fichiers — ce qui limite le risque lors des remontées d'upstream. Seuls `UblReader`, `UblWriter`, `Invoice` et `DocumentNote` ont été modifiés.

Le problème structurel principal est que **`CiiWriter` a été développé en silo** : au lieu de réutiliser `InvoiceTotals::fromInvoice()` et `Invoice::round()` comme le fait `UblWriter`, il réimplémente intégralement la ventilation de TVA et les totaux, avec des `round($x, 2)` codés en dur et un formateur monétaire maison. Un commentaire du code admet reproduire « EXACTEMENT la logique de… » une autre méthode du même fichier. Conséquences concrètes : une même facture avec un preset personnalisant les décimales produit **des montants différents en UBL et en CII**, et toute correction de calcul doit être faite deux fois. C'est aussi la racine du défaut CII-01.

### Sécurité

Le point le plus sensible est le parsing de XML externe. Les trois readers passent le document brut à `UXML::fromString()`, qui appelle `loadXML()` **sans aucun flag libxml**. La bonne nouvelle : PHP ≥ 8.1 désactive le chargement d'entités externes par défaut, donc l'attaque XXE classique — exfiltration de fichier, requête sortante — est neutralisée, et libxml borne l'expansion d'entités internes. Une défense en profondeur reste souhaitable (rejet explicite des `DOCTYPE`, `LIBXML_NONET`), d'autant que la dépendance ne la fournit pas.

Aucun risque de traversée de répertoire sur les pièces jointes : le nom de fichier et l'URL sont stockés tels quels et jamais utilisés pour écrire sur disque. Le décodage base64 n'est en revanche pas strict (données corrompues acceptées silencieusement) et aucune limite de taille n'est appliquée.

Le vrai problème de robustesse est ailleurs : `DateTime::createFromFormat()` retourne `false` sur une valeur malformée, et les trois readers enchaînent directement sur `->setTime()`. **Un XML mal formé fait donc planter le processus** par erreur fatale, au lieu de lever l'exception documentée.

### Constats

| # | Sév. | Constat | Emplacement |
|---|---|---|---|
| QUA-01 | 🟠 | Duplication du moteur de calcul entre `CiiWriter` et le modèle | `CiiWriter.php:402-505, 744-782` |
| QUA-02 | 🟠 | BT-23 perdu à l'import CII dès qu'un preset est reconnu | `CiiReader.php:37-51` |
| QUA-03 | 🟠 | Parsing de dates non défensif : erreur fatale sur entrée malformée | 3 readers |
| QUA-04 | 🟠 | `CiiWriter` lève une `\Exception` racine, non déclarée : pas de type d'erreur exploitable | `CiiWriter.php:824` |
| QUA-05 | 🟡 | Aucune défense explicite contre les `DOCTYPE` en entrée | 3 readers |
| QUA-06 | 🟡 | Décodage base64 non strict, aucune limite de taille sur les pièces jointes | `UblReader.php:885` |
| QUA-07 | 🟡 | Aucun `declare(strict_types=1)` (0 fichier sur 67) ; deux styles de typage cohabitent | global |
| QUA-08 | 🟡 | CDAR hors hiérarchie d'abstraction ; `AbstractMultiWriter` force un contrat inadapté | `Readers/`, `Writers/` |
| QUA-09 | 🟡 | Formateur monétaire appliqué à des quantités et des pourcentages | `CiiWriter.php:153, 227` |
| QUA-10 | 🟡 | Hygiène : `.DS_Store` versionné, `composer.json` toujours au nom de l'upstream | racine |
| QUA-11 | ⚪ | `CiiReader` ne lit qu'un seul moyen de paiement là où le modèle en supporte plusieurs | `CiiReader.php:140-160` |

Le point QUA-10 mérite une attention particulière : le paquet conserve `"name": "josemmo/einvoicing"` et l'auteur d'origine. Selon la configuration des dépôts Composer, **un `composer update` peut silencieusement remplacer le fork par l'upstream**.

### Couverture de tests

| Module | Tests | Évaluation |
|---|---|---|
| Traits | 18 | Bonne (héritée de l'upstream) |
| Modèle racine | 17 | Correcte sur `Invoice`, mince ailleurs |
| Writers (UBL 6, CII 6, CDAR 3, Flux10 2) | 17 | **Faible** : `CiiWriter` fait 875 lignes pour 6 tests |
| Readers (UBL 4, CII 4, CDAR 2) | 10 | **Faible** : `UblReader` fait 902 lignes pour 4 tests |
| Intégration | 7 | Bons aller-retours sur fixtures Peppol |
| `Models`, `Payments` | 5 | Mince |

Les angles morts sont cohérents avec les défauts trouvés :

- **Aucune validation XSD automatisée**, alors que les XSD PPF sont disponibles localement — toutes les violations d'ordre CII passent donc inaperçues. Les deux seuls tests de validité UBL dépendent d'un **validateur distant** et sont ignorés hors ligne.
- **Aucune fixture française** : les jeux d'essai sont exclusivement Peppol.
- **Aucun test de montants en aller-retour** pour CII : le seul test existant vérifie des chaînes de caractères, ce qui laisse passer les défauts CII-02 et CII-03.
- **Aucun test d'entrée malformée** (dates invalides, XML hostile).
- Un test du reader CDAR s'appuie sur une fixture **XSD-invalide** : il valide la tolérance du reader plutôt qu'un cas réel.
- Non testés du tout : les 7 presets, `Delivery`, `InvoiceReference`, `Attribute`, `Identifier`, `CdarStatusMap`, et 4 traits.

Un test du reader CII fige par ailleurs le mauvais mapping de BT-7 (constat CII-14) : **le bug est verrouillé par son propre test**.

---

## 9. Ce qu'il manque pour la conformité française

Le mécanisme de presets (`AbstractPreset`) expose exactement les trois points d'extension nécessaires : identifiant de spécification, règles additionnelles, et configuration initiale de la facture. Créer un preset `CiusFr` est donc un travail de remplissage, pas de refonte.

**Configuration initiale** — arrondi à 2 décimales (ce qui corrige d'emblée MOD-05 et UBL-08), devise EUR, cadre de facturation par défaut.

**Règles à implémenter**, par ordre de valeur :

| Règle | Contrôle |
|---|---|
| G1.01 | Liste blanche des types de facture autorisés (et ajout des constantes manquantes) |
| G1.02 / G1.60 | Liste blanche du cadre de facturation (B1, S1, M1…) |
| G1.05 | Format du numéro de facture (35 caractères, jeu de caractères) |
| G1.24 | Liste blanche des taux de TVA français |
| G1.31 / G1.32 | Référence à la facture antérieure obligatoire pour les avoirs et rectificatives |
| G1.41 | Code **et** libellé du motif d'exonération obligatoires |
| G2.31 | Catégories de TVA restreintes à {S, E, AE, K, G, O, Z} |
| G2.32 | Rejet des factures intégralement en catégorie O ou en exonération CGI 261 |
| G1.53 | Cohérence des bases et des TVA, tolérance d'un centime |
| G1.63 / G1.89 | SIREN obligatoire avec son `schemeID`, format à 9 chiffres |

**Prérequis bloquants sur le modèle** : BG-11 (représentant fiscal), BT-8 (exigibilité), BT-147/BT-148 (prix brut et rabais), BT-90 (créancier SEPA), BT-16/BT-18, et une API dédiée au cadre de facturation.

**Prérequis sur le moteur** : les règles `BR-CO-*`, `BR-S/E/AE/K/G/O-*` et `BR-DEC-*` sont **génériques EN 16931, pas françaises** — elles manquent aujourd'hui et devraient rejoindre le jeu de règles par défaut, pas le preset.

---

## 10. Plan de remédiation

### Phase 1 — Remettre le dépôt au vert (quelques heures)

1. Corriger `CiiWriter::addLegalOrganization` : utiliser l'identifiant `0002` déjà trouvé par la boucle, et rendre le bloc optionnel plutôt que fatal → BLK-01, CII-05
2. Corriger l'erreur Phan de `Flux10Writer.php:117` → BLK-02
3. Embarquer les XSD dans `tests/`, ou marquer les tests comme ignorés quand ils sont absents → suite auto-portante
4. Retirer `.DS_Store`, renommer le paquet dans `composer.json` → QUA-10

### Phase 2 — Rendre le module CII exploitable (le chantier principal)

5. **Refactorer `CiiWriter` sur `InvoiceTotals` et `Invoice::round()`** — supprime la duplication et corrige CII-01 par construction
6. Corriger le traitement de la base quantity et le format des quantités → CII-02
7. Émettre `BasisAmount` sur les remises d'en-tête et le respecter à la lecture → CII-03
8. Traiter la catégorie O dans la ventilation, ne pas émettre de taux vide → CII-04
9. Réordonner les quatre blocs signalés selon le XSD → CII-06 à CII-09
10. **Ajouter une validation XSD automatisée** contre les schémas PPF, en test — c'est le filet qui aurait attrapé les points 9

### Phase 3 — Conformité française

11. Créer le preset `CiusFr` avec l'arrondi à 2 décimales et les règles listées en section 9
12. Ajouter au modèle BT-8, BG-11, BT-147/BT-148, BT-90
13. Corriger le traitement des avoirs 261/502/503 en UBL → UBL-02, UBL-04, UBL-05, UBL-06
14. Compléter le moteur de validation : `BR-CO-*`, `BR-S/E/AE/*`, `BR-DEC-*`

### Phase 4 — Fiabilité et symétrie

15. Blinder le parsing des dates dans les trois readers, rejeter les `DOCTYPE` → QUA-03, QUA-05
16. Combler les asymétries de lecture : BT-25/BT-26 en CII, notes CDAR, attributs vides → CII-10, CDAR-01, CDAR-02
17. Supporter les blocs `AcknowledgementDocument` multiples et compléter les référentiels de codes CDAR → CDAR-03 à CDAR-05
18. Ajouter des tests d'aller-retour **sur les montants**, des fixtures françaises, et des cas d'entrée malformée
19. Récupérer le XSD Flux 6 publié par le PPF pour trancher CDAR-13

---

## 11. Conclusion

La librairie est **un bon socle sur lequel il reste un chantier réel**. Le support UBL est proche de l'objectif : quelques corrections ciblées sur les avoirs et un preset France suffiraient à en faire une base de production crédible pour le Flux 1. Le module CDAR est structurellement sain et valide contre le schéma officiel ; ses défauts sont des complétions, pas des refontes.

Le module CII, en revanche, demande un vrai travail avant tout usage réel. Ses défauts ne sont pas cosmétiques : ce sont des **montants faux**, reproductibles sur des cas de gestion courants (une remise commerciale en pied de facture, un prix au conditionnement, une facture d'auto-entrepreneur). Et comme c'est la syntaxe qui porte Factur-X, c'est aussi celle sur laquelle le volume français se concentrera.

Deux investissements transverses conditionnent le reste. Le premier est la **validation XSD automatisée en test** : les schémas PPF sont disponibles localement, et leur absence explique à elle seule la majorité des violations d'ordre passées inaperçues. Le second est le **preset France**, qui n'est pas un raffinement optionnel mais le point d'ancrage de toute la conformité réglementaire — et dont l'absence laisse aujourd'hui, faute de mieux, un arrondi par défaut à huit décimales.
